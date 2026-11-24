<?php declare(strict_types=1);

namespace Rush\Tool\Code\Retirement;

use Rush\Tool\Code\Deploy\DeployPaths;
use Rush\Tool\Code\Exception\DeployException;
use Rush\Tool\Code\Support\Resources;


final class RedirectStub
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }


    /** @return array{index: string, htaccess: string} */
    public function render(string $url): array
    {
        $index = $this->read('index.php');
        $htaccess = $this->read('.htaccess');

        if ( ! str_contains($index, '%url%')) {
            throw new DeployException('deploy stub index.php must contain %url%');
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $url);

        return [
            'index' => str_replace('%url%', $escaped, $index),
            'htaccess' => $htaccess,
        ];
    }


    private function read(string $file): string
    {
        $custom = DeployPaths::localStub($this->projectRoot) .'/'. $file;

        if (is_file($custom)) {
            return Resources::read($custom);
        }

        return Resources::read('stub/'. $file);
    }
}
