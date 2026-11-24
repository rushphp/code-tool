<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class RestoreStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->backupTimestamp === null) {
            throw new DeployException('restore requires a backup timestamp');
        }

        $restored = $context->snapshots->restore($context->backupTimestamp);
        $context->restored = $restored;

        $previousReleaseId = $restored['previousReleaseId'] ?? null;

        if (is_string($previousReleaseId) && $previousReleaseId !== '') {
            $context->remote->writeCurrentReleaseId($previousReleaseId);

            return;
        }

        $context->remote->deleteCurrent();
    }
}
