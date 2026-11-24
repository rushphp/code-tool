<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Deploy\ReleaseVersion;


final class VersionStep implements Step
{
    public function run(DeployContext $context): void
    {
        $context->version = (new ReleaseVersion())->resolve($context->projectRoot, $context->shell);
    }
}
