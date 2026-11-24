<?php declare(strict_types=1);

namespace Rush\Tool\Code\Release;

use Rush\Contract\OutputInterface;
use Rush\Support\FileSystem;


final class ReleaseExport
{
    public function __construct(
        public readonly string $archive,
        public readonly ReleaseManifest $manifest,
        public readonly string $directory,
        public readonly bool $temporary = true,
    ) {
    }


    /** @return array{archive: string, manifest: ReleaseManifest, temporary: bool} */
    public function toArray(): array
    {
        return [
            'archive' => $this->archive,
            'manifest' => $this->manifest,
            'temporary' => $this->temporary,
        ];
    }


    public function cleanup(OutputInterface $output): void
    {
        if ( ! $this->temporary) {
            return;
        }

        if ( ! FileSystem::tryDelete($this->archive)) {
            $output->warning('warning: could not remove temporary release archive');
        }

        if ( ! FileSystem::tryDelete($this->directory .'/manifest.json')) {
            $output->warning('warning: could not remove temporary release manifest');
        }

        if ( ! FileSystem::tryDelete($this->directory)) {
            $output->warning('warning: could not remove temporary release directory');
        }
    }
}
