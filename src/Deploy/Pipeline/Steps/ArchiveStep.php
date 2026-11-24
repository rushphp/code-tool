<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;


final class ArchiveStep implements Step
{
    public function run(DeployContext $context): void
    {
        $vendor = $context->build['vendor'] ?? null;
        $context->releaseArchive = $context->publisher->createArchive($vendor);
    }
}
