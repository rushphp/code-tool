<?php declare(strict_types=1);

use Rush\Contract\ExitCode;
use Rush\Manager\Input;
use Rush\Tool\Code\Command\LayoutInitCommand;
use Rush\Tool\Code\Command\LayoutPresetsCommand;
use Rush\Tool\Code\Command\LayoutValidateCommand;
use Rush\Support\FileSystem;
use Tests\DeployFixtures;


function layoutCommandRoot(): string
{
    $root = sys_get_temp_dir() .'/layout-command-'. bin2hex(random_bytes(6));
    mkdir($root .'/public', 0700, true);
    file_put_contents($root .'/public/index.php', '<?php');

    return $root;
}


test('layout:init creates custom by default', function (): void {
    $root = layoutCommandRoot();
    $command = new LayoutInitCommand($root);

    $code = $command->run(Input::parse([], $command->options()), DeployFixtures::output());

    expect($code)->toBe(ExitCode::Success->value)
        ->and(is_file($root .'/code.layout.json'))->toBeTrue();

    FileSystem::removeDir($root);
});


test('layout:init refuses to overwrite without force', function (): void {
    $root = layoutCommandRoot();
    file_put_contents($root .'/code.layout.json', '{}');
    $command = new LayoutInitCommand($root);

    expect(fn () => $command->run(
        Input::parse([], $command->options()),
        DeployFixtures::output(),
    ))->toThrow(RuntimeException::class, '--force');

    FileSystem::removeDir($root);
});


test('layout:init resolves a local preset', function (): void {
    $root = layoutCommandRoot();
    mkdir($root .'/.rush/code/presets', 0700, true);
    file_put_contents(
        $root .'/.rush/code/presets/slim.json',
        json_encode(DeployFixtures::layoutArray(), JSON_THROW_ON_ERROR),
    );

    $command = new LayoutInitCommand($root);
    $code = $command->run(
        Input::parse(['--preset=slim'], $command->options()),
        DeployFixtures::output(),
    );

    expect($code)->toBe(ExitCode::Success->value)
        ->and(is_file($root .'/code.layout.json'))->toBeTrue();

    FileSystem::removeDir($root);
});


test('layout:presets lists custom and local presets', function (): void {
    $root = layoutCommandRoot();
    mkdir($root .'/.rush/code/presets', 0700, true);
    file_put_contents(
        $root .'/.rush/code/presets/slim.json',
        json_encode(DeployFixtures::layoutArray(), JSON_THROW_ON_ERROR),
    );

    $stream = fopen('php://memory', 'w+');

    if ($stream === false) {
        throw new RuntimeException('could not open output stream');
    }

    $command = new LayoutPresetsCommand($root);
    $command->run(Input::parse([], $command->options()), new \Rush\Manager\Output($stream));

    rewind($stream);
    $out = (string) stream_get_contents($stream);

    expect($out)->toContain('custom')
        ->and($out)->toContain('slim')
        ->and($out)->toContain('local');

    FileSystem::removeDir($root);
});


test('layout:validate accepts a valid layout', function (): void {
    $root = layoutCommandRoot();
    $command = new LayoutInitCommand($root);
    $command->run(Input::parse([], $command->options()), DeployFixtures::output());

    $validate = new LayoutValidateCommand($root);

    expect($validate->run(
        Input::parse([], $validate->options()),
        DeployFixtures::output(),
    ))->toBe(ExitCode::Success->value);

    FileSystem::removeDir($root);
});


test('layout:validate fails when a publish source is missing', function (): void {
    $root = layoutCommandRoot();
    file_put_contents(
        $root .'/code.layout.json',
        json_encode([
            'php' => ['min' => '8.3'],
            'publish' => [['from' => 'missing', 'to' => '$webroot']],
            'entrypoint' => 'public/index.php',
            'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
            'build' => ['composer' => false],
        ], JSON_THROW_ON_ERROR),
    );

    $command = new LayoutValidateCommand($root);

    expect($command->run(
        Input::parse([], $command->options()),
        DeployFixtures::output(),
    ))->toBe(ExitCode::CheckFailed->value);

    FileSystem::removeDir($root);
});
