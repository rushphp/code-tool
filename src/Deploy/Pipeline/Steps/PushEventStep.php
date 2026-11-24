<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class PushEventStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null || $context->snapshot === null) {
            throw new DeployException('push event requires a manifest and a snapshot');
        }

        $context->events->write('push', $context->manifest->releaseId, null, $context->target->name, $context->snapshot, [
            'artifactVerified' => $context->artifactVerified,
            'unretired' => $context->wasRedirected,
        ]);
    }
}
