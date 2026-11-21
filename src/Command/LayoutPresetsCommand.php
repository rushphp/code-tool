<?php declare(strict_types=1);

namespace Rush\Tool\Code\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\PresetRepository;


final class LayoutPresetsCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'code layout:presets';
    }

    public function description(): string
    {
        return 'List available layout presets';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $presets = (new PresetRepository($this->projectRoot))->all();

        $output->line(str_pad('PRESET', 16) .'SOURCE');

        foreach ($presets as $name => $preset) {
            $output->line(str_pad($name, 16) . $preset['source']);
        }

        return ExitCode::Success->value;
    }
}
