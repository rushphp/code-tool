<?php declare(strict_types=1);

namespace Rush\Tool\Code;

use Rush\Tool\Code\Exception\LayoutException;
use JsonException;


final class Layout
{
    /**
     * @param list<array{from: string, to: string}> $publish
     * @param list<array{from: string, to: string}> $env
     * @param list<string> $writable
     * @param list<string> $exclude
     * @param list<string> $preserve
     * @param array{type: string, path?: string, down?: list<string>, up?: list<string>} $maintenance
     * @param array{composer: bool, include: list<string>, exclude: list<string>, flags: list<string>, platform: string} $build
     */
    private function __construct(
        private readonly string $projectRoot,
        private readonly string $phpMin,
        private readonly array $publish,
        private readonly array $env,
        private readonly array $writable,
        private readonly array $exclude,
        private readonly string $entrypoint,
        private readonly array $preserve,
        private readonly array $maintenance,
        private readonly array $build,
    ) {
    }


    public static function fromProject(string $projectRoot): self
    {
        $file = rtrim($projectRoot, '/') .'/code.layout.json';

        if ( ! is_file($file) || ! is_readable($file)) {
            throw new LayoutException(
                'layout not configured: create code.layout.json or run "rush code layout:init"',
            );
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new LayoutException("layout could not be read: {$file}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LayoutException('layout: invalid JSON: '. $exception->getMessage(), 0, $exception);
        }

        if ( ! is_array($data)) {
            throw new LayoutException('layout: code.layout.json must contain an object');
        }

        return self::fromArray($data, $projectRoot);
    }


    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data, string $projectRoot): self
    {
        $php = $data['php'] ?? null;

        if ( ! is_array($php) || ! is_string($php['min'] ?? null)) {
            throw new LayoutException("layout: 'php.min' is required");
        }

        $phpMin = trim($php['min']);

        if (preg_match('/\A\d+\.\d+(\.\d+)?\z/', $phpMin) !== 1) {
            throw new LayoutException("layout: 'php.min' must be a version like 8.3");
        }

        $publish = self::entries($data['publish'] ?? null, 'publish', required: true);
        $env = self::entries($data['env'] ?? [], 'env');

        $writable = self::stringList($data['writable'] ?? [], 'writable');

        foreach ($writable as $path) {
            self::assertRemotePath($path, 'writable');
        }

        $exclude = self::stringList($data['exclude'] ?? [], 'exclude');

        foreach ($exclude as $path) {
            self::assertRelativePattern($path, 'exclude');
        }

        foreach ($env as $entry) {
            if ( ! in_array($entry['from'], $exclude, true)) {
                $exclude[] = $entry['from'];
            }
        }

        if ( ! in_array('.deploy', $exclude, true)) {
            $exclude[] = '.deploy';
        }

        $entrypoint = $data['entrypoint'] ?? null;

        if ( ! is_string($entrypoint) || trim($entrypoint) === '') {
            throw new LayoutException("layout: 'entrypoint' is required");
        }

        $entrypoint = trim($entrypoint);
        self::assertRelativePath($entrypoint, 'entrypoint');

        $preserve = self::stringList($data['preserve'] ?? [], 'preserve');

        foreach ($preserve as $pattern) {
            if ( ! str_starts_with($pattern, '/')) {
                throw new LayoutException("layout: 'preserve' items must start with '/'");
            }

            if (str_contains($pattern, '..')) {
                throw new LayoutException("layout: 'preserve' items must not contain '..'");
            }
        }

        $maintenance = self::parseMaintenance($data['maintenance'] ?? null);
        $build = self::parseBuild($data['build'] ?? null, $publish);

        return new self(
            rtrim($projectRoot, '/'),
            $phpMin,
            $publish,
            $env,
            $writable,
            $exclude,
            $entrypoint,
            $preserve,
            $maintenance,
            $build,
        );
    }


    public function phpMin(): string
    {
        return $this->phpMin;
    }


    /** @return list<array{from: string, to: string}> */
    public function publish(): array
    {
        return $this->publish;
    }


    /** @return list<array{from: string, to: string}> */
    public function env(): array
    {
        return $this->env;
    }


    /** @return list<string> */
    public function writable(): array
    {
        return $this->writable;
    }


    /** @return list<string> */
    public function exclude(): array
    {
        return $this->exclude;
    }


    public function entrypoint(): string
    {
        return $this->entrypoint;
    }


    /** @return list<string> */
    public function preserve(): array
    {
        return $this->preserve;
    }


    /** @return array{type: string, path?: string, down?: list<string>, up?: list<string>} */
    public function maintenance(): array
    {
        return $this->maintenance;
    }


    /** @return list<string> */
    public function maintenanceCommands(string $phase): array
    {
        $commands = $this->maintenance[$phase] ?? [];

        return is_array($commands) ? $commands : [];
    }


    public function maintenancePath(Target $target): ?string
    {
        $path = $this->maintenance['path'] ?? null;

        return is_string($path) ? $this->resolve($path, $target) : null;
    }


