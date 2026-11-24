<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;


final class MaintenanceStep implements Step
{
    public function __construct(
        private readonly bool $enable,
    ) {
    }


    public function run(DeployContext $context): void
    {
        if ($this->enable) {
            $context->out('enabling maintenance');
            $context->maintenance->enable($context->target);
            $context->maintenanceActive = true;

            return;
        }

        $context->out('disabling maintenance');
        $context->maintenance->disable($context->target);
    }
}
