<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Scan\ProfileCatalog;

final class DatabaseIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testDatabaseDefaultsSchedulingAndQueueConcurrency(): void
    {
        $database = $this->app()->database();
        $pdo = $database->connection();
        self::assertSame(2, $database->schemaVersion());
        self::assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        self::assertSame([], $database->integrityErrors());

        $managedIp = '192.0.2.30';
        $managedId = $this->app()->hosts()->create($managedIp, '02:00:00:00:00:30');
        $managed = $this->app()->hosts()->byId($managedId);
        self::assertSame(ProfileCatalog::MANAGED_DEFAULT, $managed['scan_profile']);
        self::assertSame(ProfileCatalog::MANAGED_INTERVAL_HOURS, (int) $managed['scan_interval_hours']);

        $legacy = $pdo->prepare("INSERT INTO ips (mac, ip, scan_profile, scan_interval_hours) VALUES (:mac, :ip, 'deep', 1)");
        $legacy->execute(['mac' => '02:00:00:00:00:31', 'ip' => '192.0.2.31']);
        $day = gmmktime(0, 0, 0, 7, 12, 2026);
        $unmanagedIp = '192.0.2.32';
        $dueTime = $day + $this->app()->inventory()->initialUnmanagedHour($unmanagedIp) * 3600;
        $due = $this->app()->inventory()->scheduledTargets([$unmanagedIp], $dueTime);
        self::assertSame(ProfileCatalog::UNMANAGED_DEFAULT, $due[0]['profile']);
        self::assertSame(ProfileCatalog::UNMANAGED_DEFAULT, $this->app()->inventory()->scheduledTargets([$unmanagedIp], $dueTime + 3600)[0]['profile']);
        self::assertSame([], $this->app()->inventory()->scheduledTargets([$unmanagedIp], $dueTime + 7200));

        $first = $this->app()->scanJobs()->enqueue('192.0.2.20', 'lightweight');
        $upgraded = $this->app()->scanJobs()->enqueue('192.0.2.20', 'deep');
        self::assertTrue($first['created']);
        self::assertFalse($upgraded['created']);
        self::assertSame((int) $first['metadata']['id'], (int) $upgraded['metadata']['id']);

        foreach (range(21, 26) as $octet) {
            $this->app()->scanJobs()->enqueue('192.0.2.' . $octet, 'standard');
        }
        $claimed = $this->app()->scanJobs()->claimQueued(4);
        self::assertCount(4, $claimed);
        self::assertSame(4, $this->app()->scanJobs()->runningCount());
        self::assertSame([], $this->app()->scanJobs()->claimQueued(4));
        $this->app()->scanJobs()->fail((int) $claimed[0]['id'], 'test completion');
        self::assertCount(1, $this->app()->scanJobs()->claimQueued(4));
        self::assertSame([], $database->integrityErrors());
    }

    public function testPingWritesStatusHistoryTransactionally(): void
    {
        $pdo = $this->app()->database()->connection();
        $this->app()->backend()->savePingHosts([
            ['ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Up'],
            ['ip' => '192.0.2.11', 'mac' => '', 'status' => 'Down'],
        ]);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM ping')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM stats')->fetchColumn());

        $this->app()->backend()->savePingHosts([['ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Up']]);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM stats WHERE ip='192.0.2.10'")->fetchColumn());
        $this->app()->backend()->savePingHosts([['ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Down']]);
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM stats WHERE ip='192.0.2.10'")->fetchColumn());
    }

    public function testExtraNetworkInventoryKeepsIpOnlyHostsAndDhcpRenderIgnoresThem(): void
    {
        $pdo = $this->app()->database()->connection();
        $this->app()->backend()->savePingHosts([
            ['ip' => '198.51.100.10', 'mac' => '', 'status' => 'Up'],
            ['ip' => '198.51.100.11', 'mac' => '', 'status' => 'Down'],
            ['ip' => '198.51.100.12', 'mac' => '', 'status' => 'Down'],
        ]);
        $pdo->exec("INSERT INTO scans (ip, mode, state, status, date_begin, date_end) VALUES ('198.51.100.11', 'deep', 'complete', 'up', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO scans (ip, mode, state, status, date_begin, date_end) VALUES ('198.51.100.12', 'deep', 'complete', 'up', datetime('now', '-8 days'), datetime('now', '-8 days'))");
        $this->app()->hosts()->create('198.51.100.20', '02:00:00:00:00:20');
        $this->app()->backend()->savePingHosts([['ip' => '198.51.100.20', 'mac' => '02:00:00:00:00:20', 'status' => 'Down']]);
        $pdo->exec("UPDATE ping SET date=datetime('now', '-8 days') WHERE ip IN ('198.51.100.12', '198.51.100.20')");
        $pdo->exec("INSERT INTO range (ip_begin, type) VALUES ('198.51.100.1', 'Remote &amp; devices')");

        $inventory = $this->app()->backend()->getInventory('198.51.100.0/24');
        self::assertContains('198.51.100.10', array_column($inventory, 'ip'));
        self::assertContains('198.51.100.11', array_column($inventory, 'ip'));
        self::assertNotContains('198.51.100.12', array_column($inventory, 'ip'));
        self::assertContains('198.51.100.20', array_column($inventory, 'ip'));
        $downHost = array_values(array_filter($inventory, static fn(array $host): bool => $host['ip'] === '198.51.100.11'))[0];
        self::assertSame('Down', $downHost['status']);
        self::assertSame('Remote & devices', $inventory[0]['category']);
        self::assertSame('198.51.100.1', $inventory[0]['category_ip']);

        $rendered = $this->app()->backend()->buildDnsmasqFiles();
        self::assertStringNotContainsString('198.51.100.20', implode("\n", $rendered));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM ips WHERE ip='198.51.100.20'")->fetchColumn());
    }
}
