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
        self::assertSame([], $this->app()->inventory()->scheduledTargets([$unmanagedIp], $dueTime + 3600));

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
}
