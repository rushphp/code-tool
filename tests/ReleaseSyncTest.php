<?php declare(strict_types=1);

use Rush\Support\FileSystem;
use Rush\Tool\Code\Release\ReleaseManifest;
use Rush\Tool\Code\Release\ReleaseStore;
use Rush\Tool\Code\Release\ReleaseSync;
use Rush\Tool\Code\ShellResult;
use Tests\DeployFixtures;
use Tests\FakeShell;


test('sync downloads only missing manifests and events', function (): void {
    $root = sys_get_temp_dir() .'/release-sync-'. bin2hex(random_bytes(6));
    $target = DeployFixtures::target();
    $store = new ReleaseStore($root);
    $existing = new ReleaseManifest(
        'aaaaaaaaaaaa-20261105T102030',
        'sha256:existing',
        '1.0.0',
        'version',
        '2026-11-05T10:20:30+00:00',
    );
    $store->persist($existing);
    mkdir($root .'/.rush/code/events', 0700, true);
    file_put_contents($root .'/.rush/code/events/existing.json', '{}');

    $shell = new FakeShell(function (array $command): ShellResult {
        $remote = (string) $command[count($command) - 1];

        if ($command[0] === 'ssh' && str_contains($remote, 'releases')) {
            return new ShellResult(0, implode("\n", [
                '/srv/app/.deploy/releases/aaaaaaaaaaaa-20261105T102030/manifest.json',
                '/srv/app/.deploy/releases/bbbbbbbbbbbb-20261106T102030/manifest.json',
            ]), '');
        }

        if ($command[0] === 'ssh' && str_contains($remote, 'events')) {
            return new ShellResult(0, implode("\n", [
                '/srv/app/.deploy/events/existing.json',
                '/srv/app/.deploy/events/new.json',
            ]), '');
        }

        return new ShellResult(0, '', '');
    });

    $result = (new ReleaseSync($root, $shell))->syncTarget($target);
    $calls = implode("\n", array_map(
        static fn (array $call): string => implode(' ', $call['command']),
        $shell->calls,
    ));

    expect($result)->toBe(['ok' => true, 'manifests' => 2, 'events' => 2, 'error' => null])
        ->and($calls)->toContain('bbbbbbbbbbbb-20261106T102030/manifest.json')
        ->and($calls)->not->toContain('aaaaaaaaaaaa-20261105T102030/manifest.json')
        ->and($calls)->toContain('new.json')
        ->and($calls)->not->toContain('existing.json');

    FileSystem::removeDir($root);
});
