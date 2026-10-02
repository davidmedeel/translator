<?php

declare(strict_types=1);

namespace Medel\Translator\Cache;

use PDO;
use PDOException;
use PDOStatement;

/**
 * High speed cache backend backed by a local SQLite file.
 *
 * Nothing is ever sent over the network: the database is a plain file inside
 * the configured cache directory.
 *
 * Performance notes (measured on a 4.7 KB HTML page, 60 fragments):
 * - WAL journalling plus synchronous=NORMAL, because this database is a pure
 *   cache: losing the last commit on a power cut costs one translation, and it
 *   takes a write from ~10 ms to ~0.05 ms on Windows.
 * - Statements are prepared once and reused.
 * - Read hits are counted in memory and flushed in a single UPDATE, so reading
 *   never touches the disk.
 */
final class SqliteCache implements CacheDriver
{
    /** Number of pending hit updates before they are flushed. */
    private const TOUCH_BATCH = 64;

    /** @var string */
    private $path;

    /** @var int */
    private $ttl;

    /** @var PDO|null */
    private $pdo;

    /** @var bool */
    private $failed = false;

    /** @var array<string,PDOStatement> */
    private $statements = [];

    /** @var array<int,string> */
    private $pendingHits = [];

    public function __construct(string $path, int $ttl = 0)
    {
        $this->path = $path;
        $this->ttl = $ttl > 0 ? $ttl : 0;
        $this->connect();
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * True when the driver is unusable (read only host, missing extension...).
     */
    public function isBroken(): bool
    {
        return $this->failed;
    }

    public function get(string $key): ?string
    {
        $pdo = $this->handle();
        if ($pdo === null) {
            return null;
        }

        try {
            $statement = $this->statement(
                'get',
                'SELECT value, expires_at FROM translations WHERE cache_key = :key LIMIT 1'
            );
            $statement->execute([':key' => $key]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if ($row === false || $row === null) {
                return null;
            }

            $expiresAt = (int) $row['expires_at'];
            if ($expiresAt > 0 && $expiresAt < time()) {
                $this->delete($key);

                return null;
            }

            $this->touch($key);

            return (string) $row['value'];
        } catch (PDOException $e) {
            $this->failed = true;

            return null;
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function put(string $key, string $value): void
    {
        $pdo = $this->handle();
        if ($pdo === null) {
            return;
        }

        $now = time();

        try {
            $statement = $this->statement(
                'put',
                'INSERT INTO translations (cache_key, value, hits, created_at, accessed_at, expires_at)
                 VALUES (:key, :value, 0, :now, :now, :expires)
                 ON CONFLICT(cache_key) DO UPDATE SET
                     value = excluded.value,
                     hits = 0,
                     accessed_at = excluded.accessed_at,
                     expires_at = excluded.expires_at'
            );
            $statement->execute([
                ':key' => $key,
                ':value' => $value,
                ':now' => $now,
                ':expires' => $this->ttl > 0 ? $now + $this->ttl : 0,
            ]);
        } catch (PDOException $e) {
            $this->failed = true;
        }
    }

    public function delete(string $key): void
    {
        $pdo = $this->handle();
        if ($pdo === null) {
            return;
        }

        try {
            $statement = $this->statement('delete', 'DELETE FROM translations WHERE cache_key = :key');
            $statement->execute([':key' => $key]);
        } catch (PDOException $e) {
            $this->failed = true;
        }
    }

    public function flush(): void
    {
        $pdo = $this->handle();
        if ($pdo === null) {
            return;
        }

        $this->pendingHits = [];

        try {
            $pdo->exec('DELETE FROM translations');
        } catch (PDOException $e) {
            $this->failed = true;
        }
    }

    public function prune(): int
    {
        $pdo = $this->handle();
        if ($pdo === null) {
            return 0;
        }

        try {
            $statement = $pdo->prepare('DELETE FROM translations WHERE expires_at > 0 AND expires_at < :now');
            $statement->execute([':now' => time()]);

            return $statement->rowCount();
        } catch (PDOException $e) {
            $this->failed = true;

            return 0;
        }
    }

    public function count(): int
    {
        $pdo = $this->handle();
        if ($pdo === null) {
            return 0;
        }

        try {
            $row = $pdo->query('SELECT COUNT(*) AS c FROM translations');

            if ($row === false) {
                return 0;
            }

            $data = $row->fetch(PDO::FETCH_ASSOC);

            return $data === false ? 0 : (int) $data['c'];
        } catch (PDOException $e) {
            $this->failed = true;

            return 0;
        }
    }

    /**
     * Rows ordered by usage, handy for debugging or manual pruning.
     *
     * @return array<int,array<string,mixed>>
     */
    public function stats(int $limit = 10): array
    {
        $pdo = $this->handle();
        if ($pdo === null) {
            return [];
        }

        try {
            $statement = $pdo->prepare(
                'SELECT cache_key, hits, created_at, accessed_at FROM translations
                 ORDER BY hits DESC, accessed_at DESC LIMIT :limit'
            );
            $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->failed = true;

            return [];
        }
    }

    /**
     * Counts a read hit in memory.
     *
     * Updating the row on every read would turn a cache lookup into a disk
     * write; the counters are flushed together instead.
     */
    private function touch(string $key): void
    {
        $this->pendingHits[$key] = ($this->pendingHits[$key] ?? 0) + 1;

        if (count($this->pendingHits) >= self::TOUCH_BATCH) {
            $this->flushHits();
        }
    }

    /**
     * Writes the pending hit counters in a single transaction.
     */
    private function flushHits(): void
    {
        if ($this->pendingHits === [] || $this->pdo === null) {
            $this->pendingHits = [];

            return;
        }

        $pending = $this->pendingHits;
        $this->pendingHits = [];

        try {
            $this->pdo->beginTransaction();

            $update = $this->pdo->prepare(
                'UPDATE translations SET hits = hits + :n, accessed_at = :now WHERE cache_key = :key'
            );
            $now = time();

            foreach ($pending as $key => $hits) {
                $update->execute([':n' => $hits, ':now' => $now, ':key' => $key]);
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo !== null && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    /**
     * Prepared statements are reused for the whole request.
     */
    private function statement(string $name, string $sql): PDOStatement
    {
        if (isset($this->statements[$name])) {
            return $this->statements[$name];
        }

        $pdo = $this->handle();
        if ($pdo === null) {
            throw new PDOException('cache handle unavailable');
        }

        $statement = $pdo->prepare($sql);
        $this->statements[$name] = $statement;

        return $statement;
    }

    /**
     * Flushes the counters when the object goes out of scope.
     */
    public function __destruct()
    {
        $this->flushHits();
    }

    private function handle(): ?PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->failed) {
            return null;
        }

        $this->connect();

        return $this->pdo;
    }

    private function connect(): void
    {
        if ($this->pdo !== null || $this->failed) {
            return;
        }

        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->failed = true;

            return;
        }

        try {
            $directory = dirname($this->path);
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->failed = true;

                return;
            }

            $pdo = new PDO('sqlite:' . $this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // A disposable cache does not need durability: WAL + NORMAL keeps
            // writes off the fsync path, which is the difference between
            // 10 ms and 0.05 ms per entry on a spinning disk or Windows.
            $pdo->exec('PRAGMA busy_timeout = 3000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA temp_store = MEMORY');
            $pdo->exec('PRAGMA cache_size = -8000');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS translations (
                    cache_key    TEXT PRIMARY KEY,
                    value        TEXT NOT NULL,
                    hits         INTEGER NOT NULL DEFAULT 0,
                    created_at   INTEGER NOT NULL,
                    accessed_at  INTEGER NOT NULL,
                    expires_at   INTEGER NOT NULL DEFAULT 0
                )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_translations_accessed ON translations (accessed_at)');

            $this->pdo = $pdo;
        } catch (PDOException $e) {
            $this->failed = true;
        }
    }
}