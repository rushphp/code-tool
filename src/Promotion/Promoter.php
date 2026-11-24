<?php declare(strict_types=1);

namespace Rush\Tool\Code\Promotion;

use Rush\Contract\OutputInterface;
use Rush\Support\Operator;
use Rush\Tool\Code\Backup\SnapshotStore;
use Rush\Tool\Code\Deploy\PathRules;
use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Deployment;
use Rush\Tool\Code\Deploy\Pipeline\RollbackGuard;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ApplyOverridesStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\AssertEnvStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\CheckStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\EnsureRemoteDirsStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\LayoutCompatibilityStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\MaintenanceStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\NestedWebrootGuardStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\PersistReleaseStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\PromoteEventStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\PruneStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\PublishArtifactStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ReadCurrentForRetireStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\ReadCurrentStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\RemoveSourceDeploymentStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\RetireEventStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\SnapshotCurrentStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\SnapshotStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\UpdateCurrentPromotedStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\UploadExtractStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\VerifyRedirectStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\VerifyStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\WriteRetiredCurrentStep;
use Rush\Tool\Code\Deploy\Pipeline\Steps\WriteStubStep;
use Rush\Tool\Code\Event\EventWriter;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Maintenance;
use Rush\Tool\Code\Preflight\Preflight;
use Rush\Tool\Code\Release\ReleaseExport;
use Rush\Tool\Code\Release\ReleaseExporter;
use Rush\Tool\Code\Release\ReleaseManifest;
use Rush\Tool\Code\Release\ReleasePublisher;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Target;
use Rush\Tool\Code\Transport\Transfer;
use Rush\Tool\Code\Verify\Verifier;
use Throwable;


final class Promoter
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

    private readonly PathRules $rules;


    public function __construct(
        private readonly string $projectRoot,
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly OutputInterface $output,
        private readonly Operator $operator,
        private readonly Layout $layout,
    ) {
        $this->ssh = new Ssh($shell);
        $this->maintenance = new Maintenance($this->ssh, $layout);
        $this->scp = new Scp($target, $shell);
        $this->remote = new RemoteFiles($target, $this->ssh);
        $this->snapshots = new SnapshotStore($target, $this->ssh, $layout);
        $this->transfer = new Transfer($projectRoot, $target, $shell, $this->ssh, $this->scp, $output, $layout);
        $this->preflight = new Preflight($projectRoot, $target, $shell, $this->ssh, $output, $layout);
        $this->publisher = new ReleasePublisher($projectRoot, $target, $shell, $this->ssh, $this->scp, $this->remote, $output, $layout);
        $this->verifier = new Verifier($target, $shell, $output);
        $this->events = new EventWriter($target, $operator, $output, $projectRoot, $shell);
        $this->rules = new PathRules($layout, $target);
    }


    /** @return array{archive: string, manifest: ReleaseManifest, temporary: bool} */
    public function export(): array
    {
        return (new ReleaseExporter($this->target, $this->ssh, $this->scp, $this->remote))->export();
    }


    public function exportRelease(): ReleaseExport
    {
        return (new ReleaseExporter($this->target, $this->ssh, $this->scp, $this->remote))->exportRelease();
    }


    public function receive(string $archive, ReleaseManifest $manifest, string $promotedFrom): void
    {
        $context = $this->context();
        $context->manifest = $manifest;
        $context->releaseArchive = $archive;
        $context->promotedFrom = $promotedFrom;

        try {
            (new Deployment([
                new LayoutCompatibilityStep(),
                new CheckStep(),
                new AssertEnvStep(),
                new ReadCurrentStep(),
                new MaintenanceStep(true),
                new EnsureRemoteDirsStep(),
                new SnapshotStep(),
                new UploadExtractStep(),
                new ApplyOverridesStep(),
                new MaintenanceStep(false),
                new VerifyStep(),
                new PersistReleaseStep(),
                new PublishArtifactStep(),
                new UpdateCurrentPromotedStep(),
                new PromoteEventStep(),
                new PruneStep(),
            ]))->run($context);

            $this->out('release: '. $manifest->releaseId .' ('. $manifest->version .')');
            $this->out('promote complete');
        } catch (Throwable $error) {
            RollbackGuard::recoverDeployment($context, $error);
            throw $error;
        }
    }


    public function retire(string $redirectUrl, string $promotedTo): void
    {
        $context = $this->context();
        $context->redirectUrl = $redirectUrl;
        $context->promotedTo = $promotedTo;

        try {
            (new Deployment([
                new NestedWebrootGuardStep(),
                new ReadCurrentForRetireStep(),
                new MaintenanceStep(true),
                new SnapshotCurrentStep(),
                new WriteStubStep(),
                new MaintenanceStep(false),
                new VerifyRedirectStep(),
                new RemoveSourceDeploymentStep(),
                new WriteRetiredCurrentStep(),
                new RetireEventStep(),
            ]))->run($context);

            $this->out('source retired: redirecting to '. $redirectUrl);
        } catch (Throwable $error) {
            RollbackGuard::recoverRetirement($context, $error);
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


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
