<?php declare(strict_types=1);

namespace Rush\Tool\Code\Support;

use Rush\Tool\Code\Exception\CodeException;


final class Resources
{
    public static function path(string $relative): string
    {
        return dirname(__DIR__, 2) .'/resources/'. ltrim($relative, '/');
    }


    public static function read(string $relative): string
    {
        $path = str_starts_with($relative, '/')
            ? $relative
            : self::path($relative);

        if ( ! is_file($path) || ! is_readable($path)) {
            throw new CodeException("resource not found or unreadable: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new CodeException("could not read resource: {$path}");
        }

        return $contents;
    }
}
