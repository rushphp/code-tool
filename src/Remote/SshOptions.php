<?php declare(strict_types=1);

namespace Rush\Tool\Code\Remote;

use Rush\Tool\Code\Ssh;
use Rush\Tool\Code\Target;


final class SshOptions
{
    /** @return list<string> */
    public static function prefix(Target $target, string $binary): array
    {
        return $target->sshAuth->prefix($binary);
    }


    /** @return list<string> */
    public static function options(Target $target): array
    {
        return $target->sshAuth->options();
    }


    /** @return array<string, string> */
    public static function env(Target $target): array
    {
        return $target->sshAuth->env();
    }


    /** @return list<string> */
    public static function ssh(Target $target, string $remote): array
    {
        return [
            ...self::prefix($target, 'ssh'),
            '-p', (string) $target->sshPort,
            ...self::options($target),
            '-o', 'ConnectTimeout=15',
            $target->sshTarget(),
            $remote,
        ];
    }


    /** @return list<string> */
    public static function scp(Target $target, string $source, string $destination): array
    {
        return [
            ...self::prefix($target, 'scp'),
            '-P', (string) $target->sshPort,
            ...self::options($target),
            '-o', 'ConnectTimeout=15',
            $source,
            $destination,
        ];
    }


    public static function rsyncRemoteShell(Target $target): string
    {
        $parts = [
            ...self::prefix($target, 'ssh'),
            '-p', (string) $target->sshPort,
            ...self::options($target),
            '-o', 'ConnectTimeout=15',
        ];

        return implode(' ', array_map(
            static fn (string $part): string => Ssh::quote($part),
            $parts,
        ));
    }
}
