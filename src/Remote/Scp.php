<?php declare(strict_types=1);

namespace Rush\Tool\Code\Remote;

use Rush\Tool\Code\Shell;
use Rush\Tool\Code\Target;


final class Scp
{
    public function __construct(
        private readonly Target $target,
        private readonly Shell $shell,
    ) {
    }


    public function upload(string $local, string $remote): void
    {
        $this->shell->mustRun(
            $this->argv($local, $this->target->sshTarget() .':'. $remote),
            null,
            null,
            SshOptions::env($this->target),
        );
    }


    public function download(string $remote, string $local): void
    {
        $this->shell->mustRun(
            $this->argv($this->target->sshTarget() .':'. $remote, $local),
            null,
            null,
            SshOptions::env($this->target),
        );
    }


    /**
     * @return list<string>
     */
    private function argv(string $source, string $destination): array
    {
        return SshOptions::scp($this->target, $source, $destination);
    }
}
