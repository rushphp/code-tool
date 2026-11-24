<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline;

use Rush\Contract\OutputInterface;
use Rush\Support\Operator;
use Rush\Tool\Code\Backup\SnapshotStore;
use Rush\Tool\Code\Deploy\PathRules;
use Rush\Tool\Code\Event\EventWriter;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Maintenance;
use Rush\Tool\Code\Preflight\Preflight;
use Rush\Tool\Code\Release\ReleaseManifest;
use Rush\Tool\Code\Release\ReleasePublisher;
use Rush\Tool\Code\Release\ReleaseStore;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Target;
use Rush\Tool\Code\Transport\Transfer;
use Rush\Tool\Code\Verify\Verifier;


final class DeployContext
{
    /** @var array<string, mixed> */
    public array $current = [];

    public bool $wasRedirected = false;

    public bool $hasRsync = false;

    public string $contentHash = '';

    public string $releaseId = '';

    /** @var array{version: string, source: string, git: null|array{commit: string, branch: string, dirty: bool}} */
    public array $version = ['version' => '0.0.0', 'source' => 'none', 'git' => null];

    public ?string $previousReleaseId = null;

    /** @var null|array{dir: string, vendor: string} */
    public ?array $build = null;

    public ?string $releaseArchive = null;

    public ?string $artifactHash = null;

    public ?ReleaseManifest $manifest = null;

    public ?string $snapshot = null;

    public bool $maintenanceActive = false;

    public ?ReleaseStore $store = null;

    public ?bool $artifactVerified = null;

    public ?string $promotedFrom = null;

    public ?string $promotedTo = null;

    public ?string $redirectUrl = null;

    /** @var null|array{index: string, htaccess: string} */
    public ?array $stub = null;

    public ?string $backupTimestamp = null;

    /** @var null|array<string, mixed> */
    public ?array $restored = null;

    public function __construct(
        public readonly string $projectRoot,
        public readonly Target $target,
        public readonly Shell $shell,
        public readonly OutputInterface $output,
        public readonly Operator $operator,
        public readonly Layout $layout,
        public readonly Ssh $ssh,
        public readonly Scp $scp,
        public readonly RemoteFiles $remote,
        public readonly SnapshotStore $snapshots,
        public readonly Transfer $transfer,
        public readonly Preflight $preflight,
        public readonly ReleasePublisher $publisher,
        public readonly Verifier $verifier,
        public readonly EventWriter $events,
        public readonly Maintenance $maintenance,
        public readonly PathRules $rules,
    ) {
    }


    public function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
