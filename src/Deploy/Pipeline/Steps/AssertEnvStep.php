<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;


final class AssertEnvStep implements Step
{
    public function run(DeployContext $context): void
    {
        $context->preflight->assertEnvExists();
    }
}
