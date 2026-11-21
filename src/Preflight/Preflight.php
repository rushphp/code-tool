<?php declare(strict_types=1);

namespace Rush\Tool\Code\Preflight;

use RuntimeException;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Requirements\ComposerRequirements;
use Rush\Tool\Code\Requirements\WebProbe;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Support\Paths;
use Rush\Tool\Code\Target;


final class Preflight
{
    /** @var list<string> */
    private array $missingEnv = [];


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly Ssh $ssh,
        private readonly RemoteFiles $remote,
        private readonly OutputInterface $output,
        private readonly Layout $layout,
    ) {
    }


    public function check(): bool
    {
        $target = $this->target;

        $this->out('check');

        if (version_compare(PHP_VERSION, $this->layout->phpMin(), '<')) {
            throw new RuntimeException(
                'local PHP '. PHP_VERSION .' is below the required '. $this->layout->phpMin(),
            );
        }

        $build = $this->layout->build();

        if ($build['composer']) {
            foreach (['composer.json', 'composer.lock'] as $file) {
                if ( ! is_file($this->projectRoot .'/'. $file)) {
                    throw new RuntimeException("missing file: {$file}");
                }
            }
        }

        $command = 'test -d '. Ssh::quote($target->webroot)
            .' && test -w '. Ssh::quote($target->webroot);

        if ($target->root !== null) {
            $base = $target->rootBase ?? $target->root;

            $command .= ' && test -d '. Ssh::quote($base)
                .' && test -w '. Ssh::quote($base);

            if ($target->rootDir !== null) {
                $command .= ' && { [ -d '. Ssh::quote($target->root) .' ] || mkdir '. Ssh::quote($target->root) .'; }';
            }

            $command .= ' && test -d '. Ssh::quote($target->root)
                .' && test -w '. Ssh::quote($target->root);
        }

        foreach ($this->layout->writable() as $path) {
            $resolved = $this->layout->resolve($path, $target);
            $command .= ' && { [ ! -d '. Ssh::quote($resolved) .' ] || test -w '. Ssh::quote($resolved) .'; }';
        }

        $state = $target->stateDir();

        $command .= ' && { [ ! -d '. Ssh::quote($state) .' ] || test -w '. Ssh::quote($state) .'; }';

        $this->ssh->must($target, $command);

        $this->out('ssh ok, paths are writable');

        $this->warnAboutEntrypoint();

        $hasRsync = $this->ssh->must(
            $target,
            'command -v rsync >/dev/null 2>&1 && echo yes || echo no',
        )->output() === 'yes';

        $this->out('transport: '. ($hasRsync ? 'rsync' : 'tar (fallback)'));

        $this->checkEnv();
        $this->checkAuth();
        $this->checkStateProtection();
        $this->checkRequirements();

        return $hasRsync;
    }


    public function assertEnvExists(): void
    {
        if ($this->missingEnv === []) {
            return;
        }

        throw new RuntimeException(
            "missing env for '{$this->target->name}': add ". implode(', ', $this->missingEnv),
        );
    }


    private function checkEnv(): void
    {
        $this->missingEnv = [];

        foreach ($this->layout->env() as $entry) {
            $local = Paths::override($this->projectRoot, $this->target->name, $entry['from']);

            if (is_file($local)) {
                continue;
            }

            $this->missingEnv[] = $local;
            $this->out("warning: no env override for '{$this->target->name}'; add {$local}");
        }
    }


    private function checkAuth(): void
    {
        $auth = $this->target->sshAuth;
        $file = $auth->filePath();

        if ( ! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException('ssh auth file is not readable: '. $file);
        }

        if ($auth->isPassword()) {
            $auth->password();
        }
    }


    private function checkStateProtection(): void
    {
        $webroot = rtrim($this->target->webroot, '/');
        $state = rtrim($this->target->stateDir(), '/');

        if ( ! str_starts_with($state .'/', $webroot .'/')) {
            return;
        }

        $exists = $this->ssh->run(
            $this->target,
            'test -f '. Ssh::quote($state .'/current.json') .' && echo yes || echo no',
        )->output() === 'yes';

        if ( ! $exists) {
            return;
        }

        $url = rtrim($this->target->url, '/') .'/.deploy/current.json';
        $result = $this->shell->run(
            ['curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '20', $url],
        );
        $status = trim($result->output());

        if ($status === '200') {
            throw new RuntimeException(
                '.deploy is publicly accessible: protect '. $state .' in the web server',
            );
        }
    }


    private function checkRequirements(): void
    {
        $current = $this->remote->readCurrent();

        if (($current['status'] ?? null) === 'redirected') {
            $this->out('requirements: skipped for redirected target');

            return;
        }

        $probe = (new WebProbe())->inspect($this->target, $this->shell);

        if (version_compare($probe['php'], $this->layout->phpMin(), '<')) {
            throw new RuntimeException(
                'remote PHP '. $probe['php'] .' is below the required '. $this->layout->phpMin(),
            );
        }

        if ($this->layout->build()['composer']) {
            $requirements = ComposerRequirements::fromFile($this->projectRoot .'/composer.json');
            $requirements->assert($probe);
            $this->out('requirements ok: PHP '. $probe['php']);
        }
    }


    private function warnAboutEntrypoint(): void
    {
        if ($this->target->root !== null
            && rtrim(dirname($this->target->webroot), '/') === rtrim($this->target->root, '/'))
        {
            return;
        }

        $override = Paths::override($this->projectRoot, $this->target->name, $this->layout->entrypoint());

        if ( ! is_file($override))
        {
            $this->out(
                'warning: webroot is not directly under root; add an entrypoint at '
                .'.rush/code/override/'. $this->target->name .'/'. $this->layout->entrypoint(),
            );
        }
    }


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
