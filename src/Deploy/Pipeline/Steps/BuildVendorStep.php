<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy\Pipeline\Steps;

use Rush\Support\FileSystem;
use Rush\Tool\Code\Deploy\BuildPlan;
use Rush\Tool\Code\Deploy\Pipeline\DeployContext;
use Rush\Tool\Code\Deploy\Pipeline\Step;
use Rush\Tool\Code\Exception\DeployException;
use Rush\Tool\Code\Requirements\WebProbe;
use Throwable;


final class BuildVendorStep implements Step
{
    public function run(DeployContext $context): void
    {
        $plan = new BuildPlan($context->layout);

        if ( ! $plan->enabled()) {
            return;
        }

        $directory = FileSystem::temporaryDirectory();

        try {
            foreach ($plan->include() as $include) {
                $source = $context->projectRoot .'/'. $include;

                if (is_file($source)) {
                    FileSystem::copyFile($source, $directory .'/'. $include);
                    continue;
                }

                if ( ! is_dir($source)) {
                    throw new DeployException("build include missing: {$include}");
                }

                FileSystem::copyDir($source, $directory .'/'. $include, $plan->excludesFor($include));
            }

            foreach (['composer.json', 'composer.lock'] as $file) {
                if ( ! is_file($directory .'/'. $file)) {
                    throw new DeployException("missing build file: {$file}");
                }
            }

            $rawPlatform = $context->layout->build()['platform'];
            $remotePhp = $rawPlatform === 'remote'
                ? (new WebProbe())->inspect($context->target, $context->shell)['php']
                : null;
            $platform = $plan->platform($remotePhp);
            $result = $context->shell->run(['composer', 'config', 'platform.php', $platform], null, $directory);

            if ( ! $result->ok()) {
                throw new DeployException('composer failed:'. PHP_EOL . self::detail($result));
            }

            $command = ['composer', 'install', ...$plan->flags(), '--no-interaction', '--no-progress'];
            $context->out('build vendor: '. implode(' ', $command));

            $result = $context->shell->run($command, null, $directory);

            if ( ! $result->ok()) {
                throw new DeployException('composer failed:'. PHP_EOL . self::detail($result));
            }

            $vendor = $directory .'/vendor';

            if ( ! is_dir($vendor)) {
                throw new DeployException('composer did not generate the vendor directory');
            }

            $context->build = ['dir' => $directory, 'vendor' => $vendor];
        } catch (Throwable $error) {
            FileSystem::removeDir($directory);
            throw $error;
        }
    }


    private static function detail(\Rush\Tool\Code\ShellResult $result): string
    {
        return trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);
    }
}
