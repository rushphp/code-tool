<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;
use Rush\Tool\Code\Ssh;


final class UploadExtractStep implements Step
{
    public function run(DeployContext $context): void
    {
        if ($context->releaseArchive === null) {
            throw new DeployException('upload requires a release archive');
        }

        $remoteArchive = DeployPaths::incoming($context->target, 'release');
        $context->scp->upload($context->releaseArchive, $remoteArchive);

        $target = $context->target;
        $commands = [];

        if ($target->root !== null) {
            $commands[] = $this->rootDeleteCommand($context);
            $commands[] = 'rm -rf '. Ssh::quote($target->root .'/vendor');

            if ($context->layout->publishFor('root') !== []) {
                $commands[] = 'tar -xzf '. Ssh::quote($remoteArchive)
                    .' -C '. Ssh::quote($target->root)
                    .' --strip-components=1 root';
            }
        }

        $commands[] = $context->rules->deleteContents(
            $target->webroot,
            $context->rules->webrootPreserve(),
        );

        if ($context->layout->publishFor('webroot') !== []) {
            $commands[] = 'tar -xzf '. Ssh::quote($remoteArchive)
                .' -C '. Ssh::quote($target->webroot)
                .' --strip-components=1 webroot';
        }

        $commands[] = 'rm -f '. Ssh::quote($remoteArchive);

        $context->ssh->must($target, implode(' && ', $commands));
    }


    private function rootDeleteCommand(DeployContext $context): string
    {
        $commands = [];

        foreach ($context->layout->publishFor('root') as $entry) {
            $destination = $context->layout->resolve($entry['to'], $context->target);
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

        return $commands === [] ? 'true' : implode(' && ', $commands);
    }
}
