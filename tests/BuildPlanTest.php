<?php declare(strict_types=1);

use Rush\Tool\Code\Deploy\BuildPlan;
use Rush\Tool\Code\Layout;


test('requires the remote PHP for remote build platforms', function (): void {
    $layout = Layout::fromArray([
        'php' => ['min' => '8.3'],
        'publish' => [['from' => 'public', 'to' => '$webroot']],
        'entrypoint' => 'public/index.php',
        'maintenance' => ['type' => 'marker', 'path' => '$webroot/.maintenance'],
        'build' => ['platform' => 'remote'],
    ], '/tmp/project');

    expect(fn () => (new BuildPlan($layout))->platform(null))
        ->toThrow(RuntimeException::class, 'remote PHP version is required');
});