    /** @return array{composer: bool, include: list<string>, exclude: list<string>, flags: list<string>, platform: string} */
    public function build(): array
    {
        return $this->build;
    }


    public function usesRoot(): bool
    {
        foreach ([...$this->publish, ...$this->env] as $entry) {
            if (self::baseOf($entry['to']) === 'root') {
                return true;
            }
        }

        foreach ($this->writable as $path) {
            if (self::baseOf($path) === 'root') {
                return true;
            }
        }

        return false;
    }


    public function assertTargetCompatible(Target $target): void
    {
        if (($this->usesRoot() || $this->build['composer']) && $target->root === null) {
            throw new LayoutException("layout: target '{$target->name}' requires a 'root'");
        }
    }


    public function resolve(string $path, Target $target): string
    {
        [$base, $relative] = self::split($path);

        $root = $base === 'root' ? $target->root : $target->webroot;

        if ($root === null) {
            throw new LayoutException("layout: target '{$target->name}' has no 'root' for {$path}");
        }

        return rtrim($root, '/') . ($relative === '' ? '' : '/'. $relative);
    }


    /**
     * @return array{base: 'root'|'webroot', path: string}
     */
    public function destination(string $to): array
    {
        [$base, $relative] = self::split($to);

        return ['base' => $base, 'path' => $relative];
    }


    /**
     * @return list<string>
     */
    public function excludesFor(string $from): array
    {
        $excludes = [];
        $from = trim($from, '/');

        foreach ($this->exclude as $pattern) {
            $pattern = trim($pattern, '/');

            if ($pattern === $from) {
                $excludes[] = '*';
                continue;
            }

            if (str_starts_with($pattern, $from .'/')) {
                $excludes[] = substr($pattern, strlen($from) + 1);
            }
        }

        foreach ($this->protectedExcludesFor($from) as $exclude) {
            $excludes[] = $exclude;
        }

        return array_values(array_unique($excludes));
    }


    /** @return list<string> */
    private function protectedExcludesFor(string $from): array
    {
        $excludes = [];

        foreach ($this->publish as $entry) {
            if (trim($entry['from'], '/') !== $from) {
                continue;
            }

            $destination = rtrim($entry['to'], '/');

            foreach ([...$this->writable, ...array_map(
                static fn (array $env): string => $env['to'],
                $this->env,
            )] as $protected) {
                $protected = rtrim($protected, '/');

                if ($protected === $destination) {
                    $excludes[] = '*';
                    continue;
                }

                if (str_starts_with($protected .'/', $destination .'/')) {
                    $excludes[] = substr($protected, strlen($destination) + 1);
                }
            }
        }

        return array_values(array_unique($excludes));
    }


    /**
     * @return list<array{from: string, to: string, path: string}>
     */
    public function publishFor(string $base): array
    {
        $result = [];

        foreach ($this->publish as $entry) {
            [$entryBase, $relative] = self::split($entry['to']);

            if ($entryBase !== $base) {
                continue;
            }

            $result[] = ['from' => $entry['from'], 'to' => $entry['to'], 'path' => $relative];
        }

        return $result;
    }


