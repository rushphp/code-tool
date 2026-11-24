<?php declare(strict_types=1);

namespace Rush\Tool\Code\Event;

use Rush\Support\FileSystem;
use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Support\Json;
use Rush\Tool\Code\Target;


final class EventLog
{
    private readonly Ssh $ssh;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
    ) {
        $this->ssh = new Ssh($shell);
    }


    public function write(DeployEvent $event): string
    {
        $name = gmdate('Ymd\THis\Z')
            .'-'. $event->target
            .'-'. $event->action
            .'-'. $event->releaseId
            .'-'. bin2hex(random_bytes(3))
            .'.json';

        $json = Json::encode($event->toArray(), 'deploy event');

        $this->ensureLocalDirectory();
        $this->ssh->must($this->target, 'mkdir -p '. Ssh::quote($this->remoteDirectory()));
        $this->ssh->must(
            $this->target,
            'cat > '. Ssh::quote($this->remotePath($name)),
            $json,
        );

        FileSystem::write($this->localPath($name), $json, 0600);

        return $name;
    }


    /** @return list<string> */
    public function remoteNames(): array
    {
        $result = $this->ssh->must(
            $this->target,
            'ls -1 '. Ssh::quote($this->remoteDirectory()) .'/*.json 2>/dev/null || true',
        );

        $names = [];

        foreach (explode("\n", trim($result->stdout)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $names[] = basename(trim($line));
        }

        sort($names, SORT_STRING);

        return $names;
    }


    public function download(string $name): void
    {
        $this->ensureLocalDirectory();
        $this->downloadFile($this->remotePath($name), $this->localPath($name));
    }


    public function prune(int $keep): void
    {
        $keep = max(1, $keep);

        $this->ssh->run(
            $this->target,
            'ls -1t '. Ssh::quote($this->remoteDirectory()) .'/*.json 2>/dev/null'
            .' | tail -n +'. ($keep + 1)
            .' | xargs -r rm -f',
        );
    }


    public function localDirectory(): string
    {
        return DeployPaths::localEvents($this->projectRoot);
    }

    public function localPath(string $name): string
    {
        return $this->localDirectory() .'/'. $name;
    }

    public function remoteDirectory(): string
    {
        return $this->target->stateDir() .'/events';
    }

    public function remotePath(string $name): string
    {
        return $this->remoteDirectory() .'/'. $name;
    }


    private function ensureLocalDirectory(): void
    {
        FileSystem::ensureDirectory($this->localDirectory(), 0700);
    }


    private function downloadFile(string $remote, string $local): void
    {
        (new Scp($this->target, $this->shell))->download($remote, $local);
    }
}
