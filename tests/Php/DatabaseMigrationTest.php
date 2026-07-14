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
        foreach ([0, 7, 8, 9] as $reportedVersion) {
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

                self::assertSame(9, $manager->schemaVersion());
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

        $this->app()->database()->applyMigrations($database, \FenPing\Database\DatabaseManager::SCHEMA_VERSION, $this->app()->config()->projectDir . '/migrations');
        self::assertSame(9, $this->app()->database()->schemaVersion($database));
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
            'telegram_known_chats', 'tags', 'host_tags', 'inventory_saved_filters',
            'inventory_saved_filter_tags', 'inventory_device_metadata', 'inventory_device_tags',
        ] as $table) {
            $exists = $database->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table",
            );
            $exists->execute(['table' => $table]);
            self::assertSame(1, (int) $exists->fetchColumn());
        }
        $scanColumns = $database->query('PRAGMA table_info(scans)')->fetchAll(PDO::FETCH_ASSOC);
        self::assertContains('queued_at', array_column($scanColumns, 'name'));

        $this->app()->database()->applyMigrations(
            $database, 9, $this->app()->config()->projectDir . '/migrations',
        );
        self::assertSame(9, $this->app()->database()->schemaVersion($database));
        self::assertSame([], $database->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testVersionEightGainsDockerDeviceMetadataAndDisplayNames(): void
    {
        $database = $this->memoryDatabase();
        $database->exec("
          CREATE TABLE ips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT COLLATE NOCASE UNIQUE,
            mac TEXT COLLATE NOCASE UNIQUE,
            ip TEXT UNIQUE,
            notes TEXT,
            location TEXT,
            owner TEXT,
            model TEXT,
            icon TEXT
          );
          CREATE TABLE tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT COLLATE NOCASE NOT NULL UNIQUE
          );
          INSERT INTO ips (name, mac, ip)
          VALUES ('Existing host', '02:00:00:00:00:42', '192.0.2.42');
          PRAGMA user_version=8;
        ");

        $this->app()->database()->applyMigrations(
            $database,
            9,
            $this->app()->config()->projectDir . '/migrations',
        );

        self::assertSame(9, $this->app()->database()->schemaVersion($database));
        $columns = array_column(
            $database->query('PRAGMA table_info(ips)')->fetchAll(PDO::FETCH_ASSOC),
            'name',
        );
        self::assertContains('display_name', $columns);
        self::assertNull($database->query(
            "SELECT display_name FROM ips WHERE ip='192.0.2.42'",
        )->fetchColumn());

        $database->exec("
          INSERT INTO inventory_device_metadata (
            network_name, container_name, display_name, scan_profile, scan_interval_hours
          ) VALUES ('app_default', 'camera', 'Front camera', 'standard', 6);
          INSERT INTO tags (name) VALUES ('Camera');
          INSERT INTO inventory_device_tags (device_id, tag_id) VALUES (1, 1);
        ");
        self::assertSame(1, (int) $database->query(
            'SELECT COUNT(*) FROM inventory_device_tags',
        )->fetchColumn());
        $database->exec('DELETE FROM inventory_device_metadata WHERE id=1');
        self::assertSame(0, (int) $database->query(
            'SELECT COUNT(*) FROM inventory_device_tags',
        )->fetchColumn());
        self::assertSame([], $database->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testVersionSevenHostsGainMetadataTagsAndSavedFilters(): void
    {
        $database = $this->memoryDatabase();
        $database->exec("
          CREATE TABLE ips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT COLLATE NOCASE UNIQUE,
            mac TEXT COLLATE NOCASE UNIQUE,
            ip TEXT UNIQUE
          );
          INSERT INTO ips (name, mac, ip)
          VALUES ('Existing host', '02:00:00:00:00:42', '192.0.2.42');
          PRAGMA user_version=7;
        ");

        $this->app()->database()->applyMigrations(
            $database,
            9,
            $this->app()->config()->projectDir . '/migrations',
        );

        self::assertSame(9, $this->app()->database()->schemaVersion($database));
        $columns = array_column(
            $database->query('PRAGMA table_info(ips)')->fetchAll(PDO::FETCH_ASSOC),
            'name',
        );
        foreach (['notes', 'location', 'owner', 'model', 'icon'] as $column) {
            self::assertContains($column, $columns);
        }
        $host = $database->query(
            "SELECT notes, location, owner, model, icon FROM ips WHERE ip='192.0.2.42'",
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(
            ['notes' => null, 'location' => null, 'owner' => null, 'model' => null, 'icon' => null],
            $host,
        );

        foreach ([
            'tags', 'host_tags', 'inventory_saved_filters', 'inventory_saved_filter_tags',
        ] as $table) {
            $exists = $database->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table",
            );
            $exists->execute(['table' => $table]);
            self::assertSame(1, (int) $exists->fetchColumn());
        }
        $indexes = array_column(
            $database->query('PRAGMA index_list(host_tags)')->fetchAll(PDO::FETCH_ASSOC),
            'name',
        );
        self::assertContains('host_tags_tag_id', $indexes);

        $database->exec("
          INSERT INTO tags (name) VALUES ('Server');
          INSERT INTO host_tags (host_id, tag_id) VALUES (1, 1);
          INSERT INTO inventory_saved_filters (name) VALUES ('Infrastructure');
          INSERT INTO inventory_saved_filter_tags (filter_id, tag_id) VALUES (1, 1);
          DELETE FROM ips WHERE id=1;
        ");
        self::assertSame(0, (int) $database->query('SELECT COUNT(*) FROM host_tags')->fetchColumn());
        $database->exec('DELETE FROM tags WHERE id=1');
        self::assertSame(
            0,
            (int) $database->query('SELECT COUNT(*) FROM inventory_saved_filter_tags')->fetchColumn(),
        );
        self::assertSame([], $database->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testVersionSixScanRowsGainControlFieldsAndIndexes(): void
    {
        $database = $this->memoryDatabase();
        $database->exec("
          CREATE TABLE ips (id INTEGER PRIMARY KEY AUTOINCREMENT);
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

        $this->app()->database()->applyMigrations(
            $database,
            9,
            $this->app()->config()->projectDir . '/migrations',
        );

        self::assertSame(9, $this->app()->database()->schemaVersion($database));
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
            $this->app()->database()->applyMigrations($database, 3, $directory);
            self::assertSame(3, $this->app()->database()->schemaVersion($database));

            file_put_contents($directory . '/0004_transaction.sql', 'COMMIT;');
            try {
                $this->app()->database()->applyMigrations($database, 4, $directory);
                self::fail('transaction-control migration was accepted');
            } catch (RuntimeException) {
                self::assertSame(3, $this->app()->database()->schemaVersion($database));
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
