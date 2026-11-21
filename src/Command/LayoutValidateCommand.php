<?php declare(strict_types=1);

namespace Rush\Tool\Code\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Layout;


final class LayoutValidateCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'code layout:validate';
    }

    public function description(): string
    {
        return 'Validate code.layout.json against the project';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $layout = Layout::fromProject($this->projectRoot);
        $errors = 0;

        if (version_compare(PHP_VERSION, $layout->phpMin(), '<')) {
            $output->error('✗ local PHP '. PHP_VERSION .' is below the required '. $layout->phpMin());
            $errors++;
        } else {
            $output->success('✓ local PHP '. PHP_VERSION .' meets '. $layout->phpMin());
        }

        foreach ($layout->publish() as $entry) {
            $path = rtrim($this->projectRoot, '/') .'/'. $entry['from'];

            if ( ! is_file($path) && ! is_dir($path)) {
                $output->error("✗ publish source missing: {$entry['from']}");
                $errors++;
                continue;
            }

            $output->success("✓ publish source: {$entry['from']}");
        }

        $entrypoint = rtrim($this->projectRoot, '/') .'/'. $layout->entrypoint();

        if ( ! is_file($entrypoint)) {
            $output->error("✗ entrypoint missing: {$layout->entrypoint()}");
            $errors++;
        } else {
            $output->success("✓ entrypoint: {$layout->entrypoint()}");
        }

        foreach ($layout->env() as $entry) {
            $path = rtrim($this->projectRoot, '/') .'/'. $entry['from'];

            if ( ! is_file($path)) {
                $output->warning("! env source not local (override expected): {$entry['from']}");
            }
        }

        foreach ($layout->build()['include'] as $include) {
            $path = rtrim($this->projectRoot, '/') .'/'. $include;

            if ( ! is_file($path) && ! is_dir($path)) {
                $output->error("✗ build include missing: {$include}");
                $errors++;
            }
        }

        if ($errors > 0) {
            $output->error("layout invalid: {$errors} error(s)");

            return ExitCode::CheckFailed->value;
        }

        $output->success('layout valid');

        return ExitCode::Success->value;
    }
}
