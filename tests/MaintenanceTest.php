<?php declare(strict_types=1);

use Rush\Tool\Code\Maintenance;
use Rush\Tool\Code\ShellResult;
use Rush\Tool\Code\Ssh;
use Tests\DeployFixtures;
use Tests\FakeShell;


function deployMaintenanceShell(): FakeShell
{
    return new FakeShell(function (array $command): ShellResult {
        $remote = $command[count($command) - 1];

        if (str_contains($remote, 'echo active'))
        {
            return new ShellResult(0, 'active', '');
        }

        return new ShellResult(0, '', '');
    });
}


test('enables maintenance by touching the marker', function () {
    $shell = deployMaintenanceShell();
    $maintenance = new Maintenance(new Ssh($shell), DeployFixtures::layout());

    $maintenance->enable(DeployFixtures::target());

    expect($shell->calls[0]['command'][count($shell->calls[0]['command']) - 1])
        ->toContain('touch')
        ->toContain('.maintenance');
});


test('disables maintenance by removing the marker', function () {
    $shell = deployMaintenanceShell();
    $maintenance = new Maintenance(new Ssh($shell), DeployFixtures::layout());

    $maintenance->disable(DeployFixtures::target());

    expect($shell->calls[0]['command'][count($shell->calls[0]['command']) - 1])
        ->toContain('rm -f')
        ->toContain('.maintenance');
});


test('reports the maintenance status', function () {
    $maintenance = new Maintenance(new Ssh(deployMaintenanceShell()), DeployFixtures::layout());

    expect($maintenance->status(DeployFixtures::target()))->toBe('active');
});


test('runs command-based maintenance hooks', function (): void {
    $layout = \Rush\Tool\Code\Layout::fromArray([
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

    $shell = deployMaintenanceShell();
    $maintenance = new Maintenance(new Ssh($shell), $layout);
    $target = DeployFixtures::target();

    $maintenance->enable($target);
    $maintenance->disable($target);

    expect($maintenance->status($target))->toBe('commands')
        ->and(DeployFixtures::calls($shell))->toContain('php artisan down')
        ->and(DeployFixtures::calls($shell))->toContain('php artisan up');
});
