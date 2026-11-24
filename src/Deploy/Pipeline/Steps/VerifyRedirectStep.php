<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;


final class VerifyRedirectStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->redirectUrl === null) {
            throw new DeployException('redirect verification requires a redirect URL');
        }

        $context->verifier->assertRedirect($context->target->url, $context->redirectUrl);
    }
}
