<?php declare(strict_types=1);

namespace Rush\Tool\Code\Promotion;

use RuntimeException;
use Throwable;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Backup\SnapshotStore;
use Rush\Tool\Code\Event\EventWriter;
use Rush\Tool\Code\Lock\TargetLock;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Maintenance;
use Rush\Support\Operator;
use Rush\Tool\Code\Preflight\Preflight;
use Rush\Tool\Code\Release\ReleaseExporter;
use Rush\Tool\Code\Release\ReleaseManifest;
use Rush\Tool\Code\Release\ReleasePublisher;
use Rush\Tool\Code\Release\ReleaseStore;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Retirement\RedirectStub;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Target;
use Rush\Tool\Code\Transport\Transfer;
use Rush\Tool\Code\Verify\Verifier;


final class Promoter
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


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly OutputInterface $output,
        private readonly Operator $operator,
        private readonly Layout $layout,
    ) {
        $this->ssh = new Ssh($shell);
        $this->maintenance = new Maintenance($this->ssh, $layout);
        $this->scp = new Scp($target, $shell);
        $this->remote = new RemoteFiles($target, $this->ssh);
        $this->snapshots = new SnapshotStore($target, $this->ssh, $layout);
        $this->transfer = new Transfer($projectRoot, $target, $shell, $this->ssh, $this->scp, $output, $layout);
        $this->preflight = new Preflight($projectRoot, $target, $shell, $this->ssh, $this->remote, $output, $layout);
        $this->publisher = new ReleasePublisher($projectRoot, $target, $shell, $this->ssh, $this->scp, $this->remote, $output, $layout);
        $this->verifier = new Verifier($target, $shell, $output);
        $this->events = new EventWriter($target, $operator, $output, $projectRoot, $shell);
    }


    /** @return array{archive: string, manifest: ReleaseManifest, temporary: bool} */
    public function export(): array
    {
        return (new ReleaseExporter($this->target, $this->ssh, $this->scp, $this->remote))->export();
    }


    public function receive(string $archive, ReleaseManifest $manifest, string $promotedFrom, bool $forceLock = false): void
    {
        $lock = new TargetLock($this->target, $this->shell, $this->operator, 'promote');
        $previousOwner = $lock->acquire($forceLock);

        if ($previousOwner !== null) {
            $lockReleaseId = $this->remote->currentReleaseId() ?? 'lock';
            $this->events->write('lock.override', $lockReleaseId, null, $this->target->name, null, [
                'forcedLock' => true,
                'previousOwner' => $previousOwner,
            ]);
        }

        $started = false;
        $snapshot = null;
        $hasRsync = false;
        $remoteArchive = $this->target->stateDir() .'/incoming-release-'. bin2hex(random_bytes(4)) .'.tar.gz';

        try {
            $hasRsync = $this->preflight->check();
            $this->preflight->assertEnvExists();

            $previousReleaseId = $this->remote->currentReleaseId();
            $store = new ReleaseStore($this->projectRoot);

            $this->maintenance->enable($this->target);
            $started = true;

            $this->transfer->ensureRemoteDirs();
            $snapshot = $this->snapshots->create($manifest->toArray(), $previousReleaseId);
            $this->out('backup: '. $snapshot);

            $this->scp->upload($archive, $remoteArchive);
            $this->extractRelease($remoteArchive);
            $this->transfer->applyOverrides($hasRsync);

            $this->maintenance->disable($this->target);
            $this->verifier->verify();

            $store->persist($manifest);
            $artifactVerified = $this->publisher->upload($manifest, $archive);
            $this->remote->writeCurrent($manifest, $snapshot, 'current', $previousReleaseId, $promotedFrom);
            $this->events->write('promote', $manifest->releaseId, $promotedFrom, $this->target->name, $snapshot, [
                'artifactVerified' => $artifactVerified,
            ]);

            $this->snapshots->pruneBackups();
            $this->snapshots->pruneReleases($manifest->releaseId);
            $this->events->prune($this->target->eventKeep);
            $this->out('release: '. $manifest->releaseId .' ('. $manifest->version .')');
            $this->out('promote complete');
        }
        catch (Throwable $error) {
            if ($started) {
                $this->out('error: '. $error->getMessage());

                if ($snapshot === null) {
                    $this->out('no backup: maintenance remains active');
                } else {
                    try {
                        $this->maintenance->enable($this->target);
                        $this->snapshots->restore($snapshot);
                        $this->transfer->applyOverrides($hasRsync);
                        $this->maintenance->disable($this->target);
                        $this->verifier->verify();
                        $this->out('rollback complete');
                    } catch (Throwable $rollback) {
                        $this->out('ROLLBACK FAILED, maintenance remains active: '. $rollback->getMessage());
                    }
                }
            }

            throw $error;
        }
        finally {
            $lock->release();
        }
    }


    public function retire(string $redirectUrl, string $promotedTo): void
    {
        if ($this->target->nestedWebroots !== []) {
            throw new RuntimeException('cannot retire a target with nested webroots');
        }

        $current = $this->remote->readCurrent();

        if ($current === null) {
            throw new RuntimeException('source has no current release');
        }

        $status = $current['status'] ?? null;

        if ($status !== null && $status !== 'current') {
            throw new RuntimeException('source is not in current state');
        }

        $stub = (new RedirectStub($this->projectRoot))->render($redirectUrl);
        $started = false;
        $snapshot = null;

        try {
            $this->maintenance->enable($this->target);
            $started = true;
            $snapshot = $this->snapshots->create($current, null);

            $this->remote->writeFile($this->target->webroot .'/index.php', $stub['index']);
            $this->remote->writeFile($this->target->webroot .'/.htaccess', $stub['htaccess']);

            $this->maintenance->disable($this->target);
            $this->verifier->assertRedirect($this->target->url, $redirectUrl);

            $this->removeSourceDeployment();

            $this->remote->writeJson($this->target->stateDir() .'/current.json', [
                'releaseId' => $current['releaseId'] ?? null,
                'contentHash' => $current['contentHash'] ?? null,
                'version' => $current['version'] ?? null,
                'status' => 'redirected',
                'redirectTo' => $redirectUrl,
                'promotedTo' => $promotedTo,
                'promotedAt' => gmdate('c'),
            ]);


            $retireRelease = $current['releaseId'] ?? 'unknown';
            $this->events->write('retire', is_string($retireRelease) ? $retireRelease : 'unknown', $this->target->name, $promotedTo, $snapshot);

            $this->out('source retired: redirecting to '. $redirectUrl);
        }
        catch (Throwable $error) {
            if ($started && $snapshot !== null) {
                try {
                    $this->maintenance->enable($this->target);
                    $this->snapshots->restore($snapshot);
                    $this->transfer->applyOverrides(false);
                } catch (Throwable $rollback) {
                    $this->out('RETIRE ROLLBACK FAILED, maintenance remains active: '. $rollback->getMessage());
                }
            }

            throw $error;
        }
    }


    private function extractRelease(string $remoteArchive): void
    {
        $target = $this->target;
        $commands = [];

        if ($target->root !== null) {
            $commands[] = $this->rootDeleteCommand();
            $commands[] = 'rm -rf '. Ssh::quote($target->root .'/vendor');

            if ($this->layout->publishFor('root') !== []) {
                $commands[] = 'tar -xzf '. Ssh::quote($remoteArchive)
                    .' -C '. Ssh::quote($target->root)
                    .' --strip-components=1 root';
            }
        }

        $commands[] = $this->webrootDeleteCommand();

        if ($this->layout->publishFor('webroot') !== []) {
            $commands[] = 'tar -xzf '. Ssh::quote($remoteArchive)
                .' -C '. Ssh::quote($target->webroot)
                .' --strip-components=1 webroot';
        }

        $commands[] = 'rm -f '. Ssh::quote($remoteArchive);

        $this->ssh->must($target, implode(' && ', $commands));
    }


    private function removeSourceDeployment(): void
    {
        $target = $this->target;
        $commands = [];

        foreach ($this->layout->publishFor('root') as $entry) {
            $commands[] = 'rm -rf '. Ssh::quote($this->layout->resolve($entry['to'], $target));
        }

        if ($target->root !== null) {
            $commands[] = 'rm -rf '. Ssh::quote($target->root .'/vendor');
        }

        $commands[] = $this->webrootDeleteCommand(true);

        $this->ssh->must($target, implode(' && ', $commands));
    }


    private function rootDeleteCommand(): string
    {
        $commands = [];

        foreach ($this->layout->publishFor('root') as $entry) {
            $destination = $this->layout->resolve($entry['to'], $this->target);
            $source = rtrim($this->projectRoot, '/') .'/'. $entry['from'];

            if (is_dir($source)) {
                $commands[] = $this->deleteContents($destination, $this->rootPreserve($destination));
                continue;
            }

            $commands[] = 'rm -f '. Ssh::quote($destination);
        }

        return $commands === [] ? 'true' : implode(' && ', $commands);
    }


    private function webrootDeleteCommand(bool $preserveStub = false): string
    {
        $preserve = ['.deploy', '.maintenance', ...$this->target->nestedWebroots];

        if ($preserveStub) {
            $preserve[] = 'index.php';
            $preserve[] = '.htaccess';
        }

        foreach ([...$this->layout->preserve(), ...$this->target->preserve] as $pattern) {
            $name = explode('/', ltrim($pattern, '/'))[0];

            if ($name !== '') {
                $preserve[] = $name;
            }
        }

        return $this->deleteContents($this->target->webroot, $preserve);
    }


    /** @return list<string> */
    private function rootPreserve(string $destination): array
    {
        $preserve = [];

        if ($this->target->root === $destination) {
            $preserve[] = '.deploy';
        }

        foreach ([...$this->layout->writable(), ...array_map(
            static fn (array $env): string => $env['to'],
            $this->layout->env(),
        )] as $protected) {
            $path = $this->layout->resolve($protected, $this->target);

            if ($path === $destination) {
                continue;
            }

            if (str_starts_with($path .'/', rtrim($destination, '/') .'/')) {
                $relative = substr($path, strlen(rtrim($destination, '/')) + 1);
                $preserve[] = explode('/', $relative)[0];
            }
        }

        return array_values(array_unique($preserve));
    }


    /** @param list<string> $preserve */
    private function deleteContents(string $directory, array $preserve): string
    {
        $command = 'find '. Ssh::quote($directory) .' -mindepth 1 -maxdepth 1';

        foreach (array_values(array_unique($preserve)) as $name) {
            if ($name !== '') {
                $command .= ' ! -name '. Ssh::quote($name);
            }
        }

        return $command .' -exec rm -rf {} +';
    }


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
