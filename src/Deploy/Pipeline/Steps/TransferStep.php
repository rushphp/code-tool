<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;


final class TransferStep implements Step
{
    public function run(DeployContext $context): void
    {
        $vendor = $context->build['vendor'] ?? null;
        $context->transfer->run($vendor, $context->hasRsync);
    }
}
