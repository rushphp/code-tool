<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy;

use Rush\Tool\Code\Layout;


final class BuildPlan
{
    /** @var array{composer: bool, include: list<string>, exclude: list<string>, flags: list<string>, platform: string} */
    private readonly array $build;


    public function __construct(
        private readonly Layout $layout,
    ) {
        $this->build = $layout->build();
    }


    public function enabled(): bool
    {
        return $this->build['composer'];
    }


    /** @return list<string> */
    public function include(): array
    {
        return $this->build['include'];
    }


    /** @return list<string> */
    public function excludesFor(string $include): array
    {
        $excludes = $this->layout->excludesFor($include);

        foreach ($this->build['exclude'] as $exclude) {
            $exclude = trim($exclude, '/');

            if ($exclude === $include) {
                $excludes[] = '*';
                continue;
            }

            if (str_starts_with($exclude, $include .'/')) {
                $excludes[] = substr($exclude, strlen($include) + 1);
            }
        }

        return array_values(array_unique($excludes));
    }


    /** @return list<string> */
    public function flags(): array
    {
        return $this->build['flags'];
    }


    public function platform(?string $remotePhp): string
    {
        if ($this->build['platform'] !== 'remote') {
            return $this->build['platform'];
        }

        if ($remotePhp === null || $remotePhp === '') {
            throw new \RuntimeException('build: remote PHP version is required for platform=remote');
        }

        return $remotePhp;
    }
}