    /** @return list<string> */
    public function publishFrom(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $entry): string => $entry['from'],
            $this->publish,
        )));
    }


    public function fingerprint(): string
    {
        return 'sha256:'. hash('sha256', json_encode([
            'php' => $this->phpMin,
            'publish' => $this->publish,
            'env' => $this->env,
            'writable' => $this->writable,
            'exclude' => $this->exclude,
            'entrypoint' => $this->entrypoint,
            'preserve' => $this->preserve,
            'maintenance' => $this->maintenance,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }


    public function projectRoot(): string
    {
        return $this->projectRoot;
    }


    /**
     * @param mixed $value
     * @return list<array{from: string, to: string}>
     */
    private static function entries(mixed $value, string $key, bool $required = false): array
    {
        if ( ! is_array($value)) {
            if ($required) {
                throw new LayoutException("layout: '{$key}' is required");
            }

            return [];
        }

        $entries = [];

        foreach ($value as $entry) {
            if ( ! is_array($entry)) {
                throw new LayoutException("layout: '{$key}' entries must be objects");
            }

            $from = $entry['from'] ?? null;
            $to = $entry['to'] ?? null;

            if ( ! is_string($from) || trim($from) === '') {
                throw new LayoutException("layout: '{$key}.from' must be a non-empty string");
            }

            if ( ! is_string($to) || trim($to) === '') {
                throw new LayoutException("layout: '{$key}.to' must be a non-empty string");
            }

            $from = trim($from);
            $to = trim($to);

            self::assertRelativePath($from, "{$key}.from");
            self::assertRemotePath($to, "{$key}.to");

            $entries[] = ['from' => $from, 'to' => $to];
        }

        if ($required && $entries === []) {
            throw new LayoutException("layout: '{$key}' must not be empty");
        }

        return $entries;
    }


    /** @return list<string> */
    private static function stringList(mixed $value, string $key): array
    {
        if ( ! is_array($value)) {
            throw new LayoutException("layout: '{$key}' must be a list");
        }

        $list = [];

        foreach ($value as $item) {
            if ( ! is_string($item) || trim($item) === '') {
                throw new LayoutException("layout: '{$key}' items must be non-empty strings");
            }

            $list[] = trim($item);
        }

        return $list;
    }


    /**
     * @return array{type: string, path?: string, down?: list<string>, up?: list<string>}
     */
    private static function parseMaintenance(mixed $value): array
    {
        if ( ! is_array($value)) {
            throw new LayoutException("layout: 'maintenance' is required");
        }

        $type = $value['type'] ?? null;

        if ($type === 'marker') {
            $path = $value['path'] ?? null;

            if ( ! is_string($path) || trim($path) === '') {
                throw new LayoutException("layout: 'maintenance.path' is required for marker mode");
            }

            self::assertRemotePath(trim($path), 'maintenance.path');

            return ['type' => 'marker', 'path' => trim($path)];
        }

        if ($type === 'commands') {
            $down = self::commandList($value['down'] ?? null, 'maintenance.down');
            $up = self::commandList($value['up'] ?? null, 'maintenance.up');

            return ['type' => 'commands', 'down' => $down, 'up' => $up];
        }

        throw new LayoutException("layout: 'maintenance.type' must be 'marker' or 'commands'");
    }


    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function commandList(mixed $value, string $key): array
    {
        if ( ! is_array($value) || $value === []) {
            throw new LayoutException("layout: '{$key}' must be a non-empty list");
        }

        $commands = [];

        foreach ($value as $command) {
            if ( ! is_string($command) || trim($command) === '') {
                throw new LayoutException("layout: '{$key}' items must be non-empty strings");
            }

            $commands[] = trim($command);
        }

        return $commands;
    }


    /**
     * @param list<array{from: string, to: string}> $publish
     * @return array{composer: bool, include: list<string>, exclude: list<string>, flags: list<string>, platform: string}
     */
    private static function parseBuild(mixed $value, array $publish): array
    {
        if ($value === null) {
            $value = [];
        }

        if ( ! is_array($value)) {
            throw new LayoutException("layout: 'build' must be an object");
        }

        $composer = $value['composer'] ?? true;

        if ( ! is_bool($composer)) {
            throw new LayoutException("layout: 'build.composer' must be a boolean");
        }

        $include = self::stringList($value['include'] ?? [], 'build.include');
        $exclude = self::stringList($value['exclude'] ?? [], 'build.exclude');
        $flags = self::stringList($value['flags'] ?? ['--no-dev', '--classmap-authoritative'], 'build.flags');
        $platform = $value['platform'] ?? 'remote';

        if ( ! is_string($platform) || trim($platform) === '') {
            throw new LayoutException("layout: 'build.platform' must be 'remote' or a version");
        }

        $platform = trim($platform);

        if ($platform !== 'remote' && preg_match('/\A\d+\.\d+(\.\d+)?\z/', $platform) !== 1) {
            throw new LayoutException("layout: 'build.platform' must be 'remote' or a version like 8.3");
        }

        if ($include === [] && $composer) {
            $include = array_values(array_unique(array_map(
                static fn (array $entry): string => $entry['from'],
                array_filter(
                    $publish,
                    static fn (array $entry): bool => str_starts_with($entry['to'], '$root'),
                ),
            )));

            foreach (['composer.json', 'composer.lock'] as $file) {
                if ( ! in_array($file, $include, true)) {
                    $include[] = $file;
                }
            }
        }

        return [
            'composer' => $composer,
            'include' => $include,
            'exclude' => $exclude,
            'flags' => $flags,
            'platform' => $platform,
        ];
    }


    private static function assertRelativePath(string $path, string $label): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new LayoutException("layout: '{$label}' is invalid");
        }

        if (str_starts_with($path, '/') || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1) {
            throw new LayoutException("layout: '{$label}' must be a safe relative path");
        }
    }


    private static function assertRelativePattern(string $pattern, string $label): void
    {
        if ($pattern === '' || str_contains($pattern, "\0") || str_contains($pattern, '\\')) {
            throw new LayoutException("layout: '{$label}' is invalid");
        }

        if (str_starts_with($pattern, '/') || preg_match('#(^|/)\.{1,2}(/|$)#', $pattern) === 1) {
            throw new LayoutException("layout: '{$label}' must be a safe relative pattern");
        }
    }


    private static function assertRemotePath(string $path, string $label): void
    {
        [, $relative] = self::split($path);

        if ($relative !== '') {
            self::assertRelativePath($relative, $label);
        }
    }


    /**
     * @return array{0: 'root'|'webroot', 1: string}
     */
    private static function split(string $path): array
    {
        if ($path === '$root') {
            return ['root', ''];
        }

        if (str_starts_with($path, '$root/')) {
            return ['root', substr($path, 6)];
        }

        if ($path === '$webroot') {
            return ['webroot', ''];
        }

        if (str_starts_with($path, '$webroot/')) {
            return ['webroot', substr($path, 9)];
        }

        throw new LayoutException("layout: path must start with \$root or \$webroot: {$path}");
    }


    private static function baseOf(string $path): string
    {
        return self::split($path)[0];
    }
}
