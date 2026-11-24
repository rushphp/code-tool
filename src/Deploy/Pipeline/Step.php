<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline;


interface Step
{
    public function run(DeployContext $context): void;
}
