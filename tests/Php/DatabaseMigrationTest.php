<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Network\Ipv4Network;
use PDO;
use RuntimeException;


final class DatabaseMigrationTest extends IntegrationTestCase
{
    public function testUnversionedAndIncorrectlyStampedVersionSixSchemasAreRecovered(): void
    {
        foreach ([0, 7] as $reportedVersion) {
            $path = tempnam(sys_get_temp_dir(), 'fenping-legacy-schema-');
            self::assertIsString($path);
            try {
                $database = new PDO('sqlite:' . $path);
                $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $database->exec("
                  CREATE TABLE ips (
                    id INTEGER PRIMARY KEY, scan_profile TEXT NOT NULL DEFAULT 'standard',
                    scan_interval_hours INTEGER NOT NULL DEFAULT 24
                  );
                  CREATE TABLE scans (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, mode TEXT NOT NULL,
                    state TEXT NOT NULL, queued_at DATETIME, date_begin DATETIME, date_end DATETIME
                  );
                  CREATE TABLE notification_delivery_settings (
                    id INTEGER PRIMARY KEY, telegram_chat_id TEXT, telegram_bot_fingerprint TEXT
                  );
                  CREATE TABLE telegram_known_chats (chat_id TEXT PRIMARY KEY);
                  INSERT INTO scans (ip, mode, state, queued_at, date_begin, date_end)
                  VALUES ('192.0.2.44', 'standard', 'complete', '2026-07-13 10:00:00',
                    '2026-07-13 10:01:00', '2026-07-13 10:02:00');
                  PRAGMA user_version=$reportedVersion;
                ");
                unset($database);

                $config = new AppConfig(
                    projectDir: dirname(__DIR__, 2),
                    databasePath: $path,
                    dhcpNetwork: Ipv4Network::from24('192.0.2.0/24'),
                    extraNetworks: [],
                    interface: 'eth0',
                    applianceIp: '192.0.2.100',
                    dhcpDynamicBegin: '200',
                    dhcpDynamicEnd: '250',
                    password: '',
                    secret: 'test',
                    discordWebhookUrl: '',
                    dataDir: sys_get_temp_dir(),
                );
                $manager = new DatabaseManager($config);
                $manager->initialize();

                self::assertSame(7, $manager->schemaVersion());
                $row = $manager->connection()->query(
                    "SELECT network, request_source, progress_percent, progress_phase FROM scans",
                )->fetch(PDO::FETCH_ASSOC);
                self::assertSame('192.0.2.0/24', $row['network']);
                self::assertSame('legacy', $row['request_source']);
                self::assertSame(100, (int) $row['progress_percent']);
                self::assertSame('complete', $row['progress_phase']);
                unset($manager);
            } finally {
                foreach ([$path, $path . '-wal', $path . '-shm'] as $databasePath) {
                    if (is_file($databasePath)) {
                        unlink($databasePath);
                    }
                }
            }
        }
    }

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
        self::assertSame(7, $this->app()->backend()->databaseSchemaVersion($database));
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
            $database, 7, $this->app()->config()->projectDir . '/migrations',
        );
        self::assertSame(7, $this->app()->backend()->databaseSchemaVersion($database));
        self::assertSame([], $database->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testVersionSixScanRowsGainControlFieldsAndIndexes(): void
    {
        $database = $this->memoryDatabase();
        $database->exec("
          CREATE TABLE scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            mode TEXT NOT NULL,
            state TEXT NOT NULL,
            queued_at DATETIME,
            date_begin DATETIME,
            date_end DATETIME
          );
          INSERT INTO scans (ip, mode, state, queued_at, date_begin, date_end)
          VALUES
            ('192.0.2.40', 'deep', 'complete', '2026-07-13 10:00:00', '2026-07-13 10:01:00', '2026-07-13 10:02:00'),
            ('198.51.100.41', 'standard', 'queued', '2026-07-13 11:00:00', NULL, NULL);
          PRAGMA user_version=6;
        ");

        $this->app()->backend()->databaseApplyMigrations(
            $database,
            7,
            $this->app()->config()->projectDir . '/migrations',
        );

        self::assertSame(7, $this->app()->backend()->databaseSchemaVersion($database));
        $columns = array_column(
            $database->query('PRAGMA table_info(scans)')->fetchAll(PDO::FETCH_ASSOC),
            'name',
        );
        foreach ([
            'network', 'request_source', 'progress_percent', 'progress_phase',
            'progress_updated_at', 'cancel_requested_at',
        ] as $column) {
            self::assertContains($column, $columns);
        }
        $rows = $database->query(
            'SELECT network, request_source, progress_percent, progress_phase FROM scans ORDER BY id',
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(
            ['network' => '192.0.2.0/24', 'request_source' => 'legacy', 'progress_percent' => 100, 'progress_phase' => 'complete'],
            $rows[0],
        );
        self::assertSame('198.51.100.0/24', $rows[1]['network']);
        self::assertSame(0, (int) $rows[1]['progress_percent']);
        $indexes = array_column($database->query('PRAGMA index_list(scans)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('scans_network_state', $indexes);
        self::assertContains('scans_network_source_started', $indexes);
        self::assertSame('ok', (string) $database->query('PRAGMA integrity_check')->fetchColumn());
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
