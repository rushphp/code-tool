<?php declare(strict_types=1);

namespace Rush\Tool\Code\Remote;

use JsonException;
use RuntimeException;
use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Release\ReleaseManifest;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Target;


final class RemoteFiles
{
    public function __construct(
        private readonly Target $target,
        private readonly Ssh $ssh,
    ) {
    }


    /** @return null|array<string, mixed> */
    public function readCurrent(): ?array
    {
        $result = $this->ssh->must(
            $this->target,
            'if [ -f '. Ssh::quote(DeployPaths::current($this->target)) .' ]; '
            .'then cat '. Ssh::quote(DeployPaths::current($this->target))
            .'; else printf __MISSING__; fi',
        );

        if (trim($result->stdout) === '__MISSING__' || trim($result->stdout) === '')
        {
            return null;
        }

        $data = json_decode($result->stdout, true);

        if ( ! is_array($data))
        {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }


    public function currentReleaseId(): ?string
    {
        $current = $this->readCurrent();
        $releaseId = $current['releaseId'] ?? null;

        return is_string($releaseId) && $releaseId !== '' ? $releaseId : null;
    }


    public function writeCurrent(
        ReleaseManifest $manifest,
        string $snapshot,
        string $status,
        ?string $previousReleaseId = null,
        ?string $promotedFrom = null,
    ): void {
        $data = [
            'releaseId' => $manifest->releaseId,
            'contentHash' => $manifest->contentHash,
            'version' => $manifest->version,
            'status' => $status,
            'deployedAt' => gmdate('c'),
            'previousReleaseId' => $previousReleaseId ?? $manifest->previousReleaseId,
            'backup' => $snapshot,
        ];

        if ($promotedFrom !== null) {
            $data['promotedFrom'] = $promotedFrom;
        }

        $this->writeJson(DeployPaths::current($this->target), $data);
    }


    public function writeCurrentReleaseId(string $releaseId): void
    {
        $this->writeJson(DeployPaths::current($this->target), [
            'releaseId' => $releaseId,
            'status' => 'current',
        ]);
    }


    public function deleteCurrent(): void
    {
        $this->ssh->run(
            $this->target,
            'rm -f '. Ssh::quote(DeployPaths::current($this->target)),
        );
    }


    /** @param array<string, mixed> $data */
    public function writeJson(string $path, array $data): void
    {
        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ) ."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('could not encode remote JSON: '. $exception->getMessage(), 0, $exception);
        }

        $this->writeFile($path, $json);
    }


    public function writeFile(string $path, string $contents): void
    {
        $this->ssh->must(
            $this->target,
            'cat > '. Ssh::quote($path),
            $contents,
        );
    }
}
