<?php declare(strict_types=1);

namespace Rush\Tool\Code\Support;

use JsonException as NativeJsonException;
use Rush\Support\FileSystem;
use Rush\Tool\Code\Exception\JsonException;


final class Json
{
    public static function decode(string $raw, string $context): mixed
    {
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (NativeJsonException $exception) {
            throw new JsonException("{$context}: invalid JSON: ". $exception->getMessage(), 0, $exception);
        }
    }


    /** @return array<array-key, mixed> */
    public static function decodeObject(string $raw, string $context): array
    {
        $data = self::decode($raw, $context);

        if ( ! is_array($data)) {
            throw new JsonException("{$context}: expected a JSON object");
        }

        return $data;
    }


    public static function encode(mixed $value, string $context): string
    {
        try {
            return json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) ."\n";
        } catch (NativeJsonException $exception) {
            throw new JsonException("{$context}: could not encode JSON: ". $exception->getMessage(), 0, $exception);
        }
    }


    /** @return array<array-key, mixed> */
    public static function read(string $file, string $context): array
    {
        $raw = static::readRaw($file, $context);

        return self::decodeObject($raw, $context);
    }


    public static function readRaw(string $file, string $context): string
    {
        if ( ! is_file($file) || ! is_readable($file)) {
            throw new JsonException("{$context}: file not found or unreadable: {$file}");
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new JsonException("{$context}: could not read file: {$file}");
        }

        return $raw;
    }


    public static function write(string $file, mixed $value, int $mode = 0644): void
    {
        FileSystem::write($file, self::encode($value, $file), $mode);
    }
}
