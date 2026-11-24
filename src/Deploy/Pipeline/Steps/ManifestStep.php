<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;
use Rush\Tool\Code\Release\ReleaseManifest;


final class ManifestStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->artifactHash === null) {
            throw new DeployException('manifest requires the artifact hash');
        }

        $context->manifest = new ReleaseManifest(
            $context->releaseId,
            $context->contentHash,
            $context->version['version'],
            $context->version['source'],
            gmdate('c'),
            $context->version['git'],
            $context->previousReleaseId,
            $context->operator->toArray(),
            $context->artifactHash,
            $context->layout->fingerprint(),
        );
    }
}
