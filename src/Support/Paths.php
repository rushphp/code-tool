<?php declare(strict_types=1);

namespace Rush\Tool\Code\Support;

use Rush\Tool\Code\Deploy\DeployPaths;


final class Paths
{
    public static function override(string $projectRoot, string $targetName, string $tree): string
    {
        return DeployPaths::localOverride($projectRoot, $targetName, $tree);
    }
}
