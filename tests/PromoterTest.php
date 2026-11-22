<?php declare(strict_types=1);

use Rush\Support\FileSystem;
use Rush\Tool\Code\Promotion\Promoter;
use Rush\Tool\Code\Release\ReleaseManifest;
use Rush\Tool\Code\ShellResult;
use Tests\DeployFixtures;
use Tests\FakeShell;


function promoter(string $root, FakeShell $shell): Promoter
{
    return new Promoter(
        $root,
        DeployFixtures::target(),
        $shell,
        DeployFixtures::output(),
        DeployFixtures::operator(),
        DeployFixtures::layout($root),
    );
}


test('rejects a release built for another layout', function (): void {
    $root = DeployFixtures::workspace();
    $manifest = new ReleaseManifest(
        'abcdef123456-20261105T102030',
        'sha256:hash',
        '1.0.0',
        'version',
        gmdate('c'),
        null,
        null,
        [],
        null,
        'sha256:other',
    );

    expect(fn () => promoter($root, DeployFixtures::shell())->receive('archive', $manifest, 'staging'))
        ->toThrow(RuntimeException::class, 'release layout does not match');

    FileSystem::removeDir($root);
});


test('rejects retiring a source without a current release', function (): void {
    $root = DeployFixtures::workspace();

    expect(fn () => promoter($root, DeployFixtures::shell())->retire('https://staging.example.com', 'staging'))
        ->toThrow(RuntimeException::class, 'source has no current release');

    FileSystem::removeDir($root);
});


test('rejects retiring a source that is not current', function (): void {
    $root = DeployFixtures::workspace();
    $shell = new FakeShell(function (array $command): ShellResult {
        $remote = (string) $command[count($command) - 1];

        if ($command[0] === 'ssh' && str_contains($remote, 'current.json')) {
            return new ShellResult(0, json_encode([
                'releaseId' => 'abcdef123456-20261105T102030',
                'status' => 'redirected',
            ], JSON_THROW_ON_ERROR), '');
        }

        return new ShellResult(0, '', '');
    });

    expect(fn () => promoter($root, $shell)->retire('https://staging.example.com', 'staging'))
        ->toThrow(RuntimeException::class, 'source is not in current state');

    FileSystem::removeDir($root);
});
