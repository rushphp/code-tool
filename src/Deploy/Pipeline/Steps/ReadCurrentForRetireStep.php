<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class ReadCurrentForRetireStep implements Step
{
    public function run(DeployContext $context): void
    {
        $current = $context->remote->readCurrent();

        if ($current === null) {
            throw new DeployException('source has no current release');
        }

        $status = $current['status'] ?? null;

        if ($status !== null && $status !== 'current') {
            throw new DeployException('source is not in current state');
        }

        $context->current = $current;
    }
}
