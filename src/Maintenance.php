<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use Rush\Tool\Code\Exception\DeployException;


final class Maintenance
{
    public function __construct(
        private readonly Ssh $ssh,
        private readonly Layout $layout,
    ) {
    }


    public function enable(Target $target): void
    {
        if ($this->layout->maintenance()['type'] === 'commands') {
            foreach ($this->layout->maintenanceCommands('down') as $command) {
                $this->ssh->must($target, $command);
            }

            return;
        }

        $path = $this->markerPath($target);

        $this->ssh->must(
            $target,
            'test -d '. Ssh::quote(dirname($path))
            .' && touch '. Ssh::quote($path),
        );
    }


    public function disable(Target $target): void
    {
        if ($this->layout->maintenance()['type'] === 'commands') {
            foreach ($this->layout->maintenanceCommands('up') as $command) {
                $this->ssh->must($target, $command);
            }

            return;
        }

        $path = $this->markerPath($target);

        $this->ssh->must(
            $target,
            'rm -f '. Ssh::quote($path),
        );
    }


    public function status(Target $target): string
    {
        if ($this->layout->maintenance()['type'] === 'commands') {
            return 'commands';
        }

        $path = $this->markerPath($target);

        return $this->ssh->must(
            $target,
            'if [ -f '. Ssh::quote($path) .' ]; then echo active; else echo inactive; fi',
        )->output();
    }


    private function markerPath(Target $target): string
    {
        $path = $this->layout->maintenancePath($target);

        if ($path === null) {
            throw new DeployException('layout: maintenance marker path is missing');
        }

        return $path;
    }
}
