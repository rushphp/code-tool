<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class RetireEventStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->snapshot === null || $context->promotedTo === null) {
            throw new DeployException('retire event requires a snapshot and destination');
        }

        $release = $context->current['releaseId'] ?? 'unknown';

        $context->events->write(
            'retire',
            is_string($release) ? $release : 'unknown',
            $context->target->name,
            $context->promotedTo,
            $context->snapshot,
        );
    }
}
