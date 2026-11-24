<?php declare(strict_types=1);

namespace Rush\Tool\Code\Release;

use Rush\Support\FileSystem;
use Rush\Tool\Code\Exception\ReleaseException;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Support\Json;
use Rush\Tool\Code\Target;


final class ReleaseExporter
{
    public function __construct(
        private readonly Target $target,
        private readonly Ssh $ssh,
        private readonly Scp $scp,
        private readonly RemoteFiles $remote,
    ) {
    }


    /** @return array{archive: string, manifest: ReleaseManifest, temporary: bool} */
    public function export(): array
    {
        return $this->exportRelease()->toArray();
    }


    public function exportRelease(): ReleaseExport
    {
        $current = $this->remote->readCurrent();

        if ($current === null) {
            throw new ReleaseException('source has no current release');
        }

        $status = $current['status'] ?? null;

        if ($status !== null && $status !== 'current') {
            throw new ReleaseException('source is not in current state');
        }

        $releaseId = $current['releaseId'] ?? null;

        if ( ! is_string($releaseId) || $releaseId === '') {
            throw new ReleaseException('source has no current release');
        }

        $remoteArchive = $this->target->stateDir() .'/releases/'. $releaseId .'/release.tar.gz';
        $remoteManifest = $this->target->stateDir() .'/releases/'. $releaseId .'/manifest.json';
        $available = $this->ssh->run(
            $this->target,
            'test -f '. Ssh::quote($remoteArchive)
            .' && test -f '. Ssh::quote($remoteManifest),
        );

        if ( ! $available->ok()) {
            throw new ReleaseException("source release artifact not found: {$releaseId}");
        }

        $directory = FileSystem::temporaryDirectory('rush-release-');

        $archive = $directory .'/release.tar.gz';
        $manifestPath = $directory .'/manifest.json';

        $this->scp->download($remoteArchive, $archive);
        $this->scp->download($remoteManifest, $manifestPath);

        $manifest = ReleaseManifest::fromArray(Json::read($manifestPath, 'invalid downloaded release manifest'));

        if ($manifest->artifactHash !== null) {
            $actual = (new ArtifactVerifier())->hash($archive);

            if ( ! hash_equals($manifest->artifactHash, $actual)) {
                throw new ReleaseException("artifact hash mismatch: {$releaseId}");
            }
        }

        return new ReleaseExport($archive, $manifest, $directory);
    }
}
