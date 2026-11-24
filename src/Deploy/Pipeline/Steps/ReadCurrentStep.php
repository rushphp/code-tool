<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;


final class ReadCurrentStep implements Step
{
    public function run(DeployContext $context): void
    {
        $context->current = $context->remote->readCurrent() ?? [];
        $context->wasRedirected = ($context->current['status'] ?? null) === 'redirected';

        $releaseId = $context->current['releaseId'] ?? null;
        $context->previousReleaseId = is_string($releaseId) && $releaseId !== '' ? $releaseId : null;
    }
}
