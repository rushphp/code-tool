<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class PromoteEventStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null || $context->snapshot === null || $context->promotedFrom === null) {
            throw new DeployException('promote event requires manifest, snapshot and source');
        }

        $context->events->write(
            'promote',
            $context->manifest->releaseId,
            $context->promotedFrom,
            $context->target->name,
            $context->snapshot,
            ['artifactVerified' => $context->artifactVerified],
        );
    }
}
