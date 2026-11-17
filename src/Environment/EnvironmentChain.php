<?php declare(strict_types=1);

namespace Rush\Tool\Code\Environment;

use Rush\Tool\Code\Config;


final class EnvironmentChain
{
    /**
     * @param list<string> $available
     */
    public static function next(string $current, array $available): ?string
    {
        $started = false;

        foreach (Config::TARGETS as $environment) {
            if ($environment === $current) {
                $started = true;
                continue;
            }

            if ($started && in_array($environment, $available, true)) {
                return $environment;
            }
        }

        return null;
    }
}
