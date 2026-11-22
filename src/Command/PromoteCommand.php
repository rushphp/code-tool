<?php declare(strict_types=1);

namespace Rush\Tool\Code\Command;

use RuntimeException;
use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Config;
use Rush\Tool\Code\Environment\EnvironmentChain;
use Rush\Tool\Code\Promotion\Promoter;
use Rush\Support\FileSystem;


final class PromoteCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'code promote';
    }

    public function description(): string
    {
        return 'Promote the current release to the next environment';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('from', 'Source target to promote (development or staging)', true, null, true, 'target'),
            new Option('to', 'Destination target (defaults to the next environment)', true, null, false, 'target'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $from = $input->string('from');

        if ($from === null || $from === '') {
            throw new RuntimeException('--from is required');
        }

        $config = Config::load($this->projectRoot .'/.rush/code/config.json');
        $source = $config->target($from);
        $requested = $input->string('to');
        $requested = $requested === '' ? null : $requested;

        if ($requested !== null && ! in_array($requested, $config->names(), true)) {
            throw new RuntimeException("--to target is not configured: {$requested}");
        }

        $next = $requested ?? EnvironmentChain::next($from, $config->names());

        if ($next === null) {
            $output->info("nothing to promote from {$from}");

            return ExitCode::Success->value;
        }

        $fromIndex = array_search($from, Config::TARGETS, true);
        $toIndex = array_search($next, Config::TARGETS, true);

        if ($fromIndex === false || $toIndex === false || $toIndex <= $fromIndex) {
            throw new RuntimeException("cannot promote from {$from} to {$next}: destination must come later in the chain");
        }

        $destination = $config->target($next);
        $operator = $this->operator();
        $layout = $this->layout();
        $layout->assertTargetCompatible($source);
        $layout->assertTargetCompatible($destination);
        $sourcePromoter = new Promoter($this->projectRoot, $source, $this->shell(), $output, $operator, $layout);
        $destinationPromoter = new Promoter($this->projectRoot, $destination, $this->shell(), $output, $operator, $layout);
        $release = $sourcePromoter->export();

        try {
            $destinationPromoter->receive($release['archive'], $release['manifest'], $source->name);
            $sourcePromoter->retire($destination->url, $destination->name);
        } finally {
            if ($release['temporary']) {
                if ( ! FileSystem::tryDelete($release['archive'])) {
                    $output->warning('warning: could not remove temporary release archive');
                }

                if ( ! FileSystem::tryDelete(dirname($release['archive']) .'/manifest.json')) {
                    $output->warning('warning: could not remove temporary release manifest');
                }

                if ( ! FileSystem::tryDelete(dirname($release['archive']))) {
                    $output->warning('warning: could not remove temporary release directory');
                }
            }
        }

        $this->syncReleases($output);

        $output->success(
            "promoted {$release['manifest']->releaseId} from {$source->name} to {$destination->name}",
        );

        return ExitCode::Success->value;
    }
}
