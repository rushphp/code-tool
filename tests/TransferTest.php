<?php declare(strict_types=1);

use Rush\Tool\Code\Remote\Scp;
use Rush\Tool\Code\ShellResult;
use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\SshAuth;
use Rush\Tool\Code\Transport\Transfer;
use Rush\Support\FileSystem;
use Tests\DeployFixtures;
use Tests\FakeShell;


test('rsync applies password auth and env', function () {
    $root = sys_get_temp_dir() .'/transfer-'. bin2hex(random_bytes(6));
    $password = tempnam(sys_get_temp_dir(), 'transfer-password-');

    if ($password === false) {
        throw new RuntimeException('could not create password file');
    }

    file_put_contents($password, 'secret');
    mkdir($root .'/.rush/code/override/staging/public', 0700, true);
    file_put_contents($root .'/.rush/code/override/staging/public/index.php', 'test');
    DeployFixtures::writeLayout($root);

    $shell = new FakeShell(fn (): ShellResult => new ShellResult(0, '', ''));
    $target = DeployFixtures::target(auth: new SshAuth(SshAuth::TYPE_PASS, $password));
    $transfer = new Transfer(
        $root,
        $target,
        $shell,
        new Ssh($shell),
        new Scp($target, $shell),
        DeployFixtures::output(),
        DeployFixtures::layout($root),
    );

    try {
        $transfer->applyOverrides(true);

        $call = $shell->calls[0];
        $command = implode(' ', $call['command']);

        expect($call['command'])->toContain('rsync')
            ->and($call['env'])->toBe(['SSHPASS' => 'secret'])
            ->and($command)->toContain('sshpass')
            ->and($command)->toContain('StrictHostKeyChecking=accept-new');
    } finally {
        FileSystem::removeDir($root);
        unlink($password);
    }
});
