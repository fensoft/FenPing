<?php

declare(strict_types=1);

namespace FenPing\Database;

use FenPing\Config\AppConfig;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseManager
{
    public const SCHEMA_VERSION = 11;
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
            $legacyVersion = $this->detectLegacySchemaVersion();
            if ($legacyVersion === null) {
                $this->applyBaseSchema();
            } else {
                $this->setSchemaVersion($legacyVersion);
            }
        } elseif ($version >= 7
            && $this->detectLegacySchemaVersion() === 6
            && count($this->missingScanControlColumns()) === count($this->scanControlColumns())) {
            // Older unversioned databases were once stamped by the idempotent base
            // schema even though CREATE TABLE IF NOT EXISTS could not add columns.
            $this->setSchemaVersion(6);
        }
        if ($this->schemaVersion() === 8
            && count($this->missingHostMetadataColumns()) === count($this->hostMetadataColumns())
            && count($this->missingHostMetadataTables()) === count($this->hostMetadataTables())) {
            // A version-7 database could likewise be stamped by a newer
            // idempotent base schema without ALTER TABLE adding metadata.
            $this->setSchemaVersion(7);
        }
        if ($this->schemaVersion() === 9
            && !$this->hostDisplayNameColumnExists()
            && count($this->missingInventoryDeviceMetadataTables()) === count($this->inventoryDeviceMetadataTables())) {
            // A version-8 database could be stamped by the idempotent base
            // schema without ALTER TABLE adding discovery metadata support.
            $this->setSchemaVersion(8);
        }
        if ($this->schemaVersion() < self::SCHEMA_VERSION) {
            $this->applyMigrations($this->connection(), self::SCHEMA_VERSION, $this->config->projectDir . '/migrations');
        }
        $version = $this->schemaVersion();
        if ($version !== self::SCHEMA_VERSION) {
            throw new RuntimeException("database schema initialization stopped at version $version; expected " . self::SCHEMA_VERSION);
        }
        $missing = $this->missingScanControlColumns();
        if ($missing !== []) {
            throw new RuntimeException('database schema version 9 is missing scans columns: ' . implode(', ', $missing));
        }
        $missingMetadata = $this->missingHostMetadataColumns();
        if ($missingMetadata !== []) {
            throw new RuntimeException('database schema version 9 is missing ips columns: ' . implode(', ', $missingMetadata));
        }
        $missingMetadataTables = $this->missingHostMetadataTables();
        if ($missingMetadataTables !== []) {
            throw new RuntimeException('database schema version 9 is missing tables: ' . implode(', ', $missingMetadataTables));
        }
        if (!$this->hostDisplayNameColumnExists()) {
            throw new RuntimeException('database schema version 9 is missing ips column: display_name');
        }
        $missingInventoryMetadata = $this->missingInventoryDeviceMetadataTables();
        if ($missingInventoryMetadata !== []) {
            throw new RuntimeException('database schema version 9 is missing tables: ' . implode(', ', $missingInventoryMetadata));
        }
        if (!$this->tableExists('dns_override_groups')) {
            throw new RuntimeException('database schema version 10 is missing table: dns_override_groups');
        }
        if (!$this->tableExists('scheduled_report_settings') || !$this->tableExists('scheduled_report_runs')) {
            throw new RuntimeException('database schema version 11 is missing scheduled report tables');
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

    public function schemaVersion(?PDO $database = null): int
    {
        $database ??= $this->connection();
        return (int) $database->query('PRAGMA user_version')->fetchColumn();
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

    private function detectLegacySchemaVersion(): ?int
    {
        if (!$this->tableExists('ips') || !$this->tableExists('scans')) {
            return null;
        }
        if ($this->missingScanControlColumns() === []) {
            return 7;
        }

        $notificationColumns = $this->tableColumns('notification_delivery_settings');
        if ($this->tableExists('telegram_known_chats')
            && isset($notificationColumns['telegram_chat_id'], $notificationColumns['telegram_bot_fingerprint'])) {
            return 6;
        }
        if ($this->tableExists('notification_delivery_settings')) {
            return 5;
        }
        if ($this->tableExists('operation_status') && isset($this->tableColumns('scans')['queued_at'])) {
            return 4;
        }
        if ($this->tableExists('ip_conflicts')) {
            return 3;
        }

        $ips = $this->tableColumns('ips');
        $profileDefault = trim((string) ($ips['scan_profile']['dflt_value'] ?? ''), "'\"");
        $intervalDefault = trim((string) ($ips['scan_interval_hours']['dflt_value'] ?? ''), "'\"");
        return $profileDefault === 'standard' && $intervalDefault === '24' ? 2 : 1;
    }

    private function setSchemaVersion(int $version): void
    {
        $this->immediate(static function (PDO $database) use ($version): void {
            $database->exec('PRAGMA user_version=' . $version);
        });
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->connection()->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table",
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function tableColumns(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }
        $columns = [];
        foreach ($this->connection()->query('PRAGMA table_info(' . $table . ')') as $column) {
            $columns[(string) $column['name']] = $column;
        }
        return $columns;
    }

    private function scanControlColumns(): array
    {
        return [
            'network', 'request_source', 'progress_percent', 'progress_phase',
            'progress_updated_at', 'cancel_requested_at',
        ];
    }

    private function missingScanControlColumns(): array
    {
        $columns = $this->tableColumns('scans');
        return array_values(array_filter(
            $this->scanControlColumns(),
            static fn(string $column): bool => !isset($columns[$column]),
        ));
    }

    private function hostMetadataColumns(): array
    {
        return ['notes', 'location', 'owner', 'model', 'icon'];
    }

    private function missingHostMetadataColumns(): array
    {
        $columns = $this->tableColumns('ips');
        return array_values(array_filter(
            $this->hostMetadataColumns(),
            static fn(string $column): bool => !isset($columns[$column]),
        ));
    }

    private function hostMetadataTables(): array
    {
        return ['tags', 'host_tags', 'inventory_saved_filters', 'inventory_saved_filter_tags'];
    }

    private function missingHostMetadataTables(): array
    {
        return array_values(array_filter(
            $this->hostMetadataTables(),
            fn(string $table): bool => !$this->tableExists($table),
        ));
    }

    private function hostDisplayNameColumnExists(): bool
    {
        return isset($this->tableColumns('ips')['display_name']);
    }

    private function inventoryDeviceMetadataTables(): array
    {
        return ['inventory_device_metadata', 'inventory_device_tags'];
    }

    private function missingInventoryDeviceMetadataTables(): array
    {
        return array_values(array_filter(
            $this->inventoryDeviceMetadataTables(),
            fn(string $table): bool => !$this->tableExists($table),
        ));
    }

    public function applyMigrations(PDO $database, int $targetVersion, string $directory): void
    {
        $currentVersion = $this->schemaVersion($database);
        if ($currentVersion > $targetVersion) {
            throw new RuntimeException("database schema version $currentVersion is newer than supported version $targetVersion");
        }
        $migrations = $this->migrationFiles($directory, $targetVersion);
        for ($version = $currentVersion + 1; $version <= $targetVersion; $version++) {
            if (!isset($migrations[$version])) {
                throw new RuntimeException("missing database migration for version $version");
            }
            $this->applyMigration($database, $version, $migrations[$version]);
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

    private function applyMigration(PDO $database, int $version, string $path): void
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
        if ($database->inTransaction()) {
            throw new RuntimeException("database transaction already active");
        }
        $database->exec("BEGIN IMMEDIATE");
        try {
            $database->exec($sql);
            $database->exec("PRAGMA user_version=" . $version);
            if ($this->schemaVersion($database) !== $version) {
                throw new RuntimeException("database migration did not reach version $version");
            }
            $database->exec("COMMIT");
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->exec("ROLLBACK");
            }
            throw new RuntimeException("database migration $filename failed: " . $error->getMessage(), 0, $error);
        }
    }
}
