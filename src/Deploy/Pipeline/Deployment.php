<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline;


final class Deployment
{
    /** @param list<Step> $steps */
    public function __construct(
        private readonly array $steps,
    ) {
    }


    public function run(DeployContext $context): DeployContext
    {
        foreach ($this->steps as $step) {
            $step->run($context);
        }

        return $context;
    }
}
