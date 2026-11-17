<?php declare(strict_types=1);

namespace Rush\Tool\Code\Support;


final class Paths
{
    public static function override(string $projectRoot, string $targetName, string $tree): string
    {
        return rtrim($projectRoot, '/') .'/.rush/code/override/'. $targetName .'/'. $tree;
    }
}
