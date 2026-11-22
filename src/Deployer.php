<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use RuntimeException;
use Throwable;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Backup\SnapshotStore;
use Rush\Tool\Code\Deploy\BuildPlan;
use Rush\Tool\Code\Event\EventWriter;
use Rush\Support\Operator;
use Rush\Tool\Code\Preflight\Preflight;
use Rush\Tool\Code\Requirements\WebProbe;
use Rush\Tool\Code\Release\ArtifactVerifier;
use Rush\Tool\Code\Release\ReleaseHasher;
use Rush\Tool\Code\Release\ReleaseId;
use Rush\Tool\Code\Release\ReleaseManifest;
use Rush\Tool\Code\Release\ReleasePublisher;
use Rush\Tool\Code\Release\ReleaseStore;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Remote\Scp;
use Rush\Support\FileSystem;
use Rush\Tool\Code\Transport\Transfer;
use Rush\Tool\Code\Verify\Verifier;


class Deployer
{
    private readonly Ssh $ssh;

    private readonly Maintenance $maintenance;

    private readonly Scp $scp;

    private readonly RemoteFiles $remote;

    private readonly SnapshotStore $snapshots;

    private readonly Transfer $transfer;

    private readonly Preflight $preflight;

    private readonly ReleasePublisher $publisher;

    private readonly Verifier $verifier;

    private readonly EventWriter $events;

    private readonly Layout $layout;

