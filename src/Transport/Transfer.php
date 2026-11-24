<?php declare(strict_types=1);

namespace Rush\Tool\Code\Transport;

use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Deploy\PathRules;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Exception\TransportException;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Remote\SshOptions;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Support\FileSystem;
use Rush\Tool\Code\Target;


final class Transfer
{
    private readonly PathRules $rules;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly Ssh $ssh,
        private readonly Scp $scp,
        private readonly OutputInterface $output,
        private readonly Layout $layout,
    ) {
        $this->rules = new PathRules($layout, $target);
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
                throw new TransportException("transport: target '{$target->name}' requires a root for vendor");
            }

            $directories[] = $target->root .'/vendor';
        }

        $directories[] = DeployPaths::remote($target);
        $directories[] = DeployPaths::backups($target);
        $directories[] = DeployPaths::releases($target);
        $directories[] = DeployPaths::events($target);

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
            $source = DeployPaths::localOverride($this->projectRoot, $this->target->name, $entry['from']);

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
            $excludes = $this->entryExcludes($entry);

            if ( ! is_dir($source) && ! is_file($source)) {
                throw new TransportException("transport: missing source: {$entry['from']}");
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
                throw new TransportException("transport: target '{$this->target->name}' requires a root for vendor");
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
            $excludes = $this->entryExcludes($entry);

            if ( ! is_dir($source) && ! is_file($source)) {
                throw new TransportException("transport: missing source: {$entry['from']}");
            }

            if (is_file($source)) {
                $this->ssh->must($this->target, 'mkdir -p '. Ssh::quote(dirname($destination)));
                $this->scp->upload($source, $destination);
                continue;
            }

            $this->out('tar '. $entry['from']);

            $archive = FileSystem::temporaryArchive();
            $base = $this->layout->destination($entry['to'])['base'];
            $remote = DeployPaths::incoming($this->target, $base);

            try {
                $command = ['tar', '-czf', $archive];

                foreach ($excludes as $exclude) {
                    $command[] = '--exclude='. ($exclude === '*' ? '*' : './'. ltrim($exclude, '/'));
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
                throw new TransportException("transport: target '{$this->target->name}' requires a root for vendor");
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
        $remote = DeployPaths::incoming($this->target, 'override');

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
    private function entryExcludes(array $entry): array
    {
        return $this->rules->excludesFor($entry['from'], $entry['to']);
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

        $args = [
            'rsync', '-az',
            '--checksum',
            '--delay-updates',
            '--protect-args',
            '--itemize-changes',
            '-e', SshOptions::rsyncRemoteShell($target),
        ];

        if ($delete) {
            $args[] = '--delete';
        }

        if ($dryRun) {
            $args[] = '--dry-run';
        }

        foreach ($excludes as $exclude) {
            $args[] = '--exclude='. ($exclude === '*' ? '*' : '/'. ltrim($exclude, '/'));
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
