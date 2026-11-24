<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;
use Rush\Tool\Code\Release\ReleaseStore;


final class PersistReleaseStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null) {
            throw new DeployException('persist release requires a manifest');
        }

        $context->store = new ReleaseStore($context->projectRoot);
        $context->store->persist($context->manifest);
    }
}
