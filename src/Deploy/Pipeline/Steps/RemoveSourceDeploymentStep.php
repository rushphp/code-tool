<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Ssh;


final class RemoveSourceDeploymentStep implements Step
{
    public function run(DeployContext $context): void
    {
        $target = $context->target;
        $commands = [];

        foreach ($context->layout->publishFor('root') as $entry) {
            $destination = $context->layout->resolve($entry['to'], $target);
            $source = rtrim($context->projectRoot, '/') .'/'. $entry['from'];

            if (is_dir($source)) {
                $commands[] = $context->rules->deleteContents(
                    $destination,
                    $context->rules->preserveInside($destination),
                );
                continue;
            }

            $commands[] = 'rm -f '. Ssh::quote($destination);
        }

        if ($target->root !== null) {
            $commands[] = 'rm -rf '. Ssh::quote($target->root .'/vendor');
        }

        $commands[] = $context->rules->deleteContents(
            $target->webroot,
            $context->rules->webrootPreserve(true),
        );

        $context->ssh->must($target, implode(' && ', $commands));
    }
}
