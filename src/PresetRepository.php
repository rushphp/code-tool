<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use RuntimeException;


final class PresetRepository
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }


    /** @return array<string, array{source: string, path: string}> */
    public function all(): array
    {
        $presets = [
            'custom' => [
                'source' => 'built-in',
                'path' => dirname(__DIR__) .'/resources/layouts/custom.json',
            ],
        ];

        foreach ($this->installed() as $name => $entry) {
            if ($name === 'custom') {
                continue;
            }

            $presets[$name] = $entry;
        }

        foreach ($this->local() as $name => $entry) {
            if ($name === 'custom') {
                continue;
            }

            $presets[$name] = $entry;
        }

        ksort($presets);

        return $presets;
    }


    /** @return array{source: string, path: string} */
    public function resolve(string $name): array
    {
        $presets = $this->all();

        if ( ! isset($presets[$name])) {
            throw new RuntimeException(
                "unknown preset '{$name}'. available: ". implode(', ', array_keys($presets)),
            );
        }

        return $presets[$name];
    }


    /** @return array<string, array{source: string, path: string}> */
    private function local(): array
    {
        $presets = [];
        $files = glob(rtrim($this->projectRoot, '/') .'/.rush/code/presets/*.json') ?: [];

        foreach ($files as $file) {
            $name = basename($file, '.json');

            if ($name === '') {
                continue;
            }

            $presets[$name] = ['source' => 'local', 'path' => $file];
        }

        return $presets;
    }


    /** @return array<string, array{source: string, path: string}> */
    private function installed(): array
    {
        $file = rtrim($this->projectRoot, '/') .'/vendor/composer/installed.json';

        if ( ! is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        if ( ! is_array($data)) {
            return [];
        }

        $packages = $data['packages'] ?? $data;

        if ( ! is_array($packages)) {
            return [];
        }

        $presets = [];
        $base = dirname($file);

        foreach ($packages as $package) {
            if ( ! is_array($package)) {
                continue;
            }

            $packageExtra = $package['extra'] ?? null;
            $rush = is_array($packageExtra) ? ($packageExtra['rush'] ?? null) : null;
            $code = is_array($rush) ? ($rush['code'] ?? null) : null;
            $extra = is_array($code) ? ($code['presets'] ?? null) : null;
            $install = $package['install-path'] ?? null;

            if ( ! is_array($extra) || ! is_string($install)) {
                continue;
            }

            $directory = realpath($base .'/'. $install);

            if ($directory === false) {
                continue;
            }

            foreach ($extra as $name => $relative) {
                if ( ! is_string($name) || ! is_string($relative)) {
                    continue;
                }

                $path = realpath($directory .'/'. $relative);

                if ($path === false || ! is_file($path)) {
                    continue;
                }

                if (isset($presets[$name])) {
                    throw new RuntimeException("layout: preset '{$name}' is provided by more than one package");
                }

                $presets[$name] = [
                    'source' => 'package:'. (is_string($package['name'] ?? null) ? $package['name'] : 'unknown'),
                    'path' => $path,
                ];
            }
        }

        return $presets;
    }
}
