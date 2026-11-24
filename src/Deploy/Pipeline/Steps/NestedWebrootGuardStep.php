<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class NestedWebrootGuardStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->target->nestedWebroots !== []) {
            throw new DeployException('cannot retire a target with nested webroots');
        }
    }
}
