<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class LayoutCompatibilityStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null) {
            throw new DeployException('release manifest is required');
        }

        if ($context->manifest->layout !== null && $context->manifest->layout !== $context->layout->fingerprint()) {
            throw new DeployException('release layout does not match destination layout');
        }
    }
}
