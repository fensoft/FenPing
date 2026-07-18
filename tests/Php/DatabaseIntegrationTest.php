<?php

declare(strict_types=1);

namespace FenPing\Tests;

use OutOfBoundsException;
use FenPing\Scan\ProfileCatalog;
use RuntimeException;

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
        self::assertSame(10, $database->schemaVersion());
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

        foreach (range(21, 23) as $octet) {
            $this->app()->scanJobs()->enqueue('192.0.2.' . $octet, 'standard');
        }
        foreach (range(20, 22) as $octet) {
            $this->app()->scanJobs()->enqueue('198.51.100.' . $octet, 'standard');
        }
        $claimed = $this->app()->scanJobs()->claimQueued(4);
        self::assertCount(4, $claimed);
        self::assertSame(4, $this->app()->scanJobs()->runningCount());
        self::assertSame(
            ['192.0.2.0/24' => 2, '198.51.100.0/24' => 2],
            array_count_values(array_column($claimed, 'network')),
        );
        self::assertSame([], $this->app()->scanJobs()->claimQueued(4));
        $this->app()->scanJobs()->fail((int) $claimed[0]['id'], 'test completion');
        self::assertCount(1, $this->app()->scanJobs()->claimQueued(4));
        self::assertSame([], $database->integrityErrors());
    }

    public function testScheduledBudgetDefersWithoutBlockingManualPromotion(): void
    {
        $database = $this->app()->database();
        $pdo = $database->connection();
        $insert = $pdo->prepare("
          INSERT INTO scans (
            ip, mode, state, network, request_source, progress_percent, progress_phase,
            progress_updated_at, date_begin, date_end
          ) VALUES (
            '192.0.2.10', 'lightweight', 'complete', '192.0.2.0/24', 'scheduled', 100, 'complete',
            :started, :started, :started
          )
        ");
        $firstStart = time() - 23 * 3600;
        foreach (range(0, 253) as $offset) {
            $insert->execute(['started' => gmdate('Y-m-d H:i:s', $firstStart + $offset)]);
        }

        $scheduled = $this->app()->scanJobs()->enqueue('192.0.2.90', 'standard', 'scheduled');
        self::assertTrue($scheduled['created']);
        $waiting = $this->app()->scanJobs()->findJob((int) $scheduled['metadata']['id']);
        self::assertSame('scheduled', $waiting['request_source']);
        self::assertNull($waiting['queue_position']);
        self::assertSame('daily_budget', $waiting['queue_reason']);
        self::assertSame(gmdate('Y-m-d H:i:s', $firstStart + 86400), $waiting['budget_eligible_at']);
        self::assertSame([], $this->app()->scanJobs()->claimQueued(4));

        $promoted = $this->app()->scanJobs()->enqueue('192.0.2.90', 'standard', 'manual');
        self::assertFalse($promoted['created']);
        self::assertSame('manual', $promoted['metadata']['request_source']);
        self::assertSame(1, $promoted['metadata']['queue_position']);
        self::assertSame('ready', $promoted['metadata']['queue_reason']);
        $claimed = $this->app()->scanJobs()->claimQueued(4);
        self::assertCount(1, $claimed);
        self::assertSame('manual', $claimed[0]['request_source']);

        $policy = $this->app()->scanJobs()->policySummary();
        $network = array_column($policy['networks'], null, 'network')['192.0.2.0/24'];
        self::assertSame(254, $network['scheduled_starts_24h']);
        self::assertSame(254, $network['daily_budget']);
        self::assertSame([], $database->integrityErrors());
    }

    public function testQueuePositionsReserveEarlierEligibleSlots(): void
    {
        $this->app()->scanJobs()->start('192.0.2.10', 'lightweight');
        $this->app()->scanJobs()->start('192.0.2.11', 'lightweight');
        $this->app()->scanJobs()->start('198.51.100.10', 'lightweight');

        $ready = $this->app()->scanJobs()->enqueue('198.51.100.60', 'standard');
        $globalWait = $this->app()->scanJobs()->enqueue('203.0.113.60', 'standard');
        $networkWait = $this->app()->scanJobs()->enqueue('192.0.2.60', 'standard');
        $queue = array_column($this->app()->scanJobs()->queue(), null, 'id');

        $readyRow = $queue[(int) $ready['metadata']['id']];
        self::assertSame(1, $readyRow['queue_position']);
        self::assertSame('ready', $readyRow['queue_reason']);
        self::assertSame('queued', $readyRow['progress_phase']);

        $globalRow = $queue[(int) $globalWait['metadata']['id']];
        self::assertSame(2, $globalRow['queue_position']);
        self::assertSame('global_concurrency', $globalRow['queue_reason']);
        self::assertSame('waiting_global', $globalRow['progress_phase']);

        $networkRow = $queue[(int) $networkWait['metadata']['id']];
        self::assertSame(3, $networkRow['queue_position']);
        self::assertSame('network_concurrency', $networkRow['queue_reason']);
        self::assertSame('waiting_network', $networkRow['progress_phase']);

        $inventoryScan = $this->app()->inventory()->latestScans()['198.51.100.60'];
        self::assertSame('198.51.100.0/24', $inventoryScan['network']);
        self::assertSame(0, $inventoryScan['progress_percent']);
        self::assertSame(1, $inventoryScan['queue_position']);
        self::assertFalse($inventoryScan['cancel_requested']);
        self::assertArrayNotHasKey('cancel_requested_at', $inventoryScan);
    }

    public function testCancellationIsIdempotentAndWinsTerminalRaces(): void
    {
        $queued = $this->app()->scanJobs()->enqueue('192.0.2.70', 'standard');
        $queuedId = (int) $queued['metadata']['id'];
        $cancelled = $this->app()->scanJobs()->cancel('192.0.2.70', $queuedId);
        self::assertSame(200, $cancelled['status']);
        self::assertSame('cancelled', $cancelled['metadata']['state']);
        self::assertFalse($cancelled['metadata']['cancel_requested']);
        self::assertSame(200, $this->app()->scanJobs()->cancel('192.0.2.70', $queuedId)['status']);

        $runningId = $this->app()->scanJobs()->start('192.0.2.71', 'deep');
        $this->app()->scanJobs()->updateProgress($runningId, 'port_scan', 40);
        $requested = $this->app()->scanJobs()->cancel('192.0.2.71', $runningId);
        self::assertSame(200, $requested['status']);
        self::assertSame('cancelled', $requested['metadata']['state']);
        self::assertTrue($requested['metadata']['cancel_requested']);
        self::assertSame('cancelled', $requested['metadata']['progress_phase']);
        self::assertTrue($this->app()->scanJobs()->cancellationRequested($runningId));

        $this->app()->scanJobs()->updateProgress($runningId, 'service_detection', 70);
        $this->app()->scanJobs()->fail($runningId, 'stale worker failure');
        $this->app()->scanJobs()->timeout($runningId, 'stale worker timeout');
        self::assertFalse($this->app()->scanJobs()->complete($runningId, ['status' => 'down', 'duration' => 1, 'ports' => []]));
        $terminal = $this->app()->scanJobs()->findJob($runningId);
        self::assertSame('cancelled', $terminal['state']);
        self::assertSame(40, $terminal['progress_percent']);
        self::assertSame('cancelled', $terminal['progress_phase']);

        try {
            $this->app()->scanJobs()->cancel('192.0.2.99', 999999);
            self::fail('missing scan was cancellable');
        } catch (OutOfBoundsException) {
            self::addToAssertionCount(1);
        }
        $completeId = $this->app()->scanJobs()->start('192.0.2.72', 'lightweight');
        $this->app()->scanJobs()->complete($completeId, ['status' => 'down', 'duration' => 1, 'ports' => []]);
        try {
            $this->app()->scanJobs()->cancel('192.0.2.72', $completeId);
            self::fail('completed scan was cancellable');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testPingWritesStatusHistoryTransactionally(): void
    {
        $pdo = $this->app()->database()->connection();
        $this->app()->pingRepository()->save([
            ['ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Up'],
            ['ip' => '192.0.2.11', 'mac' => '', 'status' => 'Down'],
        ]);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM ping')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM stats')->fetchColumn());

        $this->app()->pingRepository()->save([['ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Up']]);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM stats WHERE ip='192.0.2.10'")->fetchColumn());
        $this->app()->pingRepository()->save([['ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Down']]);
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM stats WHERE ip='192.0.2.10'")->fetchColumn());
    }

    public function testExtraNetworkInventoryKeepsIpOnlyHostsAndDhcpRenderIgnoresThem(): void
    {
        $pdo = $this->app()->database()->connection();
        $this->app()->pingRepository()->save([
            ['ip' => '198.51.100.10', 'mac' => '', 'status' => 'Up'],
            ['ip' => '198.51.100.11', 'mac' => '', 'status' => 'Down'],
            ['ip' => '198.51.100.12', 'mac' => '', 'status' => 'Down'],
        ]);
        $pdo->exec("INSERT INTO scans (ip, mode, state, status, date_begin, date_end) VALUES ('198.51.100.11', 'deep', 'complete', 'up', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO scans (ip, mode, state, status, date_begin, date_end) VALUES ('198.51.100.12', 'deep', 'complete', 'up', datetime('now', '-8 days'), datetime('now', '-8 days'))");
        $this->app()->hosts()->create('198.51.100.20', '02:00:00:00:00:20');
        $this->app()->pingRepository()->save([['ip' => '198.51.100.20', 'mac' => '02:00:00:00:00:20', 'status' => 'Down']]);
        $pdo->exec("UPDATE ping SET date=datetime('now', '-8 days') WHERE ip IN ('198.51.100.12', '198.51.100.20')");
        $pdo->exec("INSERT INTO range (ip_begin, type) VALUES ('198.51.100.1', 'Remote &amp; devices')");

        $inventory = $this->app()->inventory()->forNetwork('198.51.100.0/24');
        self::assertContains('198.51.100.10', array_column($inventory, 'ip'));
        self::assertContains('198.51.100.11', array_column($inventory, 'ip'));
        self::assertNotContains('198.51.100.12', array_column($inventory, 'ip'));
        self::assertContains('198.51.100.20', array_column($inventory, 'ip'));
        $downHost = array_values(array_filter($inventory, static fn(array $host): bool => $host['ip'] === '198.51.100.11'))[0];
        self::assertSame('Down', $downHost['status']);
        self::assertSame('Remote & devices', $inventory[0]['category']);
        self::assertSame('198.51.100.1', $inventory[0]['category_ip']);

        $rendered = $this->app()->dhcpConfig()->render();
        self::assertStringNotContainsString('198.51.100.20', implode("\n", $rendered));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM ips WHERE ip='198.51.100.20'")->fetchColumn());
    }
}
