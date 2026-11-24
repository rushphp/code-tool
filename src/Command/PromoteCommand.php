<?php declare(strict_types=1);

namespace Rush\Tool\Code\Command;

use Rush\Tool\Code\Exception\ConfigException;
use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Config;
use Rush\Tool\Code\Environment\EnvironmentChain;
use Rush\Tool\Code\Promotion\Promoter;


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
            throw new ConfigException('--from is required');
        }

        $config = $this->config();
        $source = $config->target($from);
        $requested = $input->string('to');
        $requested = $requested === '' ? null : $requested;

        if ($requested !== null && ! in_array($requested, $config->names(), true)) {
            throw new ConfigException("--to target is not configured: {$requested}");
        }

        $next = $requested ?? EnvironmentChain::next($from, $config->names());

        if ($next === null) {
            $output->info("nothing to promote from {$from}");

            return ExitCode::Success->value;
        }

        $fromIndex = array_search($from, Config::TARGETS, true);
        $toIndex = array_search($next, Config::TARGETS, true);

        if ($fromIndex === false || $toIndex === false || $toIndex <= $fromIndex) {
            throw new ConfigException("cannot promote from {$from} to {$next}: destination must come later in the chain");
        }

        $destination = $config->target($next);
        $operator = $this->operator();
        $layout = $this->layout();
        $layout->assertTargetCompatible($source);
        $layout->assertTargetCompatible($destination);
        $sourcePromoter = new Promoter($this->projectRoot, $source, $this->shell(), $output, $operator, $layout);
        $destinationPromoter = new Promoter($this->projectRoot, $destination, $this->shell(), $output, $operator, $layout);
        $release = $sourcePromoter->exportRelease();

        try {
            $destinationPromoter->receive($release->archive, $release->manifest, $source->name);
            $sourcePromoter->retire($destination->url, $destination->name);
        } finally {
            $release->cleanup($output);
        }

        $this->syncReleases($output);

        $output->success(
            "promoted {$release->manifest->releaseId} from {$source->name} to {$destination->name}",
        );

        return ExitCode::Success->value;
    }
}
