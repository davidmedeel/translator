<?php

declare(strict_types=1);

namespace Medel\Translator\Cache;

/**
 * Portable cache backend: a single JSON file, fully loaded on first use and
 * flushed atomically (temp file + rename).
 *
 * Chosen automatically when pdo_sqlite is not available. Writes are buffered
 * and written once at the end of the request: rewriting the whole file on
 * every put() is quadratic (65 fragments = 65 rewrites = 424 ms).
 */
final class JsonCache implements CacheDriver
{
    /** @var string */
    private $path;

    /** @var int */
    private $ttl;

    /** @var array<string,array{value:string,expires:int}> */
    private $entries = [];

    /** @var bool */
    private $loaded = false;

    /** @var bool */
    private $writable = true;

    /** @var bool */
    private $dirty = false;

    public function __construct(string $path, int $ttl = 0)
    {
        $this->path = $path;
        $this->ttl = $ttl > 0 ? $ttl : 0;
        $this->load();
    }

    public function name(): string
    {
        return 'json';
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function get(string $key): ?string
    {
        $this->load();

        if (!isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];
        if ($entry['expires'] > 0 && $entry['expires'] < time()) {
            unset($this->entries[$key]);
            $this->persist();

            return null;
        }

        return $entry['value'];
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function put(string $key, string $value): void
    {
        $this->load();

        $this->entries[$key] = [
            'value' => $value,
            'expires' => $this->ttl > 0 ? time() + $this->ttl : 0,
        ];

        $this->dirty = true;
    }

    public function delete(string $key): void
    {
        $this->load();

        if (isset($this->entries[$key])) {
            unset($this->entries[$key]);
            $this->dirty = true;
        }
    }

    public function flush(): void
    {
        $this->loaded = true;
        $this->entries = [];
        $this->dirty = false;
        $this->persist();
    }

    /**
     * Writes the whole index to disk atomically.
     */
    private function persist(): void
    {
        if ($this->entries === []) {
            if (is_file($this->path)) {
                @unlink($this->path);
            }

            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->writable = false;

            return;
        }

        $payload = json_encode($this->entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $temp = $this->path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $payload, LOCK_EX) === false) {
            $this->writable = false;

            return;
        }

        if (!@rename($temp, $this->path)) {
            @unlink($temp);
            $this->writable = false;
        }
    }

    public function prune(): int
    {
        $this->load();

        $now = time();
        $removed = 0;

        foreach ($this->entries as $key => $entry) {
            if ($entry['expires'] > 0 && $entry['expires'] < $now) {
                unset($this->entries[$key]);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->dirty = false;
            $this->persist();
        }

        return $removed;
    }

    public function count(): int
    {
        $this->load();

        return count($this->entries);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * Writes the pending entries right now.
     *
     * The destructor does it automatically; call it explicitly when the cache
     * has to survive something else than a normal shutdown (a cron job, a CLI
     * worker, a test).
     */
    public function commit(): void
    {
        if ($this->dirty) {
            $this->dirty = false;
            $this->persist();
        }
    }

    /**
     * Writes the pending entries when the request ends.
     */
    public function __destruct()
    {
        $this->commit();
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!is_file($this->path)) {
            return;
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        foreach ($decoded as $key => $entry) {
            if (!is_array($entry) || !isset($entry['value'])) {
                continue;
            }

            $this->entries[(string) $key] = [
                'value' => (string) $entry['value'],
                'expires' => isset($entry['expires']) ? (int) $entry['expires'] : 0,
            ];
        }
    }
}