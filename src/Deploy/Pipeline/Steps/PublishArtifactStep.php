<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class PublishArtifactStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->manifest === null || $context->releaseArchive === null) {
            throw new DeployException('publish artifact requires a manifest and an archive');
        }

        $context->artifactVerified = $context->publisher->upload($context->manifest, $context->releaseArchive);
    }
}
