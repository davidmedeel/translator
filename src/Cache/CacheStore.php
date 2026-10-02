<?php

declare(strict_types=1);

namespace Medel\Translator\Cache;

/**
 * Local cache manager used by the engine.
 *
 * Responsibilities:
 *  - pick the best available backend (SQLite, JSON or in-memory only);
 *  - answer reads from an in-process layer first (0 ms, no disk access);
 *  - survive hostile environments: a read only folder, a missing extension or a
 *    corrupted file never breaks a translation, it just falls back to the next
 *    available layer.
 */
final class CacheStore
{
    public const DRIVER_AUTO = 'auto';
    public const DRIVER_SQLITE = 'sqlite';
    public const DRIVER_JSON = 'json';
    public const DRIVER_MEMORY = 'memory';
    public const DRIVER_NONE = 'none';

    /** @var CacheDriver|null */
    private $driver;

    /** @var string */
    private $driverName = self::DRIVER_NONE;

    /** @var string */
    private $directory;

    /** @var int */
    private $ttl;

    /** @var bool */
    private $enabled;

    /** @var array<string,string> */
    private $memory = [];

    /** @var array<string,bool> */
    private $knownMisses = [];

    /** @var int */
    private $hits = 0;

    /** @var int */
    private $misses = 0;

    /** @var int */
    private $writes = 0;

    /** @var array<int,string> */
    private $warnings = [];

    /**
     * @param array<string,mixed> $options
     *        driver:     auto|sqlite|json|memory|none
     *        path:       cache file (sqlite) or cache.json (json). When null a
     *                    writable location is discovered automatically.
     *        directory:  cache folder, used when "path" is not given
     *        enabled:    false disables persistence completely
     *        ttl:        seconds, 0 = never expires
     */
    public function __construct(array $options = [])
    {
        $this->enabled = !array_key_exists('enabled', $options) || (bool) $options['enabled'];
        $this->ttl = isset($options['ttl']) ? max(0, (int) $options['ttl']) : 0;

        $requested = isset($options['driver']) ? (string) $options['driver'] : self::DRIVER_AUTO;
        $path = isset($options['path']) && $options['path'] !== null ? (string) $options['path'] : null;

        if ($path === null) {
            $directory = isset($options['directory']) && $options['directory'] !== null
                ? (string) $options['directory']
                : self::defaultDirectory();
            $this->directory = rtrim($directory, "/\\");
            $path = $this->directory . DIRECTORY_SEPARATOR . 'translations.sqlite';
        } else {
            $this->directory = dirname($path);
        }

        if (!$this->enabled) {
            $this->driverName = self::DRIVER_NONE;

            return;
        }

        $this->boot($requested, $path);
    }

    /**
     * Resolves a cache value or null.
     */
    public function get(string $key): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        if (array_key_exists($key, $this->memory)) {
            $this->hits++;

            return $this->memory[$key];
        }

        if ($this->driver !== null) {
            $value = $this->driver->get($key);
            if ($value !== null) {
                $this->memory[$key] = $value;
                $this->hits++;

                return $value;
            }
        }

        if (isset($this->knownMisses[$key])) {
            $this->misses++;

            return null;
        }

        $this->knownMisses[$key] = true;
        $this->misses++;

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function put(string $key, string $value): void
    {
        $this->memory[$key] = $value;
        unset($this->knownMisses[$key]);

        if (!$this->enabled) {
            return;
        }

        if ($this->driver !== null) {
            $this->driver->put($key, $value);
            $this->writes++;
        }
    }

    /**
     * Returns the cached value, computing and storing it on the first miss.
     *
     * @param callable():string $producer
     */
    public function remember(string $key, callable $producer): string
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = (string) $producer();
        $this->put($key, $value);

