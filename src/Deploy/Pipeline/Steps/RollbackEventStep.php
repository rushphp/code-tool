<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class RollbackEventStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->backupTimestamp === null || $context->restored === null) {
            throw new DeployException('rollback event requires a restored backup');
        }

        $release = $context->restored['previousReleaseId'] ?? $context->restored['releaseId'] ?? 'unknown';

        $context->events->write(
            'rollback',
            is_string($release) ? $release : 'unknown',
            null,
            null,
            $context->backupTimestamp,
        );

        $context->out('rollback complete');
    }
}
