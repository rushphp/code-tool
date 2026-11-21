<?php declare(strict_types=1);

namespace Rush\Tool\Code\Command;

use RuntimeException;
use Rush\Contract\CommandInterface;
use Rush\Contract\InputInterface;
use Rush\Contract\OutputInterface;
use Rush\Tool\Code\Config;
use Rush\Tool\Code\Deployer;
use Rush\Tool\Code\Layout;
use Rush\Support\Operator;
use Rush\Support\OperatorFile;
use Rush\Tool\Code\Release\ReleaseSync;
use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Target;


abstract class CommandAbstract implements CommandInterface
{
    private ?Config $config = null;

    private ?Layout $layout = null;


    public function __construct(
        protected string $projectRoot,
        protected ?Shell $shell = null,
    ) {
    }


    public function usage(): string
    {
        $parts = ['rush', $this->name()];

        foreach ($this->options() as $option) {
            $label = '--'. $option->name;

            if ($option->takesValue) {
                $label .= '=<'. $option->valueName .'>';
            }

            $parts[] = $option->required ? $label : '['. $label .']';
        }

        return implode(' ', $parts);
    }



    /** @return list<\Rush\Contract\Option> */
    public function options(): array
    {
        return $this->commandOptions();
    }


    /** @return list<\Rush\Contract\Option> */
    protected function commandOptions(): array
    {
        return [];
    }


    protected function config(): Config
    {
        return $this->config ??= Config::load($this->projectRoot .'/.rush/code/config.json');
    }


    protected function layout(): Layout
    {
        return $this->layout ??= Layout::fromProject($this->projectRoot);
    }


    protected function operator(): Operator
    {
        return OperatorFile::load($this->projectRoot);
    }


    protected function target(InputInterface $input): Target
    {
        $name = $input->string('target');

        if ($name === null || $name === '') {
            throw new RuntimeException('--target is required');
        }

        return $this->config()->target($name);
    }


    protected function deployer(Target $target, OutputInterface $output): Deployer
    {
        $layout = $this->layout();
        $layout->assertTargetCompatible($target);

        return new Deployer(
            $this->projectRoot,
            $target,
            $this->shell(),
            $output,
            $this->operator(),
            $layout,
        );
    }


    protected function syncReleases(OutputInterface $output): bool
    {
        $results = (new ReleaseSync($this->projectRoot, $this->shell()))->syncAll($this->config());
        $ok = true;

        foreach ($results as $target => $result) {
            if ($result['ok']) {
                $output->line(
                    "[{$target}] sync ok ({$result['manifests']} manifests, {$result['events']} events)",
                );
                continue;
            }

            $ok = false;
            $output->warning("[{$target}] sync error: ". ($result['error'] ?? 'unknown error'));
        }

        return $ok;
    }


    protected function shell(): Shell
    {
        return $this->shell ??= new Shell();
    }
}
