<?php declare(strict_types=1);

namespace Rush\Tool\Code\Release;

use Rush\Support\FileSystem;
use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Exception\ReleaseException;
use Rush\Tool\Code\Support\Json;


final class ReleaseStore
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }


    public function manifestPath(string $releaseId): string
    {
        return DeployPaths::localReleaseManifest($this->projectRoot, $releaseId);
    }


    public function exists(string $releaseId): bool
    {
        return is_file($this->manifestPath($releaseId));
    }


    public function persist(ReleaseManifest $manifest): void
    {
        FileSystem::write(
            $this->manifestPath($manifest->releaseId),
            Json::encode($manifest->toArray(), 'release manifest'),
            0600,
        );
    }


    public function manifest(string $releaseId): ReleaseManifest
    {
        $path = $this->manifestPath($releaseId);

        if ( ! is_file($path)) {
            throw new ReleaseException("release manifest not found: {$releaseId}");
        }

        return ReleaseManifest::fromArray(Json::read($path, "invalid release manifest: {$releaseId}"));
    }


    /** @return list<string> */
    public function releaseIds(): array
    {
        $directory = DeployPaths::localReleases($this->projectRoot);

        if ( ! is_dir($directory)) {
            return [];
        }

        $ids = [];

        foreach (glob($directory .'/*/manifest.json') ?: [] as $file) {
            $ids[] = basename(dirname($file));
        }

        sort($ids, SORT_STRING);

        return $ids;
    }
}
