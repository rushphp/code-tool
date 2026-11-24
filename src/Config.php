<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use Rush\Tool\Code\Exception\ConfigException;
use JsonException;
use Rush\Tool\Code\Config\TargetFactory;
use Rush\Tool\Code\Config\TargetGraph;


final class Config
{
    /**
     * Ordered deploy targets. Promotion follows this exact order.
     *
     * @var list<string>
     */
    public const TARGETS = ['development', 'staging', 'production'];

    /** @var array<string, Target> */
    private array $targets = [];


    private function __construct()
    {
    }


    public static function load(string $file): self
    {
        if ( ! is_file($file) || ! is_readable($file))
        {
            throw new ConfigException("config not found or unreadable: {$file}");
        }

        $raw = file_get_contents($file);

        if ($raw === false)
        {
            throw new ConfigException("config could not be read: {$file}");
        }

        try
        {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $error)
        {
            throw new ConfigException('invalid config: '. $error->getMessage(), 0, $error);
        }

        if ( ! is_array($data))
        {
            throw new ConfigException('config must be a JSON object');
        }

        $targets = $data['targets'] ?? null;

        if ( ! is_array($targets) || $targets === [])
        {
            throw new ConfigException("config: 'targets' must be a non-empty object");
        }

        $defaults = $data['global'] ?? [];

        if ( ! is_array($defaults))
        {
            throw new ConfigException("config: 'global' must be an object");
        }

        $root = realpath(dirname($file));

        if ($root === false)
        {
            throw new ConfigException("config directory could not be resolved: {$file}");
        }

        $config = new self();

        foreach ($targets as $name => $target)
        {
            if ( ! is_string($name) || ! in_array($name, self::TARGETS, true))
            {
                throw new ConfigException(
                    "config: invalid target '{$name}'. use: ". implode(', ', self::TARGETS),
                );
            }

            if ( ! is_array($target))
            {
                throw new ConfigException("config: target '{$name}' must be an object");
            }

            $merged = self::merge($defaults, $target);
            $config->targets[$name] = TargetFactory::build($name, $merged, $root);
        }

        TargetGraph::assertUniqueAddresses($config->targets);
        $config->targets = TargetGraph::withNestedWebroots($config->targets);

        return $config;
    }


    public function target(string $name): Target
    {
        if ( ! isset($this->targets[$name]))
        {
            throw new ConfigException(
                "unknown target '{$name}'. available: ". implode(', ', $this->names()),
            );
        }

        return $this->targets[$name];
    }


    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->targets);
    }


    /**
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $target
     * @return array<array-key, mixed>
     */
    private static function merge(array $defaults, array $target): array
    {
        foreach ($target as $key => $value)
        {
            if ($key === 'auth') {
                $defaults[$key] = $value;
                continue;
            }

            if (
                is_array($value)
                && isset($defaults[$key])
                && is_array($defaults[$key])
                && self::isAssoc($value)
                && self::isAssoc($defaults[$key])
            ) {
                $defaults[$key] = self::merge($defaults[$key], $value);
                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }


    /**
     * @param array<array-key, mixed> $value
     */
    private static function isAssoc(array $value): bool
    {
        return $value === [] || ! array_is_list($value);
    }


}
