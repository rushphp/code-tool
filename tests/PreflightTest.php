<?php declare(strict_types=1);

use Rush\Support\FileSystem;
use Rush\Tool\Code\Layout;
use Rush\Tool\Code\Preflight\Preflight;
use Rush\Tool\Code\ShellResult;
use Rush\Tool\Code\Ssh;
use Tests\DeployFixtures;
use Tests\FakeShell;


function preflightRoot(bool $composer = true): string
{
    $root = sys_get_temp_dir() .'/code-preflight-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    if ($composer) {
        file_put_contents($root .'/composer.json', '{}');
        file_put_contents($root .'/composer.lock', '{}');
    }

    return $root;
}


function preflightShell(
    string $remotePhp = '8.3.20',
    string $deployStatus = '404',
    bool $stateExists = false,
): FakeShell {
    return new FakeShell(function (array $command) use ($remotePhp, $deployStatus, $stateExists): ShellResult {
        $remote = (string) $command[count($command) - 1];
        $binary = $command[0];

        if ($binary === 'ssh') {
            if (str_contains($remote, 'test -f') && str_contains($remote, 'current.json')) {
                return new ShellResult(0, $stateExists ? 'yes' : 'no', '');
            }

            if (str_contains($remote, 'command -v rsync')) {
                return new ShellResult(0, 'yes', '');
            }

            return new ShellResult(0, '', '');
        }

        if ($binary === 'curl') {
            if (str_contains($remote, '_rush-check')) {
                return new ShellResult(0, json_encode([
                    'php' => $remotePhp,
                    'extensions' => [],
                ], JSON_THROW_ON_ERROR), '');
            }

            if (str_contains($remote, '.deploy/current.json')) {
                return new ShellResult(0, $deployStatus, '');
            }

            return new ShellResult(0, '200', '');
        }

        return new ShellResult(0, '', '');
    });
}


function preflight(string $root, Layout $layout, FakeShell $shell, \Rush\Tool\Code\Target $target): Preflight
{
    return new Preflight($root, $target, $shell, new Ssh($shell), DeployFixtures::output(), $layout);
}


test('rejects a local PHP below the layout minimum', function (): void {
    $root = preflightRoot();
    $layout = Layout::fromArray([
        'php' => ['min' => '99.0'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
        'build' => ['composer' => false],
    ], $root);

    expect(fn () => preflight($root, $layout, preflightShell(), DeployFixtures::target())->check())
        ->toThrow(RuntimeException::class, 'local PHP');

    FileSystem::removeDir($root);
});


test('rejects a missing composer file when a build is enabled', function (): void {
    $root = preflightRoot(composer: false);
    $layout = DeployFixtures::layout($root);

    expect(fn () => preflight($root, $layout, preflightShell(), DeployFixtures::target())->check())
        ->toThrow(RuntimeException::class, 'missing file: composer.json');

    FileSystem::removeDir($root);
});


test('rejects a publicly accessible .deploy directory', function (): void {
    $root = preflightRoot(composer: false);
    $layout = Layout::fromArray([
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
        'build' => ['composer' => false],
    ], $root);
    $target = DeployFixtures::target(root: null);

    expect(fn () => preflight($root, $layout, preflightShell(deployStatus: '200', stateExists: true), $target)->check())
        ->toThrow(RuntimeException::class, '.deploy is publicly accessible');

    FileSystem::removeDir($root);
});


test('rejects a remote PHP below the layout minimum', function (): void {
    $root = preflightRoot();
    $layout = DeployFixtures::layout($root);

    expect(fn () => preflight($root, $layout, preflightShell(remotePhp: '8.2.19'), DeployFixtures::target())->check())
        ->toThrow(RuntimeException::class, 'remote PHP 8.2.19');

    FileSystem::removeDir($root);
});
