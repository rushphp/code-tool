<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class PruneStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null) {
            throw new DeployException('prune requires a manifest');
        }

        $context->snapshots->pruneBackups();
        $context->snapshots->pruneReleases($context->manifest->releaseId);
        $context->events->prune($context->target->eventKeep);
    }
}
