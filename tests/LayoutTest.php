<?php declare(strict_types=1);

use Rush\Tool\Code\Layout;
use Rush\Tool\Code\SshAuth;
use Rush\Tool\Code\Target;
use Tests\DeployFixtures;


function layoutTarget(?string $root = '/srv/app'): Target
{
    return new Target(
        'staging',
        'staging.example.com',
        'deploy',
        22,
        new SshAuth(SshAuth::TYPE_KEY, __DIR__ .'/fixtures/auth-key'),
        '/srv/app/public_html',
        $root,
        $root,
        null,
        'https://staging.example.com',
        ['/.well-known/***'],
        3,
    );
}


test('requires code.layout.json', function (): void {
    $root = sys_get_temp_dir() .'/layout-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    expect(fn () => Layout::fromProject($root))
        ->toThrow(RuntimeException::class, 'layout not configured');

    \Rush\Support\FileSystem::removeDir($root);
});


test('loads the layout and resolves root and webroot paths', function (): void {
    $layout = DeployFixtures::layout('/tmp/project');
    $target = layoutTarget();

    expect($layout->phpMin())->toBe('8.3')
        ->and($layout->usesRoot())->toBeTrue()
        ->and($layout->resolve('$root/application', $target))->toBe('/srv/app/application')
        ->and($layout->resolve('$webroot', $target))->toBe('/srv/app/public_html')
        ->and($layout->publishFor('root'))->toHaveCount(3)
        ->and($layout->publishFor('webroot'))->toHaveCount(1);
});


test('public-only layouts do not require a target root', function (): void {
    $layout = Layout::fromArray([
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
        'build' => ['composer' => false],
    ], '/tmp/project');

    $layout->assertTargetCompatible(layoutTarget(null));

    expect($layout->usesRoot())->toBeFalse();
});


test('layouts using root require a target root', function (): void {
    $layout = DeployFixtures::layout('/tmp/project');

    expect(fn () => $layout->assertTargetCompatible(layoutTarget(null)))
        ->toThrow(RuntimeException::class, 'requires a');
});


test('composer builds require a target root', function (): void {
    $layout = Layout::fromArray([
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ], '/tmp/project');

    expect(fn () => $layout->assertTargetCompatible(layoutTarget(null)))
        ->toThrow(RuntimeException::class, 'requires a');
});


test('rejects unsafe publish paths', function (): void {
    expect(fn () => Layout::fromArray([
        'php' => ['min' => '8.3'],
        'publish' => [['from' => '../secret', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ], '/tmp/project'))->toThrow(RuntimeException::class);
});


test('parses maintenance commands', function (): void {
    $layout = Layout::fromArray([
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => [
            'type' => 'commands',
            'down' => ['php artisan down'],
            'up' => ['php artisan up'],
        ],
        'build' => ['composer' => false],
    ], '/tmp/project');

    expect($layout->maintenance()['type'])->toBe('commands')
        ->and($layout->maintenanceCommands('down'))->toBe(['php artisan down'])
        ->and($layout->maintenanceCommands('up'))->toBe(['php artisan up']);
});


test('rejects invalid layout definitions', function (array $layout): void {
    expect(fn () => Layout::fromArray($layout, '/tmp/project'))
        ->toThrow(RuntimeException::class);
})->with([
    'missing php' => [[
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ]],
    'invalid php' => [[
        'php' => ['min' => '8'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ]],
    'empty publish' => [[
        'php' => ['min' => '8.3'],
        'publish' => [],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ]],
    'unsafe source' => [[
        'php' => ['min' => '8.3'],
        'publish' => [['from' => '../secret', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ]],
    'unsafe destination' => [[
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot/../secret']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ]],
    'invalid env' => [[
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'env' => [['from' => '../env.json', 'to' => '$root/application/env.json']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ]],
    'invalid writable' => [[
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'writable' => ['/absolute/path'],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
    ]],
    'invalid maintenance' => [[
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'unknown'],
    ]],
    'invalid build platform' => [[
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
        'build' => ['platform' => 'latest'],
    ]],
]);


test('stateDir follows root when present and webroot otherwise', function (): void {
    expect(layoutTarget()->stateDir())->toBe('/srv/app/.deploy')
        ->and(layoutTarget(null)->stateDir())->toBe('/srv/app/public_html/.deploy');
});
