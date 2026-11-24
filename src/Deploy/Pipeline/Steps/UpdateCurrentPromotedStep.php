<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class UpdateCurrentPromotedStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null || $context->snapshot === null || $context->promotedFrom === null) {
            throw new DeployException('promoted current marker requires manifest, snapshot and source');
        }

        $context->remote->writeCurrent(
            $context->manifest,
            $context->snapshot,
            'current',
            $context->previousReleaseId,
            $context->promotedFrom,
        );
    }
}
