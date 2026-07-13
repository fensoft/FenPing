<?php

declare(strict_types=1);

namespace FenPing\Tests;

use PDO;
use RuntimeException;


final class DatabaseMigrationTest extends IntegrationTestCase
{
    public function testVersionOneDatabaseMigratesWithoutChangingExistingSchedules(): void
    {
        $database = $this->memoryDatabase();
        $database->exec("
          CREATE TABLE ips (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT COLLATE NOCASE UNIQUE,
            mac TEXT COLLATE NOCASE UNIQUE, ip TEXT UNIQUE, important INTEGER,
            repeater INTEGER, web INTEGER, router INTEGER, dns TEXT,
            netboot_image_id INTEGER, scan_profile TEXT NOT NULL DEFAULT 'deep',
            scan_interval_hours INTEGER NOT NULL DEFAULT 1
          );
          CREATE INDEX ips_netboot_image_id ON ips (netboot_image_id);
          CREATE TABLE scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            mode TEXT NOT NULL,
            state TEXT NOT NULL DEFAULT 'running',
            date_begin DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_end DATETIME
          );
          INSERT INTO ips (name, mac, ip, scan_profile, scan_interval_hours)
          VALUES ('Existing host', '02:00:00:00:00:40', '192.0.2.40', 'deep', 1);
          PRAGMA user_version=1;
        ");

        $this->app()->backend()->databaseApplyMigrations($database, \FenPing\Backend\Backend::DATABASE_SCHEMA_VERSION, $this->app()->config()->projectDir . '/migrations');
        self::assertSame(6, $this->app()->backend()->databaseSchemaVersion($database));
        $existing = $database->query("SELECT scan_profile, scan_interval_hours FROM ips WHERE ip='192.0.2.40'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('deep', $existing['scan_profile']);
        self::assertSame(1, (int) $existing['scan_interval_hours']);

        $database->exec("INSERT INTO ips (name, mac, ip) VALUES ('New host', '02:00:00:00:00:41', '192.0.2.41')");
        $created = $database->query("SELECT scan_profile, scan_interval_hours FROM ips WHERE ip='192.0.2.41'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('standard', $created['scan_profile']);
        self::assertSame(24, (int) $created['scan_interval_hours']);

        foreach ([
            'ip_conflicts', 'ip_conflict_devices', 'ip_conflict_monitor',
            'operation_status', 'operation_failures', 'notification_delivery_settings',
            'telegram_known_chats',
        ] as $table) {
            $exists = $database->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table",
            );
            $exists->execute(['table' => $table]);
            self::assertSame(1, (int) $exists->fetchColumn());
        }
        $scanColumns = $database->query('PRAGMA table_info(scans)')->fetchAll(PDO::FETCH_ASSOC);
        self::assertContains('queued_at', array_column($scanColumns, 'name'));

        $this->app()->backend()->databaseApplyMigrations(
            $database, 6, $this->app()->config()->projectDir . '/migrations',
        );
        self::assertSame(6, $this->app()->backend()->databaseSchemaVersion($database));
        self::assertSame([], $database->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testMigrationSequencingRollbackAndGuards(): void
    {
        $directory = sys_get_temp_dir() . '/fenping-migrations-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            file_put_contents($directory . '/0002_add_note.sql', 'ALTER TABLE migration_probe ADD COLUMN note TEXT;');
            file_put_contents($directory . '/0003_add_row.sql', "INSERT INTO migration_probe (value, note) VALUES ('kept', 'version 3');");
            $database = $this->memoryDatabase();
            $database->exec('CREATE TABLE migration_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL); PRAGMA user_version=1;');
            $this->app()->backend()->databaseApplyMigrations($database, 3, $directory);
            self::assertSame(3, $this->app()->backend()->databaseSchemaVersion($database));

            file_put_contents($directory . '/0004_transaction.sql', 'COMMIT;');
            try {
                $this->app()->backend()->databaseApplyMigrations($database, 4, $directory);
                self::fail('transaction-control migration was accepted');
            } catch (RuntimeException) {
                self::assertSame(3, $this->app()->backend()->databaseSchemaVersion($database));
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function memoryDatabase(): PDO
    {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('PRAGMA foreign_keys=ON');
        return $database;
    }
}
