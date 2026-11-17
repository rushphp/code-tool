<?php declare(strict_types=1);

namespace Tests;

use Rush\Contract\CommandInterface;
use Rush\Contract\ToolInterface;
use Rush\Manager\Application;


final class CliFixtures
{
    /**
     * @param array<string, CommandInterface> $commands
     * @param list<string> $argv
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function run(array $commands, array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        if ($stdout === false || $stderr === false) {
            throw new \RuntimeException('could not open test streams');
        }

        $tool = new class ($commands) implements ToolInterface {
            /** @param array<string, CommandInterface> $commands */
            public function __construct(private readonly array $commands)
            {
            }

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
                return 'Code tool';
            }

            public function version(): string
            {
                return '1.2.3';
            }

            /** @return array<string, CommandInterface> */
            public function commands(string $projectRoot): array
            {
                return $this->commands;
            }

            /** @return list<string> */
            public function install(string $projectRoot): array
            {
                return [];
            }
        };

        $application = new Application(sys_get_temp_dir(), '1.2.3', $stdout, $stderr);
        $application->attach($tool);

        $code = $application->run($argv);

        rewind($stdout);
        rewind($stderr);

        return [
            'code' => $code,
            'stdout' => (string) stream_get_contents($stdout),
            'stderr' => (string) stream_get_contents($stderr),
        ];
    }
}
