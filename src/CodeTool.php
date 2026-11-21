<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use Rush\Contract\CommandInterface;
use Rush\Contract\ToolInterface;
use Rush\Tool\Code\Command\CheckCommand;
use Rush\Tool\Code\Command\LayoutInitCommand;
use Rush\Tool\Code\Command\LayoutPresetsCommand;
use Rush\Tool\Code\Command\LayoutValidateCommand;
use Rush\Tool\Code\Command\PromoteCommand;
use Rush\Tool\Code\Command\PushCommand;
use Rush\Tool\Code\Command\ReleasesCommand;
use Rush\Tool\Code\Command\RollbackCommand;


final class CodeTool implements ToolInterface
{
    public const VERSION = '0.1.0';


    public function key(): string
    {
        return 'code';
    }

    public function name(): string
    {
        return 'code';
    }

    public function description(): string
    {
        return 'Shared-host code delivery tool';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** @return array<string, CommandInterface> */
    public function commands(string $projectRoot): array
    {
        return [
            'code check' => new CheckCommand($projectRoot),
            'code push' => new PushCommand($projectRoot),
            'code promote' => new PromoteCommand($projectRoot),
            'code releases' => new ReleasesCommand($projectRoot),
            'code rollback' => new RollbackCommand($projectRoot),
            'code layout:init' => new LayoutInitCommand($projectRoot),
            'code layout:presets' => new LayoutPresetsCommand($projectRoot),
            'code layout:validate' => new LayoutValidateCommand($projectRoot),
        ];
    }

    /** @return list<string> */
    public function install(string $projectRoot): array
    {
        return (new Initializer($projectRoot, layout: Layout::fromProject($projectRoot)))->run();
    }
}
