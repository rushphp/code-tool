<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;


final class SnapshotCurrentStep implements Step
{
    public function run(DeployContext $context): void
    {
        $context->snapshot = $context->snapshots->create($context->current, null);
        $context->out('backup: '. $context->snapshot);
    }
}
