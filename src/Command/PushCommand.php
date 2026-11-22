<?php declare(strict_types=1);

namespace Rush\Tool\Code\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;


final class PushCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'code push';
    }

    public function description(): string
    {
        return 'Build a release, back up, transfer and verify a deployment';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('target', 'Target to operate on (development, staging, production)', true, null, true, 'target'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->deployer($this->target($input), $output)->push();
        $this->syncReleases($output);

        return ExitCode::Success->value;
    }
}
