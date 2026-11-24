<?php declare(strict_types=1);

namespace Rush\Tool\Code\Release;

use Rush\Tool\Code\Exception\ReleaseException;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Support\Json;
use Rush\Support\FileSystem;
use Rush\Tool\Code\Target;


final class ReleasePublisher
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly Ssh $ssh,
        private readonly Scp $scp,
        private readonly RemoteFiles $remote,
        private readonly OutputInterface $output,
        private readonly Layout $layout,
    ) {
    }


    public function createArchive(?string $vendor): string
    {
        $stage = FileSystem::temporaryDirectory();

        try {
            $rootStage = $stage .'/root';
            $webrootStage = $stage .'/webroot';

            FileSystem::ensureDirectory($rootStage);
            FileSystem::ensureDirectory($webrootStage);

            foreach ($this->layout->publish() as $entry) {
                $destination = $this->layout->destination($entry['to']);
                $base = $destination['base'] === 'root' ? $rootStage : $webrootStage;
                $target = $destination['path'] === ''
                    ? $base
                    : $base .'/'. $destination['path'];
                $source = rtrim($this->projectRoot, '/') .'/'. $entry['from'];
                $excludes = [...$this->layout->excludesFor($entry['from']), '.deploy'];

                if (is_dir($source)) {
                    FileSystem::copyDir($source, $target, $excludes);
                    continue;
                }

                if ( ! is_file($source)) {
                    throw new ReleaseException("missing publish source: {$entry['from']}");
                }

                FileSystem::copyFile($source, $target);
            }

            if ($vendor !== null) {
                FileSystem::copyDir($vendor, $rootStage .'/vendor');
            }

            $archive = FileSystem::temporaryArchive();

            $this->shell->mustRun([
                'tar', '-czf', $archive,
                '-C', $stage,
                'root', 'webroot',
            ]);

            if ( ! is_file($archive)) {
                throw new ReleaseException('release archive was not created');
            }

            return $archive;
        } finally {
            FileSystem::removeDir($stage);
        }
    }


    public function upload(ReleaseManifest $manifest, string $archive): ?bool
    {
        $target = $this->target;
        $directory = $target->stateDir() .'/releases/'. $manifest->releaseId;
        $remoteArchive = $directory .'/release.tar.gz';

        $this->ssh->must($target, 'mkdir -p '. Ssh::quote($directory));
        $this->scp->upload($archive, $remoteArchive);

        $verified = null;

        if ($manifest->artifactHash === null) {
            $this->out('warning: release has no artifact hash; integrity not verified');
        } else {
            $verification = (new ArtifactVerifier())->verifyRemote(
                $target,
                $this->shell,
                $remoteArchive,
                $manifest->artifactHash,
            );

            if ( ! $verification['available']) {
                $this->out('warning: remote artifact hash tool not found; integrity not verified');
            } elseif ( ! $verification['ok']) {
                throw new ReleaseException("artifact hash mismatch: {$manifest->releaseId}");
            } else {
                $verified = true;
            }
        }

        $this->remote->writeFile($directory .'/manifest.json', Json::encode($manifest->toArray(), 'release manifest'));

        return $verified;
    }


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
