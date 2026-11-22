<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy;

use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Target;


final class PathRules
{
    public function __construct(
        private readonly Layout $layout,
        private readonly Target $target,
    ) {
    }


    /** @return list<string> */
    public function excludesFor(string $from, string $to): array
    {
        $excludes = [...$this->layout->excludesFor($from), '.deploy'];
        $destination = $this->layout->resolve($to, $this->target);
        $base = $this->layout->destination($to)['base'];

        if ($base === 'webroot') {
            $excludes[] = '.maintenance';

            foreach ([...$this->layout->preserve(), ...$this->target->preserve] as $pattern) {
                $excludes[] = $this->tarPattern($pattern);
            }

            foreach ($this->target->nestedWebroots as $nested) {
                $excludes[] = $nested;
            }
        }

        if (in_array($destination, $this->protectedPaths(), true)) {
            $excludes[] = '*';
        }

        $excludes = [...$excludes, ...$this->protectedPathsUnder($destination)];

        return array_values(array_unique(array_filter($excludes, static fn (string $value): bool => $value !== '')));
    }


    /** @return list<string> */
    public function preserveInside(string $directory, bool $webroot = false): array
    {
        $preserve = ['.deploy'];

        if ($webroot) {
            $preserve[] = '.maintenance';
            $preserve = [...$preserve, ...$this->target->nestedWebroots];

            foreach ([...$this->layout->preserve(), ...$this->target->preserve] as $pattern) {
                $name = explode('/', ltrim($this->tarPattern($pattern), '/'))[0];

                if ($name !== '') {
                    $preserve[] = $name;
                }
            }
        }

        if ($directory === $this->target->root) {
            $relative = $this->relativeWebroot();

            if ($relative !== null) {
                $preserve[] = explode('/', $relative)[0];
            }
        }

        $preserve = [...$preserve, ...$this->protectedPathsUnder($directory, true)];

        return array_values(array_unique($preserve));
    }


    /** @return list<string> */
    public function webrootPreserve(bool $stub = false): array
    {
        $preserve = $this->preserveInside($this->target->webroot, true);

        if ($stub) {
            $preserve[] = 'index.php';
            $preserve[] = '.htaccess';
        }

        return array_values(array_unique($preserve));
    }


    /** @param list<string> $preserve */
    public function deleteContents(string $directory, array $preserve): string
    {
        $command = 'find '. Ssh::quote($directory) .' -mindepth 1 -maxdepth 1';

        foreach (array_values(array_unique($preserve)) as $name) {
            if ($name !== '') {
                $command .= ' ! -name '. Ssh::quote($name);
            }
        }

        return $command .' -exec rm -rf {} +';
    }


    public function tarPattern(string $pattern): string
    {
        $pattern = ltrim($pattern, '/');

        foreach (['/***', '/**', '/*'] as $suffix) {
            if (str_ends_with($pattern, $suffix)) {
                return substr($pattern, 0, -strlen($suffix));
            }
        }

        return $pattern;
    }


    public function stateInRoot(): bool
    {
        return $this->target->root !== null
            && str_starts_with($this->target->stateDir() .'/', rtrim($this->target->root, '/') .'/');
    }


    public function stateInWebroot(): bool
    {
        return str_starts_with(
            rtrim($this->target->stateDir(), '/') .'/',
            rtrim($this->target->webroot, '/') .'/',
        );
    }


    public function relativeWebroot(): ?string
    {
        if ($this->target->root === null) {
            return null;
        }

        $root = rtrim($this->target->root, '/') .'/';

        if ( ! str_starts_with($this->target->webroot, $root)) {
            return null;
        }

        return substr($this->target->webroot, strlen($root));
    }


    /** @return list<string> */
    private function protectedPathsUnder(string $directory, bool $topLevel = false): array
    {
        $paths = [];
        $base = rtrim($directory, '/');

        foreach ($this->protectedPaths() as $resolved) {
            if ($resolved === $directory) {
                continue;
            }

            if (str_starts_with($resolved .'/', $base .'/')) {
                $relative = substr($resolved, strlen($base) + 1);

                if ($relative !== '') {
                    $paths[] = $topLevel ? explode('/', $relative)[0] : $relative;
                }
            }
        }

        return array_values(array_unique($paths));
    }


    /** @return list<string> */
    private function protectedPaths(): array
    {
        $paths = [];

        foreach ([...$this->layout->writable(), ...array_map(
            static fn (array $env): string => $env['to'],
            $this->layout->env(),
        )] as $protected) {
            $paths[] = $this->layout->resolve($protected, $this->target);
        }

        return array_values(array_unique($paths));
    }
}
