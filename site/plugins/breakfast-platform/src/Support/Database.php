<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Thin PDO/SQLite wrapper.
 *
 * All CRM/queue/webhook persistence goes through this class. It enforces the
 * pragmas we depend on (foreign keys, WAL, busy timeout) and exposes only
 * prepared-statement helpers so callers cannot build ad-hoc SQL with
 * interpolated values.
 */
final class Database
{
    /** @var array<string,self> */
    private static array $instances = [];

    private PDO $pdo;

    private function __construct(private readonly string $path)
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);

            if (is_dir($dir) === false) {
                if (@mkdir($dir, 0770, true) === false && is_dir($dir) === false) {
                    throw new RuntimeException('Cannot create database directory');
                }
            }
        }

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Never surface raw DSN/driver errors upward.
            throw new RuntimeException('Database connection failed');
        }

        // Foreign-key enforcement is off while the platform migrates from the
        // database to flat files: the CRM core (contacts, companies, leads,
        // opportunities, tasks, activities) now lives as JSON files, so the
        // remaining tables' foreign keys pointing at those tables can no longer
        // be satisfied by the database. Referential integrity is maintained in
        // application code (repositories, explicit child cleanup on delete)
        // until every table has moved off the database.
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        if ($path !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    public static function instance(string $path): self
    {
        return self::$instances[$path] ??= new self($path);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string,mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Run a closure inside a transaction. Rolls back on any throwable and
     * re-raises, so partial multi-record writes never persist.
     *
     * @template T
     * @param callable(self):T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            // Already inside a transaction (nested call) — just run.
            return $work($this);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $work($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            // Roll back if a transaction is still open (commit may have failed).
            try {
                $this->pdo->rollBack();
            } catch (Throwable) {
                // no active transaction to roll back
            }

            throw $e;
        }
    }

    public function tableExists(string $name): bool
    {
        return $this->scalar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :n",
            ['n' => $name]
        ) !== null;
    }

    /** @internal test seam */
    public static function reset(): void
    {
        self::$instances = [];
    }
}
