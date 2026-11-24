<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;
use Rush\Tool\Code\Retirement\RedirectStub;


final class WriteStubStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->redirectUrl === null) {
            throw new DeployException('redirect stub requires a redirect URL');
        }

        $stub = (new RedirectStub($context->projectRoot))->render($context->redirectUrl);
        $context->stub = $stub;

        $context->remote->writeFile($context->target->webroot .'/index.php', $stub['index']);
        $context->remote->writeFile($context->target->webroot .'/.htaccess', $stub['htaccess']);
    }
}
