<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use Rush\Tool\Code\Exception\TransportException;
use Rush\Tool\Code\Remote\SshOptions;


final class Ssh
{
    public function __construct(
        private readonly Shell $shell,
    ) {
    }


    /** @return list<string> */
    public function argv(Target $target, string $remote): array
    {
        return SshOptions::ssh($target, $remote);
    }


    public function run(Target $target, string $remote, ?string $stdin = null): ShellResult
    {
        return $this->shell->run(
            $this->argv($target, $remote),
            $stdin,
            null,
            SshOptions::env($target),
        );
    }


    public function must(Target $target, string $remote, ?string $stdin = null): ShellResult
    {
        $result = $this->run($target, $remote, $stdin);

        if ( ! $result->ok()) {
            $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

            throw new TransportException(
                "ssh {$target->sshTarget()} failed". ($detail !== '' ? ":\n". $detail : ''),
            );
        }

        return $result;
    }


    public static function quote(string $value): string
    {
        return Shell::quote($value);
    }
}
