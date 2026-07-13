<?php

declare(strict_types=1);

namespace FenPing\Database;

use FenPing\Config\AppConfig;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseManager
{
    public const SCHEMA_VERSION = 6;
    private const BUSY_TIMEOUT_MS = 30000;

    private ?PDO $connection = null;

    public function __construct(private readonly AppConfig $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $directory = dirname($this->config->databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("failed to create database directory: $directory");
        }

        $database = new PDO('sqlite:' . $this->config->databasePath);
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('PRAGMA busy_timeout=' . self::BUSY_TIMEOUT_MS);
        $database->exec('PRAGMA foreign_keys=ON');
        $database->exec('PRAGMA temp_store=MEMORY');
        $database->exec('PRAGMA synchronous=NORMAL');
        $journal = strtolower((string) $database->query('PRAGMA journal_mode=WAL')->fetchColumn());
        if ($journal !== 'wal') {
            throw new RuntimeException("failed to enable SQLite WAL mode: $journal");
        }
        $database->sqliteCreateFunction('ipv4_num', self::ipv4Number(...), 1, PDO::SQLITE_DETERMINISTIC);
        return $this->connection = $database;
    }

    public function initialize(): void
    {
        $version = $this->schemaVersion();
        if ($version > self::SCHEMA_VERSION) {
            throw new RuntimeException("database schema version $version is newer than supported version " . self::SCHEMA_VERSION);
        }
        if ($version === 0) {
            $this->applyBaseSchema();
        } else {
            $this->applyMigrations(self::SCHEMA_VERSION);
        }
        $version = $this->schemaVersion();
        if ($version !== self::SCHEMA_VERSION) {
            throw new RuntimeException("database schema initialization stopped at version $version; expected " . self::SCHEMA_VERSION);
        }
    }

    public function beginImmediate(): void
    {
        $database = $this->connection();
        if ($database->inTransaction()) {
            throw new RuntimeException('database transaction already active');
        }
        $database->exec('BEGIN IMMEDIATE');
    }

    public function commit(): void
    {
        $database = $this->connection();
        if (!$database->inTransaction()) {
            throw new RuntimeException('database transaction is not active');
        }
        $database->exec('COMMIT');
    }

    public function rollback(): void
    {
        if ($this->connection()->inTransaction()) {
            $this->connection()->exec('ROLLBACK');
        }
    }

    public function immediate(callable $operation): mixed
    {
        $this->beginImmediate();
        try {
            $result = $operation($this->connection());
            $this->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function schemaVersion(): int
    {
        return (int) $this->connection()->query('PRAGMA user_version')->fetchColumn();
    }

    public function integrityErrors(): array
    {
        $errors = [];
        foreach ($this->connection()->query('PRAGMA integrity_check') as $row) {
            $message = (string) ($row['integrity_check'] ?? array_values($row)[0] ?? '');
            if ($message !== 'ok') {
                $errors[] = $message;
            }
        }
        foreach ($this->connection()->query('PRAGMA foreign_key_check') as $row) {
            $errors[] = 'foreign key: ' . implode(', ', array_map('strval', array_values($row)));
        }
        return $errors;
    }

    public static function ipv4Number(mixed $value): ?int
    {
        $ip = filter_var((string) $value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($ip === false) {
            return null;
        }
        $number = ip2long($ip);
        return $number === false ? null : (int) sprintf('%u', $number);
    }

    private function applyBaseSchema(): void
    {
        $schema = file_get_contents($this->config->projectDir . '/db.sql');
        if ($schema === false) {
            throw new RuntimeException('failed to read db.sql');
        }
        $this->immediate(function (PDO $database) use ($schema): void {
            $database->exec($schema);
            if ($this->schemaVersion() !== self::SCHEMA_VERSION) {
                throw new RuntimeException('db.sql created an unexpected schema version');
            }
        });
    }

    private function applyMigrations(int $targetVersion): void
    {
        $directory = $this->config->projectDir . '/migrations';
        $migrations = $this->migrationFiles($directory, $targetVersion);
        for ($version = $this->schemaVersion() + 1; $version <= $targetVersion; $version++) {
            if (!isset($migrations[$version])) {
                throw new RuntimeException("missing database migration for version $version");
            }
            $this->applyMigration($version, $migrations[$version]);
        }
    }

    private function migrationFiles(string $directory, int $targetVersion): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("database migrations directory not found: $directory");
        }
        $migrations = [];
        foreach (glob(rtrim($directory, '/') . '/*.sql') ?: [] as $path) {
            $filename = basename($path);
            if (!preg_match('/^(\d{4})_[a-z0-9][a-z0-9_-]*\.sql$/', $filename, $matches)) {
                throw new RuntimeException("invalid database migration filename: $filename");
            }
            $version = (int) $matches[1];
            if ($version < 2 || $version > $targetVersion || isset($migrations[$version])) {
                throw new RuntimeException("invalid database migration version: $filename");
            }
            $migrations[$version] = $path;
        }
        ksort($migrations, SORT_NUMERIC);
        return $migrations;
    }

    private function applyMigration(int $version, string $path): void
    {
        $filename = basename($path);
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("database migration is empty or unreadable: $filename");
        }
        $guardSql = preg_replace(['/--[^\r\n]*/', '/\/\*.*?\*\//s'], '', $sql);
        if (preg_match('/\bPRAGMA\s+user_version\b/i', (string) $guardSql)
            || preg_match('/(?:^|;)\s*(?:BEGIN|COMMIT|END|ROLLBACK|SAVEPOINT|RELEASE)\b/i', (string) $guardSql)) {
            throw new RuntimeException("database migration must not manage transactions or user_version: $filename");
        }
        try {
            $this->immediate(function (PDO $database) use ($sql, $version): void {
                $database->exec($sql);
                $database->exec('PRAGMA user_version=' . $version);
                if ($this->schemaVersion() !== $version) {
                    throw new RuntimeException("database migration did not reach version $version");
                }
            });
        } catch (Throwable $error) {
            throw new RuntimeException("database migration $filename failed: " . $error->getMessage(), 0, $error);
        }
    }
}
