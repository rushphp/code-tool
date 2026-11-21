<?php declare(strict_types=1);

namespace Rush\Tool\Code\Command;

use Rush\Contract\ExitCode;
use Rush\Contract\InputInterface;
use Rush\Contract\Option;
use Rush\Contract\OutputInterface;
use Rush\Support\FileSystem;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\PresetRepository;


final class LayoutInitCommand extends CommandAbstract
{
    public function name(): string
    {
        return 'code layout:init';
    }

    public function description(): string
    {
        return 'Create code.layout.json from a preset';
    }

    /** @return list<Option> */
    protected function commandOptions(): array
    {
        return [
            new Option('preset', 'Preset name (default: custom)', true, 'custom', false, 'name'),
            new Option('force', 'Overwrite an existing code.layout.json'),
        ];
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $preset = $input->string('preset') ?? 'custom';
        $repository = new PresetRepository($this->projectRoot);
        $resolved = $repository->resolve($preset);
        $target = rtrim($this->projectRoot, '/') .'/code.layout.json';

        if (is_file($target) && ! $input->bool('force')) {
            throw new \RuntimeException('code.layout.json already exists; use --force to overwrite');
        }

        $raw = file_get_contents($resolved['path']);

        if ($raw === false) {
            throw new \RuntimeException("could not read preset: {$resolved['path']}");
        }

        $data = json_decode($raw, true);

        if ( ! is_array($data)) {
            throw new \RuntimeException("preset '{$preset}' is not a valid JSON object");
        }

        Layout::fromArray($data, $this->projectRoot);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) ."\n";

        FileSystem::write($target, $json, 0644);

        $output->success("created code.layout.json from preset '{$preset}'");
        $output->line('next:');
        $output->line('  edit code.layout.json');
        $output->line('  run: rush code layout:validate');

        return ExitCode::Success->value;
    }
}
