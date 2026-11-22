<?php declare(strict_types=1);

use Rush\Support\FileSystem;
use Rush\Tool\Code\PresetRepository;


function presetRoot(): string
{
    $root = sys_get_temp_dir() .'/code-presets-'. bin2hex(random_bytes(6));
    mkdir($root .'/.rush/code/presets', 0700, true);

    return $root;
}


test('discovers local and installed presets', function (): void {
    $root = presetRoot();
    $packaged = $root .'/vendor/rushphp/preset-pack/layouts/packaged.json';

    mkdir(dirname($packaged), 0700, true);
    file_put_contents($packaged, '{}');
    file_put_contents($root .'/.rush/code/presets/slim.json', '{}');

    mkdir($root .'/vendor/composer', 0700, true);
    file_put_contents($root .'/vendor/composer/installed.json', json_encode([
        'packages' => [
            [
                'name' => 'vendor/preset-pack',
                'install-path' => '../rushphp/preset-pack',
                'extra' => ['rush' => ['code' => ['presets' => ['packaged' => 'layouts/packaged.json']]]],
            ],
            [
                'name' => 'vendor/no-presets',
                'install-path' => '../rushphp/no-presets',
            ],
            [
                'name' => 'vendor/missing-file',
                'install-path' => '../rushphp/missing-file',
                'extra' => ['rush' => ['code' => ['presets' => ['missing' => 'missing.json']]]],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $repository = new PresetRepository($root);
    $presets = $repository->all();

    expect(array_keys($presets))->toBe(['custom', 'packaged', 'slim'])
        ->and($presets['packaged']['source'])->toBe('package:vendor/preset-pack')
        ->and($presets['packaged']['path'])->toBe($packaged)
        ->and($presets['slim']['source'])->toBe('local')
        ->and($repository->resolve('packaged')['path'])->toBe($packaged);

    expect(fn () => $repository->resolve('unknown'))
        ->toThrow(RuntimeException::class, 'unknown preset');

    FileSystem::removeDir($root);
});


test('rejects duplicate installed presets', function (): void {
    $root = presetRoot();
    $first = $root .'/vendor/first/layout.json';
    $second = $root .'/vendor/second/layout.json';

    mkdir(dirname($first), 0700, true);
    mkdir(dirname($second), 0700, true);
    file_put_contents($first, '{}');
    file_put_contents($second, '{}');

    mkdir($root .'/vendor/composer', 0700, true);
    file_put_contents($root .'/vendor/composer/installed.json', json_encode([
        'packages' => [
            [
                'name' => 'vendor/first',
                'install-path' => '../first',
                'extra' => ['rush' => ['code' => ['presets' => ['shared' => 'layout.json']]]],
            ],
            [
                'name' => 'vendor/second',
                'install-path' => '../second',
                'extra' => ['rush' => ['code' => ['presets' => ['shared' => 'layout.json']]]],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => (new PresetRepository($root))->all())
        ->toThrow(RuntimeException::class, "preset 'shared' is provided by more than one package");

    FileSystem::removeDir($root);
});
