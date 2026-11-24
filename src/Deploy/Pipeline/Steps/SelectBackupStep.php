<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class SelectBackupStep implements Step
{
    public function __construct(
        private readonly ?string $timestamp,
    ) {
    }


    public function run(DeployContext $context): void
    {
        $backups = $context->snapshots->backups();
        $timestamp = $this->timestamp;

        if ($timestamp === null) {
            if ($backups === []) {
                throw new DeployException('no backup found');
            }

            $timestamp = $backups[0];
            $context->out('latest backup: '. $timestamp);
        }

        if (preg_match('/\A[0-9]{8}-[0-9]{6}\z/', $timestamp) !== 1) {
            throw new DeployException("invalid backup timestamp: {$timestamp}");
        }

        if ( ! in_array($timestamp, $backups, true)) {
            throw new DeployException("backup not found: {$timestamp}");
        }

        $context->backupTimestamp = $timestamp;
    }
}
