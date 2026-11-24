<?php declare(strict_types=1);

namespace Rush\Tool\Code\Backup;

use Rush\Tool\Code\Exception\DeployException;
use JsonException;
use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Deploy\PathRules;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Support\Json;
use Rush\Tool\Code\Target;


final class SnapshotStore
{
    private readonly PathRules $rules;


    public function __construct(
        private readonly Target $target,
        private readonly Ssh $ssh,
        private readonly Layout $layout,
    ) {
        $this->rules = new PathRules($layout, $target);
    }


    /** @return list<string> */
    public function backups(): array
    {
        return $this->snapshots();
    }


    /** @param array<string, mixed> $release */
    public function create(array $release = [], ?string $previousReleaseId = null): string
    {
        $target = $this->target;
        $name = gmdate('Ymd-His');
        $directory = $this->directory() .'/'. $name;
        $commands = ['mkdir -p '. Ssh::quote($directory)];

        if ($target->root !== null) {
            $excludes = ['--exclude=.deploy'];
            $nested = $this->rules->relativeWebroot();

            if ($nested !== null) {
                $excludes[] = '--exclude='. Ssh::quote($nested);
            }

            $commands[] = 'tar -czf '. Ssh::quote($directory .'/root.tar.gz')
                .' '. implode(' ', $excludes)
                .' -C '. Ssh::quote($target->root) .' .';
        }

        $webrootExcludes = ['--exclude=.deploy', '--exclude=.maintenance'];

        foreach ($target->nestedWebroots as $entry) {
            $webrootExcludes[] = '--exclude='. Ssh::quote('./'. $entry);
        }

        $commands[] = 'tar -czf '. Ssh::quote($directory .'/webroot.tar.gz')
            .' '. implode(' ', $webrootExcludes)
            .' -C '. Ssh::quote($target->webroot) .' .';

        $this->ssh->must($target, implode(' && ', $commands));

        $manifest = Json::encode(array_merge($release, [
            'target' => $target->name,
            'root' => $target->root,
            'webroot' => $target->webroot,
            'nestedWebroots' => $target->nestedWebroots,
            'layout' => $this->layout->fingerprint(),
            'createdAt' => gmdate('c'),
            'previousReleaseId' => $previousReleaseId,
        ]), 'snapshot manifest');

        $this->ssh->must(
            $target,
            'cat > '. Ssh::quote($directory .'/manifest.json'),
            $manifest,
        );

        return $name;
    }


    /** @return array<string, mixed> */
    public function restore(string $timestamp): array
    {
        $target = $this->target;
        $directory = DeployPaths::backup($target, $timestamp);
        $manifest = $this->assertSnapshotLayout($directory);
        $commands = [];

        if ($target->root !== null) {
            $commands[] = $this->rules->deleteContents(
                $target->root,
                $this->rules->preserveInside($target->root),
            );
            $commands[] = 'tar -xzf '. Ssh::quote($directory .'/root.tar.gz')
                .' -C '. Ssh::quote($target->root);
        }

        $commands[] = $this->rules->deleteContents(
            $target->webroot,
            $this->rules->preserveInside($target->webroot, true),
        );
        $commands[] = 'tar -xzf '. Ssh::quote($directory .'/webroot.tar.gz')
            .' -C '. Ssh::quote($target->webroot);

        $this->ssh->must($target, implode(' && ', $commands));

        return $manifest;
    }


    /** @return list<string> */
    public function snapshots(): array
    {
        $output = $this->ssh->must(
            $this->target,
            'ls -1dt '. Ssh::quote($this->directory()) .'/*/ 2>/dev/null || true',
        )->stdout;

        $snapshots = [];

        foreach (explode("\n", $output) as $line)
        {
            $name = basename(rtrim(trim($line), '/'));

            if (preg_match('/\A[0-9]{8}-[0-9]{6}\z/', $name) === 1)
            {
                $snapshots[] = $name;
            }
        }

        return $snapshots;
    }


    public function pruneBackups(): void
    {
        $target = $this->target;

        $this->ssh->run(
            $target,
            'ls -1dt '. Ssh::quote($this->directory()) .'/*/ 2>/dev/null'
            .' | tail -n +'. ($target->backupKeep + 1)
            .' | xargs -r rm -rf',
        );
    }


    public function pruneReleases(string $currentReleaseId): void
    {
        $directory = DeployPaths::releases($this->target);
        $result = $this->ssh->run($this->target, 'ls -1dt '. Ssh::quote($directory) .'/*/ 2>/dev/null || true');
        $releases = [];

        foreach (explode("\n", trim($result->stdout)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $releases[] = basename(rtrim(trim($line), '/'));
        }

        $keep = max(1, $this->target->releaseKeep);
        $kept = [];

        foreach ($releases as $releaseId) {
            if ($releaseId === $currentReleaseId || count($kept) < $keep) {
                $kept[] = $releaseId;
            }
        }

        $remove = array_diff($releases, $kept);

        foreach ($remove as $releaseId) {
            $this->ssh->run(
                $this->target,
                'rm -rf '. Ssh::quote($directory .'/'. $releaseId),
            );
        }
    }


    private function directory(): string
    {
        return DeployPaths::backups($this->target);
    }


    /** @return array<string, mixed> */
    private function assertSnapshotLayout(string $directory): array
    {
        $result = $this->ssh->must(
            $this->target,
            'if [ -f '. Ssh::quote($directory .'/manifest.json') .' ]; '
            .'then cat '. Ssh::quote($directory .'/manifest.json') .'; else printf __MISSING__; fi',
        );

        if (trim($result->stdout) === '__MISSING__')
        {
            throw new DeployException('snapshot manifest not found');
        }

        try
        {
            $manifest = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $error)
        {
            throw new DeployException('invalid snapshot manifest: '. $error->getMessage(), 0, $error);
        }

        if ( ! is_array($manifest))
        {
            throw new DeployException('invalid snapshot manifest: expected object');
        }

        if (($manifest['root'] ?? null) !== $this->target->root
            || ($manifest['webroot'] ?? null) !== $this->target->webroot)
        {
            throw new DeployException('snapshot layout does not match the current target');
        }

        if (is_string($manifest['layout'] ?? null)
            && $manifest['layout'] !== $this->layout->fingerprint())
        {
            throw new DeployException('snapshot layout does not match the current code.layout.json');
        }

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }
}
