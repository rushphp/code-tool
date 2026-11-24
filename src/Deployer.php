<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use Rush\Contract\OutputInterface;
use Rush\Support\FileSystem;
use Rush\Support\Operator;
use Rush\Tool\Code\Backup\SnapshotStore;
use Rush\Tool\Code\Deploy\PathRules;
use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Deployment;
use Rush\Tool\Code\Deploy\Pipeline\RollbackGuard;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ApplyOverridesStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ArchiveStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ArtifactHashStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\AssertEnvStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\BuildVendorStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\CheckStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\EnsureRemoteDirsStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\HashStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\MaintenanceStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ManifestStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\PersistReleaseStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\PruneStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\PublishArtifactStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\PushEventStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ReactivateStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ReadCurrentStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\RestoreStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\RollbackEventStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\SelectBackupStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\SnapshotStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\TransferStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\UpdateCurrentStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\VerifyStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\VersionStep;
use Rush\Tool\Code\Event\EventWriter;
use Rush\Tool\Code\Maintenance;
use Rush\Tool\Code\Preflight\Preflight;
use Rush\Tool\Code\Release\ReleasePublisher;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Transport\Transfer;
use Rush\Tool\Code\Verify\Verifier;
use Throwable;


final class Deployer
{
    private readonly Ssh $ssh;

    private readonly Maintenance $maintenance;

    private readonly Scp $scp;

    private readonly RemoteFiles $remote;

    private readonly SnapshotStore $snapshots;

    private readonly Transfer $transfer;

    private readonly Preflight $preflight;

    private readonly ReleasePublisher $publisher;

    private readonly Verifier $verifier;

    private readonly EventWriter $events;

    private readonly Layout $layout;

    private readonly PathRules $rules;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly OutputInterface $output,
        private readonly Operator $operator,
        ?Layout $layout = null,
    ) {
        $this->layout = $layout ?? Layout::fromProject($projectRoot);
        $this->ssh = new Ssh($shell);
        $this->maintenance = new Maintenance($this->ssh, $this->layout);
        $this->scp = new Scp($target, $shell);
        $this->remote = new RemoteFiles($target, $this->ssh);
        $this->snapshots = new SnapshotStore($target, $this->ssh, $this->layout);
        $this->transfer = new Transfer($projectRoot, $target, $shell, $this->ssh, $this->scp, $output, $this->layout);
        $this->preflight = new Preflight($projectRoot, $target, $shell, $this->ssh, $output, $this->layout);
        $this->publisher = new ReleasePublisher($projectRoot, $target, $shell, $this->ssh, $this->scp, $this->remote, $output, $this->layout);
        $this->verifier = new Verifier($target, $shell, $output);
        $this->events = new EventWriter($target, $operator, $output, $projectRoot, $shell);
        $this->rules = new PathRules($this->layout, $target);
    }


    public function check(): void
    {
        $this->preflight->check();
    }


    public function push(): void
    {
        $context = $this->context();

        try {
            (new Deployment([
                new CheckStep(),
                new AssertEnvStep(),
                new ReadCurrentStep(),
                new HashStep(),
                new VersionStep(),
                new BuildVendorStep(),
                new ArchiveStep(),
                new ArtifactHashStep(),
                new ManifestStep(),
                new MaintenanceStep(true),
                new EnsureRemoteDirsStep(),
                new SnapshotStep(),
                new ReactivateStep(),
                new TransferStep(),
                new MaintenanceStep(false),
                new VerifyStep(),
                new PersistReleaseStep(),
                new PublishArtifactStep(),
                new UpdateCurrentStep(),
                new PushEventStep(),
                new PruneStep(),
            ]))->run($context);

            if ($context->manifest !== null) {
                $this->out('release: '. $context->manifest->releaseId .' ('. $context->manifest->version .')');
            }

            $this->out('deploy complete');
        } catch (Throwable $error) {
            RollbackGuard::recoverDeployment($context, $error);
            throw $error;
        } finally {
            $this->cleanUp($context);
        }
    }


    /**
     * @return list<string>
     */
    public function backups(): array
    {
        return $this->snapshots->backups();
    }


    public function rollback(?string $timestamp): void
    {
        $context = $this->context();

        try {
            (new Deployment([
                new CheckStep(),
                new AssertEnvStep(),
                new SelectBackupStep($timestamp),
                new MaintenanceStep(true),
                new RestoreStep(),
                new ApplyOverridesStep(),
                new MaintenanceStep(false),
                new VerifyStep(),
                new RollbackEventStep(),
            ]))->run($context);
        } catch (Throwable $error) {
            RollbackGuard::recoverRollback($context);
            throw $error;
        }
    }


    private function context(): DeployContext
    {
        return new DeployContext(
            $this->projectRoot,
            $this->target,
            $this->shell,
            $this->output,
            $this->operator,
            $this->layout,
            $this->ssh,
            $this->scp,
            $this->remote,
            $this->snapshots,
            $this->transfer,
            $this->preflight,
            $this->publisher,
            $this->verifier,
            $this->events,
            $this->maintenance,
            $this->rules,
        );
    }


    private function cleanUp(DeployContext $context): void
    {
        if ($context->releaseArchive !== null && ! FileSystem::tryDelete($context->releaseArchive)) {
            $this->out('warning: could not remove temporary release archive');
        }

        if ($context->build !== null) {
            try {
                FileSystem::removeDir($context->build['dir']);
            } catch (Throwable $cleanup) {
                $this->out('warning: could not remove build directory: '. $cleanup->getMessage());
            }
        }
    }


    protected function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
