<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use Rush\Support\FileSystem;
use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Exception\SecurityException;


final class Initializer
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $examplePath = null,
        private readonly ?Layout $layout = null,
    ) {
    }


    /** @return list<string> */
    public function run(): array
    {
        $layout = $this->resolveLayout();
        $base = DeployPaths::local($this->projectRoot);
        $config = DeployPaths::localConfig($this->projectRoot);
        $example = $this->examplePath ?? dirname(__DIR__) .'/code.example.json';

        if (is_link($base)) {
            throw new SecurityException("refusing to use symlink: {$base}");
        }

        $lines = [];
        $directories = [
            $base,
            DeployPaths::localAuth($this->projectRoot),
            DeployPaths::localStub($this->projectRoot),
            DeployPaths::localReleases($this->projectRoot),
            DeployPaths::localEvents($this->projectRoot),
        ];

        foreach (Config::TARGETS as $environment) {
            $directories[] = $base .'/override/'. $environment;

            if ($layout === null) {
                continue;
            }

            foreach ($layout->publish() as $entry) {
                $directories[] = $this->overrideDirectory($base, $environment, $entry['from']);
            }

            foreach ($layout->env() as $entry) {
                $directories[] = dirname($base .'/override/'. $environment .'/'. $entry['from']);
            }
        }

        foreach (array_values(array_unique($directories)) as $directory) {
            $lines[] = $this->directory($directory);
        }

        $lines[] = $this->file($base .'/stub/index.php', dirname(__DIR__) .'/resources/stub/index.php');
        $lines[] = $this->file($base .'/stub/.htaccess', dirname(__DIR__) .'/resources/stub/.htaccess');
        $lines[] = $this->configuration($config, $example);

        return $lines;
    }


    private function resolveLayout(): ?Layout
    {
        if ($this->layout !== null) {
            return $this->layout;
        }

        $file = rtrim($this->projectRoot, '/') .'/code.layout.json';

        return is_file($file) ? Layout::fromProject($this->projectRoot) : null;
    }


    private function overrideDirectory(string $base, string $environment, string $from): string
    {
        $path = $base .'/override/'. $environment .'/'. $from;
        $source = rtrim($this->projectRoot, '/') .'/'. $from;

        if (is_file($source) || ! is_dir($source)) {
            return dirname($path);
        }

        return $path;
    }


    private function directory(string $path): string
    {
        if (is_link($path)) {
            throw new SecurityException("refusing to use symlink: {$path}");
        }

        if (is_dir($path)) {
            return 'exists '. $this->display($path);
        }

        FileSystem::ensureDirectory($path);

        return 'created '. $this->display($path);
    }


    private function file(string $path, string $template): string
    {
        if (is_link($path)) {
            throw new SecurityException("refusing to use symlink: {$path}");
        }

        if (is_file($path)) {
            return 'exists '. $this->display($path);
        }

        FileSystem::copyFile($template, $path);
        FileSystem::chmod($path, 0600);

        return 'created '. $this->display($path);
    }


    private function configuration(string $config, string $example): string
    {
        if (is_link($config)) {
            throw new SecurityException("refusing to use symlink: {$config}");
        }

        if (is_file($config)) {
            return 'exists '. $this->display($config);
        }

        FileSystem::copyFile($example, $config);
        FileSystem::chmod($config, 0600);

        return 'created '. $this->display($config);
    }


    private function display(string $path): string
    {
        $root = rtrim($this->projectRoot, '/');

        if (str_starts_with($path, $root .'/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }
}