        return $value;
    }

    public function delete(string $key): void
    {
        unset($this->memory[$key], $this->knownMisses[$key]);

        if ($this->driver !== null) {
            $this->driver->delete($key);
        }
    }

    /**
     * Empties the cache (disk + process memory).
     */
    public function flush(): void
    {
        $this->memory = [];
        $this->knownMisses = [];

        if ($this->driver !== null) {
            $this->driver->flush();
        }
    }

    public function prune(): int
    {
        return $this->driver !== null ? $this->driver->prune() : 0;
    }

    /**
     * Forces any buffered cache write to disk.
     *
     * The SQLite backend writes on the spot; the JSON one buffers until the
     * end of the request.
     */
    public function commit(): void
    {
        if ($this->driver !== null && method_exists($this->driver, 'commit')) {
            $this->driver->commit();
        }
    }

    public function count(): int
    {
        return $this->driver !== null ? $this->driver->count() : count($this->memory);
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    public function path(): ?string
    {
        return $this->driver !== null ? $this->driver->path() : null;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'driver' => $this->driverName,
            'path' => $this->path(),
            'entries' => $this->count(),
            'memory_entries' => count($this->memory),
            'hits' => $this->hits,
            'misses' => $this->misses,
            'writes' => $this->writes,
            'ttl' => $this->ttl,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * First writable cache folder: explicit env var, package var folder,
     * project storage or the system temp directory.
     */
    public static function defaultDirectory(): string
    {
        $env = getenv('MEDEL_TRANSLATOR_CACHE');
        if (is_string($env) && $env !== '') {
            return rtrim($env, "/\\");
        }

        $package = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';
        if (self::writableDirectory($package)) {
            return $package;
        }

        $project = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'translator-cache';
        if (self::writableDirectory($project)) {
            return $project;
        }

        return rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'medel-translator';
    }

    private function boot(string $requested, string $path): void
    {
        $sqlitePath = preg_match('/\.sqlite3?$/i', $path) === 1
            ? $path
            : $this->directory . DIRECTORY_SEPARATOR . 'translations.sqlite';
        $jsonPath = preg_match('/\.json$/i', $path) === 1
            ? $path
            : $this->directory . DIRECTORY_SEPARATOR . 'translations.json';

        if ($requested === self::DRIVER_SQLITE) {
            $this->bootSqlite($sqlitePath);

            return;
        }

        if ($requested === self::DRIVER_JSON) {
            $this->bootJson($jsonPath);

            return;
        }

        if ($requested === self::DRIVER_MEMORY || $requested === self::DRIVER_NONE) {
            $this->driverName = $requested === self::DRIVER_NONE ? self::DRIVER_NONE : self::DRIVER_MEMORY;

            return;
        }

        // auto
        if ($this->bootSqlite($sqlitePath)) {
            return;
        }

        if ($this->bootJson($jsonPath)) {
            return;
        }

        $this->driverName = self::DRIVER_MEMORY;
        $this->warnings[] = 'No writable cache folder found: running with an in-memory cache only.';
    }

    private function bootSqlite(string $path): bool
    {
        if (!class_exists(\PDO::class) || !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->warnings[] = 'ext-pdo_sqlite not available: the cache falls back to JSON.';

            return false;
        }

        $cache = new SqliteCache($path, $this->ttl);
        if ($cache->isBroken()) {
            $this->warnings[] = 'SQLite cache not writable at ' . $path . ': falling back to JSON.';

            return false;
        }

        $this->driver = $cache;
        $this->driverName = self::DRIVER_SQLITE;

        return true;
    }

    private function bootJson(string $path): bool
    {
        $cache = new JsonCache($path, $this->ttl);
        if (!$cache->isWritable() && !is_file($path)) {
            $this->warnings[] = 'JSON cache not writable at ' . $path . ': running with an in-memory cache.';

            return false;
        }

        $this->driver = $cache;
        $this->driverName = self::DRIVER_JSON;

        return true;
    }

    private static function writableDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        $parent = dirname($directory);
        if (!is_dir($parent) || !is_writable($parent)) {
            return false;
        }

        return @mkdir($directory, 0775, true) || is_dir($directory);
    }
}