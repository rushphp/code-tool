<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Release\ReleaseHasher;
use Rush\Tool\Code\Release\ReleaseId;


final class HashStep implements Step
{
    public function run(DeployContext $context): void
    {
        $context->contentHash = (new ReleaseHasher())->hash($context->projectRoot);
        $context->releaseId = ReleaseId::fromHash($context->contentHash);
    }
}
