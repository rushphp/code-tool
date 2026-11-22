<?php declare(strict_types=1);

use Tests\DeployFixtures;
use Tests\FakeShell;
use Rush\Tool\Code\Requirements\ComposerRequirements;
use Rush\Tool\Code\Requirements\WebProbe;


test('reads php and extension requirements from composer.json', function () {
    $root = sys_get_temp_dir() .'/deploy-requirements-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    file_put_contents($root .'/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'ext-pdo' => '*',
            'ext-pdo_sqlite' => '*',
        ],
        'require-dev' => [
            'ext-xdebug' => '*',
        ],
    ], JSON_THROW_ON_ERROR));

    $requirements = ComposerRequirements::fromFile($root .'/composer.json');

    expect($requirements->php)->toBe('>=8.2')
        ->and($requirements->extensions)->toBe(['pdo', 'pdo_sqlite']);

    $requirements->assert(['php' => '8.2.20', 'extensions' => ['pdo', 'pdo_sqlite']]);

    expect(fn () => $requirements->assert(['php' => '8.1.0', 'extensions' => ['pdo', 'pdo_sqlite']]))
        ->toThrow(RuntimeException::class, 'PHP >=8.2 required');

    expect(fn () => $requirements->assert(['php' => '8.2.20', 'extensions' => ['pdo']]))
        ->toThrow(RuntimeException::class, 'missing PHP extension pdo_sqlite');
});


test('web probe validates php and extensions and removes the probe', function () {
    $shell = new FakeShell(function (array $command, ?string $stdin = null): \Rush\Tool\Code\ShellResult {
        $last = (string) $command[count($command) - 1];

        if (str_contains($last, '_rush-check') && $command[0] === 'curl') {
            return new \Rush\Tool\Code\ShellResult(0, json_encode([
                'php' => '8.2.20',
                'extensions' => ['pdo', 'pdo_sqlite'],
            ], JSON_THROW_ON_ERROR), '');
        }

        return new \Rush\Tool\Code\ShellResult(0, '', '');
    });

    $probe = (new WebProbe())->inspect(DeployFixtures::target(), $shell);
    $calls = DeployFixtures::calls($shell);

    expect($probe)->toBe(['php' => '8.2.20', 'extensions' => ['pdo', 'pdo_sqlite']])
        ->and($calls)->toContain('cat >')
        ->and($calls)->toContain('rm -f');
});


test('checks supported composer PHP constraints', function (string $constraint, string $version, bool $ok): void {
    $requirements = new ComposerRequirements($constraint, []);

    if ($ok) {
        $requirements->assert(['php' => $version, 'extensions' => []]);
        expect(true)->toBeTrue();

        return;
    }

    expect(fn () => $requirements->assert(['php' => $version, 'extensions' => []]))
        ->toThrow(RuntimeException::class);
})->with([
    ['^8.3', '8.4.1', true],
    ['^8.3', '9.0.0', false],
    ['~8.3', '8.3.20', true],
    ['8.3.*', '8.4.0', false],
    ['>=8.3 <9', '8.4.0', true],
    ['*', '7.4.0', true],
    ['8.2', '8.1.9', false],
]);


test('rejects unsupported composer constraints and invalid require blocks', function (): void {
    $root = sys_get_temp_dir() .'/deploy-requirements-'. bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    expect(fn () => (new ComposerRequirements('^8.3 || ^8.4', []))->assert([
        'php' => '8.4.0',
        'extensions' => [],
    ]))->toThrow(RuntimeException::class, 'unsupported PHP constraint');

    expect(fn () => ComposerRequirements::fromFile($root .'/missing.json'))
        ->toThrow(RuntimeException::class, 'composer.json not found');

    file_put_contents($root .'/composer.json', '{');
    expect(fn () => ComposerRequirements::fromFile($root .'/composer.json'))
        ->toThrow(RuntimeException::class, 'invalid composer.json');

    file_put_contents($root .'/composer.json', json_encode(['require' => 'php'], JSON_THROW_ON_ERROR));
    expect(fn () => ComposerRequirements::fromFile($root .'/composer.json'))
        ->toThrow(RuntimeException::class, "'require' must be an object");

    \Rush\Support\FileSystem::removeDir($root);
});
