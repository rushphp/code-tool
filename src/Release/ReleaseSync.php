<?php declare(strict_types=1);

namespace Rush\Tool\Code\Release;

use Rush\Support\FileSystem;
use Rush\Tool\Code\Deploy\DeployPaths;
use Throwable;
use Rush\Tool\Code\Config;
use Rush\Tool\Code\Event\EventLog;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Target;


final class ReleaseSync
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly Shell $shell,
    ) {
    }


    /**
     * @return array<string, array{ok: bool, manifests: int, events: int, error: ?string}>
     */
    public function syncAll(Config $config): array
    {
        $results = [];

        foreach ($config->names() as $name) {
            $results[$name] = $this->syncTarget($config->target($name));
        }

        return $results;
    }


    /** @return array{ok: bool, manifests: int, events: int, error: ?string} */
    public function syncTarget(Target $target): array
    {
        $ssh = new Ssh($this->shell);
        $scp = new Scp($target, $this->shell);

        try {
            $manifests = $this->manifestIds($ssh, $target);
            $events = (new EventLog($this->projectRoot, $target, $this->shell))->remoteNames();

            foreach ($manifests as $releaseId) {
                $local = DeployPaths::localReleaseManifest($this->projectRoot, $releaseId);

                if (is_file($local)) {
                    continue;
                }

                $this->ensureDirectory(dirname($local));
                $scp->download(
                    $target->stateDir() .'/releases/'. $releaseId .'/manifest.json',
                    $local,
                );
            }

            $eventLog = new EventLog($this->projectRoot, $target, $this->shell);

            foreach ($events as $name) {
                if (is_file($eventLog->localPath($name))) {
                    continue;
                }

                $eventLog->download($name);
            }

            return [
                'ok' => true,
                'manifests' => count($manifests),
                'events' => count($events),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'manifests' => 0,
                'events' => 0,
                'error' => $exception->getMessage(),
            ];
        }
    }


    /** @return list<string> */
    private function manifestIds(Ssh $ssh, Target $target): array
    {
        $result = $ssh->must(
            $target,
            'ls -1 '. Ssh::quote($target->stateDir() .'/releases') .'/*/manifest.json 2>/dev/null || true',
        );

        $ids = [];

        foreach (explode("\n", trim($result->stdout)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $ids[] = basename(dirname(trim($line)));
        }

        sort($ids, SORT_STRING);

        return $ids;
    }


    private function ensureDirectory(string $directory): void
    {
        FileSystem::ensureDirectory($directory);
    }
}
