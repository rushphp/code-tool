<?php declare(strict_types=1);

namespace Rush\Tool\Code\Release;

use Rush\Tool\Code\Exception\ReleaseException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Rush\Tool\Code\Layout;


final class ReleaseHasher
{
    public function __construct(
        private readonly ?Layout $layout = null,
    ) {
    }


    public function hash(string $projectRoot): string
    {
        $layout = $this->layout ?? Layout::fromProject($projectRoot);
        $root = rtrim($projectRoot, '/');
        $files = [];

        foreach ($layout->publish() as $entry) {
            $source = $root .'/'. $entry['from'];

            if (is_dir($source)) {
                $this->collect($root, $entry['from'], $layout->excludesFor($entry['from']), $files);
                continue;
            }

            if ( ! is_file($source)) {
                throw new ReleaseException("missing source: {$entry['from']}");
            }

            $files[trim($entry['from'], '/')] = $source;
        }

        if ($layout->build()['composer']) {
            foreach (['composer.json', 'composer.lock'] as $file) {
                $path = $root .'/'. $file;

                if ( ! is_file($path)) {
                    throw new ReleaseException("missing file: {$file}");
                }

                if ( ! isset($files[$file])) {
                    $files[$file] = $path;
                }
            }
        }

        ksort($files, SORT_STRING);

        $manifest = '';

        foreach ($files as $relative => $path) {
            $hash = hash_file('sha256', $path);

            if ($hash === false) {
                throw new ReleaseException("could not hash file: {$relative}");
            }

            $manifest .= $relative ."\0". $hash ."\n";
        }

        return 'sha256:'. hash('sha256', $manifest);
    }


    /**
     * @param list<string> $excludes
     * @param array<string, string> $files
     */
    private function collect(string $projectRoot, string $directory, array $excludes, array &$files): void
    {
        $root = $projectRoot .'/'. $directory;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ( ! $item instanceof SplFileInfo || $item->isLink() || ! $item->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($projectRoot) + 1));
            $inner = ltrim(substr($relative, strlen($directory)), '/');

            if ($this->excluded($inner, $excludes)) {
                continue;
            }

            $files[$relative] = $item->getPathname();
        }
    }


    /** @param list<string> $excludes */
    private function excluded(string $relative, array $excludes): bool
    {
        if ($relative === '.deploy' || str_starts_with($relative, '.deploy/')) {
            return true;
        }

        foreach ($excludes as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded .'/')) {
                return true;
            }

            if (str_ends_with($excluded, '/*') && str_starts_with($relative, substr($excluded, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
