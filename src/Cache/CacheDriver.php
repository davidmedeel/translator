<?php

declare(strict_types=1);

namespace Medel\Translator\Cache;

/**
 * Contract shared by every local cache backend.
 *
 * Values are always plain strings: a cache key maps to one already translated
 * fragment. Backends must never throw during a lookup, they degrade to a
 * "miss" instead.
 */
interface CacheDriver
{
    /**
     * Returns the cached value or null when absent/expired.
     */
    public function get(string $key): ?string;

    public function has(string $key): bool;

    public function put(string $key, string $value): void;

    public function delete(string $key): void;

    /**
     * Removes every entry.
     */
    public function flush(): void;

    /**
     * Drops expired entries and returns how many were removed.
     */
    public function prune(): int;

    public function count(): int;

    public function name(): string;

    public function path(): ?string;
}