    private bool $hasRsync = false;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly OutputInterface $output,
        private readonly Operator $operator,
        ?Layout $layout = null,
    ) {
        $this->layout = $layout ?? Layout::fromProject($projectRoot);
        $this->ssh = new Ssh($shell);
        $this->maintenance = new Maintenance($this->ssh, $this->layout);
        $this->scp = new Scp($target, $shell);
        $this->remote = new RemoteFiles($target, $this->ssh);
        $this->snapshots = new SnapshotStore($target, $this->ssh, $this->layout);
        $this->transfer = new Transfer($projectRoot, $target, $shell, $this->ssh, $this->scp, $output, $this->layout);
        $this->preflight = new Preflight($projectRoot, $target, $shell, $this->ssh, $output, $this->layout);
        $this->publisher = new ReleasePublisher($projectRoot, $target, $shell, $this->ssh, $this->scp, $this->remote, $output, $this->layout);
        $this->verifier = new Verifier($target, $shell, $output);
        $this->events = new EventWriter($target, $operator, $output, $projectRoot, $shell);
    }


    public function check(): void
    {
        $this->hasRsync = $this->preflight->check();
    }


    public function push(): void
    {
        $build = null;
        $releaseArchive = null;
        $started = false;
        $snapshot = null;

        try
        {
            $this->check();
            $this->preflight->assertEnvExists();

            $current = $this->remote->readCurrent() ?? [];
            $wasRedirected = ($current['status'] ?? null) === 'redirected';
            $contentHash = (new ReleaseHasher())->hash($this->projectRoot);
            $releaseId = ReleaseId::fromHash($contentHash);
            $version = $this->releaseVersion();
            $previousReleaseId = $this->remote->currentReleaseId();

            $build = $this->buildVendor();
            $vendor = $build['vendor'] ?? null;
            $releaseArchive = $this->publisher->createArchive($vendor);
            $artifactHash = (new ArtifactVerifier())->hash($releaseArchive);

            $manifest = new ReleaseManifest(
                $releaseId,
                $contentHash,
                $version['version'],
                $version['source'],
                gmdate('c'),
                $version['git'],
                $previousReleaseId,
                $this->operator->toArray(),
                $artifactHash,
                $this->layout->fingerprint(),
            );

            $store = new ReleaseStore($this->projectRoot);

            $this->out('enabling maintenance');
            $this->maintenance->enable($this->target);
            $started = true;

            $this->transfer->ensureRemoteDirs();

            $snapshot = $this->snapshots->create($manifest->toArray(), $previousReleaseId);
            $this->out('backup: '. $snapshot);

            if ($wasRedirected) {
                $this->unretire($current);
            }

            $this->transfer->run($vendor, $this->hasRsync);

            $this->out('disabling maintenance');
            $this->maintenance->disable($this->target);

            $this->verifier->verify();

            $store->persist($manifest);
            $artifactVerified = $this->publisher->upload($manifest, $releaseArchive);
            $this->remote->writeCurrent($manifest, $snapshot, 'current');
            $this->events->write('push', $manifest->releaseId, null, $this->target->name, $snapshot, [
                'artifactVerified' => $artifactVerified,
                'unretired' => $wasRedirected,
            ]);

            $this->snapshots->pruneBackups();
            $this->snapshots->pruneReleases($manifest->releaseId);
            $this->events->prune($this->target->eventKeep);
            $this->out('release: '. $manifest->releaseId .' ('. $manifest->version .')');
            $this->out('deploy complete');
        }
        catch (Throwable $error)
        {
            if ($started)
            {
                $this->out('error: '. $error->getMessage());

                if ($snapshot === null)
                {
                    $this->out('no backup: maintenance remains active');
                }
                else
                {
                    try
                    {
                        $this->maintenance->enable($this->target);
                        $this->snapshots->restore($snapshot);
                        $this->transfer->applyOverrides($this->hasRsync);
                        $this->maintenance->disable($this->target);
                        $this->verifier->verify();
                        $this->out('rollback complete');
                    }
                    catch (Throwable $rollback)
                    {
                        $this->out('ROLLBACK FAILED, maintenance remains active: '. $rollback->getMessage());
                    }
                }
            }

            throw $error;
        }
        finally
        {
            if ($releaseArchive !== null && ! FileSystem::tryDelete($releaseArchive)) {
                $this->out('warning: could not remove temporary release archive');
            }

            if ($build !== null) {
                try {
                    FileSystem::removeDir($build['dir']);
                } catch (Throwable $cleanup) {
                    $this->out('warning: could not remove build directory: '. $cleanup->getMessage());
                }
            }
        }
    }


    /**
     * @return list<string>
     */
    public function backups(): array
    {
        return $this->snapshots->backups();
    }


    public function rollback(?string $timestamp): void
    {
        $this->check();
        $this->preflight->assertEnvExists();

        $backups = $this->snapshots->backups();

        if ($timestamp === null)
        {
            if ($backups === [])
            {
                throw new RuntimeException('no backup found');
            }

            $timestamp = $backups[0];
            $this->out('latest backup: '. $timestamp);
        }

        if (preg_match('/\A[0-9]{8}-[0-9]{6}\z/', $timestamp) !== 1)
        {
            throw new RuntimeException("invalid backup timestamp: {$timestamp}");
        }

        if ( ! in_array($timestamp, $backups, true))
        {
            throw new RuntimeException("backup not found: {$timestamp}");
        }

        $started = false;

        try
        {
            $this->maintenance->enable($this->target);
            $started = true;
            $restored = $this->snapshots->restore($timestamp);
            $previousReleaseId = $restored['previousReleaseId'] ?? null;

            if (is_string($previousReleaseId) && $previousReleaseId !== '') {
                $this->remote->writeCurrentReleaseId($previousReleaseId);
            } else {
                $this->remote->deleteCurrent();
            }

            $this->transfer->applyOverrides($this->hasRsync);
            $this->maintenance->disable($this->target);
            $this->verifier->verify();
            $rollbackRelease = $restored['previousReleaseId'] ?? $restored['releaseId'] ?? 'unknown';
            $this->events->write('rollback', is_string($rollbackRelease) ? $rollbackRelease : 'unknown', null, null, $timestamp);
            $this->out('rollback complete');
        }
        catch (Throwable $error)
        {
            if ($started)
            {
                try
                {
                    $this->maintenance->enable($this->target);
                }
                catch (Throwable)
                {
                }
            }

            throw $error;
        }
    }


    protected function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }


    /** @return null|array{dir: string, vendor: string} */
    private function buildVendor(): ?array
    {
        $plan = new BuildPlan($this->layout);

        if ( ! $plan->enabled()) {
            return null;
        }

        $directory = FileSystem::temporaryDirectory();

        try
        {
            foreach ($plan->include() as $include)
            {
                $source = $this->projectRoot .'/'. $include;

                if (is_file($source)) {
                    FileSystem::copyFile($source, $directory .'/'. $include);
                    continue;
                }

                if ( ! is_dir($source)) {
                    throw new RuntimeException("build include missing: {$include}");
                }

                FileSystem::copyDir(
                    $source,
                    $directory .'/'. $include,
                    $plan->excludesFor($include),
                );
            }

            foreach (['composer.json', 'composer.lock'] as $file)
            {
                if ( ! is_file($directory .'/'. $file)) {
                    throw new RuntimeException("missing build file: {$file}");
                }
            }

            $rawPlatform = $this->layout->build()['platform'];
            $remotePhp = $rawPlatform === 'remote' ? $this->remotePhp() : null;
            $platform = $plan->platform($remotePhp);
            $result = $this->shell->run(
                ['composer', 'config', 'platform.php', $platform],
                null,
                $directory,
            );

            if ( ! $result->ok()) {
                $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

                throw new RuntimeException("composer failed:\n". $detail);
            }

            $command = [
                'composer', 'install',
                ...$plan->flags(),
                '--no-interaction',
                '--no-progress',
            ];

            $this->out('build vendor: '. implode(' ', $command));

            $result = $this->shell->run($command, null, $directory);

            if ( ! $result->ok())
            {
                $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

                throw new RuntimeException("composer failed:\n". $detail);
            }

            $vendor = $directory .'/vendor';

            if ( ! is_dir($vendor))
            {
                throw new RuntimeException('composer did not generate the vendor directory');
            }

            return ['dir' => $directory, 'vendor' => $vendor];
        }
        catch (Throwable $error)
        {
            FileSystem::removeDir($directory);
            throw $error;
        }
    }


    private function remotePhp(): string
    {
        $probe = (new WebProbe())->inspect($this->target, $this->shell);

        return $probe['php'];
    }


    /** @param array<string, mixed> $current */
    private function unretire(array $current): void
    {
        $retirement = $current['retirement'] ?? null;
        $files = is_array($retirement) ? ($retirement['files'] ?? null) : null;

        if ( ! is_array($files) || $files === []) {
            return;
        }

        $commands = [];

        foreach ($files as $file) {
            if ( ! is_string($file) || $file === '' || $file === '.' || $file === '..'
                || str_contains($file, '/') || str_contains($file, '\\')) {
                continue;
            }

            $commands[] = 'rm -f '. Ssh::quote(rtrim($this->target->webroot, '/') .'/'. $file);
        }

        if ($commands !== []) {
            $this->out('reactivating retired target');
            $this->ssh->must($this->target, implode(' && ', $commands));
        }
    }


    /** @return array{version: string, source: string, git: null|array{commit: string, branch: string, dirty: bool}} */
    private function releaseVersion(): array
    {
        $versionFile = $this->projectRoot .'/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        $source = 'version';

        if ($version === '') {
            $env = getenv('DEPLOY_VERSION');
            $version = is_string($env) ? trim($env) : '';
            $source = 'env';
        }

        if ($version === '') {
            $version = '0.0.0';
            $source = 'none';
        }

        return ['version' => $version, 'source' => $source, 'git' => $this->gitInfo()];
    }


    /** @return null|array{commit: string, branch: string, dirty: bool} */
    private function gitInfo(): ?array
    {
        $commit = $this->shell->run(['git', '-C', $this->projectRoot, 'rev-parse', '--short', 'HEAD']);

        if ( ! $commit->ok() || trim($commit->stdout) === '') {
            return null;
        }

        $branch = $this->shell->run(['git', '-C', $this->projectRoot, 'rev-parse', '--abbrev-ref', 'HEAD']);
        $status = $this->shell->run(['git', '-C', $this->projectRoot, 'status', '--porcelain']);

        return [
            'commit' => trim($commit->stdout),
            'branch' => $branch->ok() ? trim($branch->stdout) : '',
            'dirty' => $status->ok() && trim($status->stdout) !== '',
        ];
    }
}
