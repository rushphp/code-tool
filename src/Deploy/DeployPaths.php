<?php declare(strict_types=1);

namespace Rush\Tool\Code\Deploy;

use Rush\Tool\Code\Target;


final class DeployPaths
{
    public static function remote(Target $target): string
    {
        return $target->stateDir();
    }


    public static function current(Target $target): string
    {
        return self::remote($target) .'/current.json';
    }


    public static function protection(Target $target): string
    {
        return self::remote($target) .'/.htaccess';
    }


    public static function releases(Target $target): string
    {
        return self::remote($target) .'/releases';
    }


    public static function release(Target $target, string $releaseId): string
    {
        return self::releases($target) .'/'. $releaseId;
    }


    public static function backups(Target $target): string
    {
        return self::remote($target) .'/backups';
    }


    public static function backup(Target $target, string $timestamp): string
    {
        return self::backups($target) .'/'. $timestamp;
    }


    public static function events(Target $target): string
    {
        return self::remote($target) .'/events';
    }


    public static function incoming(Target $target, string $kind): string
    {
        return self::remote($target) .'/incoming-'. $kind .'-'. bin2hex(random_bytes(4)) .'.tar.gz';
    }


    public static function local(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') .'/.rush/code';
    }


    public static function localRelease(string $projectRoot, string $releaseId): string
    {
        return self::local($projectRoot) .'/releases/'. $releaseId;
    }


    public static function localEvents(string $projectRoot): string
    {
        return self::local($projectRoot) .'/events';
    }


    public static function localOverride(string $projectRoot, string $target, string $from): string
    {
        return self::local($projectRoot) .'/override/'. $target .'/'. $from;
    }


    public static function localPresets(string $projectRoot): string
    {
        return self::local($projectRoot) .'/presets';
    }
}
