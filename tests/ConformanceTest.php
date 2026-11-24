<?php declare(strict_types=1);

use Rush\Testing\Conformance\ToolConformance;
use Rush\Tool\Code\CodeTool;
use Rush\Tool\Code\Layout;
use Tests\DeployFixtures;


test('code tool satisfies the rush conformance rules', function (): void {
    $root = sys_get_temp_dir() .'/code-conformance-'. bin2hex(random_bytes(6));

    mkdir($root .'/public', 0700, true);
    file_put_contents($root .'/public/index.php', '<?php');
    DeployFixtures::writeLayout($root);

    try {
        ToolConformance::assert(new CodeTool(), $root);
        expect(true)->toBeTrue();
    } finally {
        \Rush\Support\FileSystem::removeDir($root);
    }
});


test('code tool exposes its layout commands scoped to its key', function (): void {
    $tool = new CodeTool();
    $commands = $tool->commands(sys_get_temp_dir());

    expect($tool->key())->toBe('code')
        ->and(array_keys($commands))->toContain('code push', 'code layout:init')
        ->and($commands['code push']->name())->toBe('code push');
});
