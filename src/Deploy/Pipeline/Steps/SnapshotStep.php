<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;


final class SnapshotStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null) {
            throw new \Rush\Tool\Code\Exception\DeployException('snapshot requires a release manifest');
        }

        $context->snapshot = $context->snapshots->create($context->manifest->toArray(), $context->previousReleaseId);
        $context->out('backup: '. $context->snapshot);
    }
}
