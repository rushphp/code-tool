<?php declare(strict_types=1);

namespace Rush\Tool\Code\Transport;

use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Support\FileSystem;
use Rush\Tool\Code\Target;


final class Transfer
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly Ssh $ssh,
        private readonly Scp $scp,
        private readonly OutputInterface $output,
        private readonly Layout $layout,
    ) {
    }


    public function run(?string $vendor, bool $hasRsync): void
    {
        $this->ensureRemoteDirs($vendor);

        if ($hasRsync) {
            $this->transferRsync($vendor);
        } else {
            $this->transferTar($vendor);
        }

        $this->applyOverrides($hasRsync);
    }


    public function ensureRemoteDirs(?string $vendor = null): void
    {
        $target = $this->target;
        $directories = [];

        foreach ($this->layout->publish() as $entry) {
            $destination = $this->layout->resolve($entry['to'], $target);
            $source = rtrim($this->projectRoot, '/') .'/'. $entry['from'];

            $directories[] = is_dir($source) ? $destination : dirname($destination);
        }

        foreach ($this->layout->writable() as $path) {
            $directories[] = $this->layout->resolve($path, $target);
        }

        if ($vendor !== null) {
            if ($target->root === null) {
                throw new \RuntimeException("transport: target '{$target->name}' requires a root for vendor");
            }

            $directories[] = $target->root .'/vendor';
        }

        $directories[] = $target->stateDir();
        $directories[] = $target->stateDir() .'/backups';
        $directories[] = $target->stateDir() .'/releases';
        $directories[] = $target->stateDir() .'/events';

        $command = 'mkdir -p';

        foreach (array_values(array_unique(array_filter($directories))) as $directory) {
            $command .= ' '. Ssh::quote($directory);
        }

        $this->ssh->must($target, $command);
        $this->protectStateDir();
    }


    public function applyOverrides(bool $hasRsync): void
    {
        foreach ($this->layout->publish() as $entry) {
            $source = rtrim($this->projectRoot, '/') .'/.rush/code/override/'. $this->target->name .'/'. $entry['from'];

            if ( ! is_dir($source) && ! is_file($source)) {
                continue;
            }

            $destination = $this->layout->resolve($entry['to'], $this->target);

            $this->out('override '. $entry['from']);

            if (is_file($source)) {
                if ($hasRsync) {
                    $this->rsync($this->rsyncArgs($source, $destination, [], false, false));
                } else {
                    $this->scp->upload($source, $destination);
                }

                continue;
            }

            if ($hasRsync) {
                $this->rsync($this->rsyncArgs($source .'/', $destination .'/', [], false, false));
                continue;
            }

            $this->transferTarDirectory($source, $destination);
        }
    }


    private function transferRsync(?string $vendor): void
    {
        foreach ($this->layout->publish() as $entry) {
            $source = rtrim($this->projectRoot, '/') .'/'. $entry['from'];
            $destination = $this->layout->resolve($entry['to'], $this->target);
            $excludes = $this->entryExcludes($entry, $destination);

            if ( ! is_dir($source) && ! is_file($source)) {
                throw new \RuntimeException("transport: missing source: {$entry['from']}");
            }

            $this->out('rsync '. $entry['from']);

            if (is_dir($source)) {
                $this->rsync($this->rsyncArgs($source .'/', $destination .'/', $excludes, false));
                continue;
            }

            $this->rsync($this->rsyncArgs($source, $destination, $excludes, false, false));
        }

        if ($vendor !== null) {
            $root = $this->target->root;

            if ($root === null) {
                throw new \RuntimeException("transport: target '{$this->target->name}' requires a root for vendor");
            }

            $this->out('rsync vendor');
            $this->rsync($this->rsyncArgs($vendor .'/', $root .'/vendor/', [], false, false));
        }
    }


    private function transferTar(?string $vendor): void
    {
        foreach ($this->layout->publish() as $entry) {
            $source = rtrim($this->projectRoot, '/') .'/'. $entry['from'];
            $destination = $this->layout->resolve($entry['to'], $this->target);
            $excludes = $this->entryExcludes($entry, $destination);

            if ( ! is_dir($source) && ! is_file($source)) {
                throw new \RuntimeException("transport: missing source: {$entry['from']}");
            }

            if (is_file($source)) {
                $this->ssh->must($this->target, 'mkdir -p '. Ssh::quote(dirname($destination)));
                $this->scp->upload($source, $destination);
                continue;
            }

            $this->out('tar '. $entry['from']);

            $archive = FileSystem::temporaryArchive();
            $base = $this->layout->destination($entry['to'])['base'];
            $remote = $this->target->stateDir() .'/incoming-'. $base .'-'. bin2hex(random_bytes(4)) .'.tar.gz';

            try {
                $command = ['tar', '-czf', $archive];

                foreach ($excludes as $exclude) {
                    $command[] = '--exclude='. $exclude;
                }

                array_push($command, '-C', $source, '.');

                $this->shell->mustRun($command);
                $this->scp->upload($archive, $remote);

                $this->ssh->must(
                    $this->target,
                    'mkdir -p '. Ssh::quote($destination)
                    .' && tar -xzf '. Ssh::quote($remote) .' -C '. Ssh::quote($destination)
                    .' && rm -f '. Ssh::quote($remote),
                );
            } finally {
                if ( ! FileSystem::tryDelete($archive)) {
                    $this->out('warning: could not remove temporary archive');
                }
            }
        }

        if ($vendor !== null) {
            $root = $this->target->root;

            if ($root === null) {
                throw new \RuntimeException("transport: target '{$this->target->name}' requires a root for vendor");
            }

            $this->secureDirectory($vendor, $root .'/vendor');
        }
    }


    private function transferTarDirectory(string $source, string $destination): void
    {
        $this->secureDirectory($source, $destination);
    }


    private function secureDirectory(string $source, string $destination): void
    {
        $archive = FileSystem::temporaryArchive();
        $remote = $this->target->stateDir() .'/incoming-'. bin2hex(random_bytes(4)) .'.tar.gz';

        try {
            $this->shell->mustRun(['tar', '-czf', $archive, '-C', $source, '.']);
            $this->scp->upload($archive, $remote);

            $this->ssh->must(
                $this->target,
                'mkdir -p '. Ssh::quote($destination)
                .' && tar -xzf '. Ssh::quote($remote) .' -C '. Ssh::quote($destination)
                .' && rm -f '. Ssh::quote($remote),
            );
        } finally {
            if ( ! FileSystem::tryDelete($archive)) {
                $this->out('warning: could not remove temporary archive');
            }
        }
    }


    private function protectStateDir(): void
    {
        $webroot = rtrim($this->target->webroot, '/');
        $state = rtrim($this->target->stateDir(), '/');

        if ( ! str_starts_with($state .'/', $webroot .'/')) {
            return;
        }

        $contents = <<<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HTACCESS;

        $this->ssh->must(
            $this->target,
            'cat > '. Ssh::quote($state .'/.htaccess'),
            $contents ."\n",
        );
    }


    /**
     * @param array{from: string, to: string} $entry
     * @return list<string>
     */
    private function entryExcludes(array $entry, string $destination): array
    {
        $excludes = [...$this->layout->excludesFor($entry['from']), '.deploy'];
        $base = $this->layout->destination($entry['to'])['base'];

        if ($base === 'webroot') {
            $excludes[] = '.maintenance';

            foreach ([...$this->layout->preserve(), ...$this->target->preserve] as $pattern) {
                $excludes[] = $pattern;
            }

            foreach ($this->target->nestedWebroots as $nested) {
                $excludes[] = '/'. $nested;
                $excludes[] = '/'. $nested .'/***';
            }
        }

        foreach ([...$this->layout->writable(), ...array_map(
            static fn (array $env): string => $env['to'],
            $this->layout->env(),
        )] as $protected) {
            $path = $this->layout->resolve($protected, $this->target);

            if ($path === $destination) {
                $excludes[] = '*';
                continue;
            }

            if (str_starts_with($path .'/', rtrim($destination, '/') .'/')) {
                $excludes[] = substr($path, strlen(rtrim($destination, '/')) + 1);
            }
        }

        return array_values(array_unique($excludes));
    }


    /**
     * @param list<string> $excludes
     * @return list<string>
     */
    private function rsyncArgs(
        string $source,
        string $destination,
        array $excludes,
        bool $dryRun,
        bool $delete = true,
    ): array {
        $target = $this->target;
        $sshParts = [
            ...$target->sshAuth->prefix('ssh'),
            '-p', (string) $target->sshPort,
            ...$target->sshAuth->options(),
            '-o', 'ConnectTimeout=15',
        ];

        $ssh = implode(' ', array_map(
            static fn (string $part): string => Ssh::quote($part),
            $sshParts,
        ));

        $args = [
            'rsync', '-az',
            '--checksum',
            '--delay-updates',
            '--protect-args',
            '--itemize-changes',
            '-e', $ssh,
        ];

        if ($delete) {
            $args[] = '--delete';
        }

        if ($dryRun) {
            $args[] = '--dry-run';
        }

        foreach ($excludes as $exclude) {
            $args[] = '--exclude='. $exclude;
        }

        $args[] = $source;
        $args[] = $target->sshTarget() .':'. $destination;

        return $args;
    }


    /** @param list<string> $args */
    private function rsync(array $args): void
    {
        $this->shell->mustRun($args, null, null, $this->target->sshAuth->env());
    }


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
