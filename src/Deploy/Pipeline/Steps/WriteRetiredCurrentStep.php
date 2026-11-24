<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Exception\DeployException;


final class WriteRetiredCurrentStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->redirectUrl === null || $context->promotedTo === null || $context->snapshot === null) {
            throw new DeployException('retired marker requires redirect target, destination and snapshot');
        }

        $context->remote->writeJson(DeployPaths::current($context->target), [
            'releaseId' => $context->current['releaseId'] ?? null,
            'contentHash' => $context->current['contentHash'] ?? null,
            'version' => $context->current['version'] ?? null,
            'status' => 'redirected',
            'redirectTo' => $context->redirectUrl,
            'promotedTo' => $context->promotedTo,
            'promotedAt' => gmdate('c'),
            'backup' => $context->snapshot,
            'previousReleaseId' => $context->current['previousReleaseId'] ?? null,
            'retirement' => [
                'files' => ['index.php', '.htaccess'],
                'redirectTo' => $context->redirectUrl,
            ],
        ]);
    }
}
