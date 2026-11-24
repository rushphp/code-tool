<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;
use Rush\Tool\Code\Release\ArtifactVerifier;


final class ArtifactHashStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->releaseArchive === null) {
            throw new DeployException('artifact hash requires an archive');
        }

        $context->artifactHash = (new ArtifactVerifier())->hash($context->releaseArchive);
    }
}
