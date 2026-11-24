<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class UpdateCurrentStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null || $context->snapshot === null) {
            throw new DeployException('current marker requires a manifest and a snapshot');
        }

        $context->remote->writeCurrent($context->manifest, $context->snapshot, 'current');
    }
}
