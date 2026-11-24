<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Ssh;


final class ReactivateStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ( ! $context->wasRedirected) {
            return;
        }

        $retirement = $context->current['retirement'] ?? null;
        $files = is_array($retirement) ? ($retirement['files'] ?? null) : null;

        if ( ! is_array($files) || $files === []) {
            return;
        }

        $commands = [];

        foreach ($files as $file) {
            if ( ! is_string($file) || $file === '' || $file === '.' || $file === '..'
                || str_contains($file, '/') || str_contains($file, '\\')) {
                continue;
            }

            $commands[] = 'rm -f '. Ssh::quote(rtrim($context->target->webroot, '/') .'/'. $file);
        }

        if ($commands !== []) {
            $context->out('reactivating retired target');
            $context->ssh->must($context->target, implode(' && ', $commands));
        }
    }
}
