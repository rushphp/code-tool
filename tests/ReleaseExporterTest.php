<?php declare(strict_types=1);

use Rush\Support\FileSystem;
use Rush\Tool\Code\Release\ReleaseExporter;
use Rush\Tool\Code\Remote\RemoteFiles;
use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\ShellResult;
use Rush\Tool\Code\Ssh;
use Tests\DeployFixtures;
use Tests\FakeShell;


function releaseExporter(
    FakeShell $shell,
    \Rush\Tool\Code\Target $target,
): ReleaseExporter {
    $ssh = new Ssh($shell);

    return new ReleaseExporter($target, $ssh, new Scp($target, $shell), new RemoteFiles($target, $ssh));
}


function releaseExporterShell(string $manifest, bool $available = true): FakeShell
{
    return new FakeShell(function (array $command) use ($manifest, $available): ShellResult {
        $remote = (string) $command[count($command) - 1];

        if ($command[0] === 'ssh') {
            if (str_contains($remote, 'current.json')) {
                return new ShellResult(0, json_encode([
                    'releaseId' => 'abcdef123456-20261105T102030',
                    'status' => 'current',
                ], JSON_THROW_ON_ERROR), '');
            }

            if (str_contains($remote, 'test -f')) {
                return new ShellResult($available ? 0 : 1, '', '');
            }

            return new ShellResult(0, '', '');
        }

        if ($command[0] === 'scp') {
            $destination = (string) $command[count($command) - 1];

            if (str_contains($remote, 'release.tar.gz')) {
                file_put_contents($destination, 'release');
            } else {
                file_put_contents($destination, $manifest);
            }
        }

        return new ShellResult(0, '', '');
    });
}


test('exports the current release with its manifest', function (): void {
    $manifest = json_encode([
        'releaseId' => 'abcdef123456-20261105T102030',
        'contentHash' => 'sha256:hash',
        'version' => '1.0.0',
        'source' => 'version',
        'createdAt' => '2026-11-05T10:20:30+00:00',
    ], JSON_THROW_ON_ERROR);

    $release = releaseExporter(releaseExporterShell($manifest), DeployFixtures::target())->export();

    expect($release['temporary'])->toBeTrue()
        ->and($release['manifest']->releaseId)->toBe('abcdef123456-20261105T102030')
        ->and(file_get_contents($release['archive']))->toBe('release');

    FileSystem::removeDir(dirname($release['archive']));
});


test('rejects missing source artifacts and invalid manifests', function (): void {
    $target = DeployFixtures::target();

    expect(fn () => releaseExporter(releaseExporterShell('{}', available: false), $target)->export())
        ->toThrow(RuntimeException::class, 'source release artifact not found');

    expect(fn () => releaseExporter(releaseExporterShell('{'), $target)->export())
        ->toThrow(RuntimeException::class, 'invalid downloaded release manifest');
});


test('rejects a source that is not current', function (): void {
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

    expect(fn () => releaseExporter($shell, DeployFixtures::target())->export())
        ->toThrow(RuntimeException::class, 'source is not in current state');
});


test('rejects an artifact whose hash does not match the manifest', function (): void {
    $manifest = json_encode([
        'releaseId' => 'abcdef123456-20261105T102030',
        'contentHash' => 'sha256:hash',
        'version' => '1.0.0',
        'source' => 'version',
        'createdAt' => '2026-11-05T10:20:30+00:00',
        'artifactHash' => 'sha256:wrong',
    ], JSON_THROW_ON_ERROR);

    expect(fn () => releaseExporter(releaseExporterShell($manifest), DeployFixtures::target())->export())
        ->toThrow(RuntimeException::class, 'artifact hash mismatch');
});
