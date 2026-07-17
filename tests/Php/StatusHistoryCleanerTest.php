<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Status\StatusHistoryCleaner;

final class StatusHistoryCleanerTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testCleanupRemovesExpiredEventsAndKeepsNewestEventsPerIp(): void
    {
        $database = $this->app()->database();
        $pdo = $database->connection();
        $insert = $pdo->prepare("
            INSERT INTO stats (ip, mac, status, date_begin, date_end)
            VALUES (:ip, NULL, :status, :date, :date)
        ");

        foreach (range(1, 5) as $event) {
            $insert->execute([
                'ip' => '192.0.2.10',
                'status' => 'event-' . $event,
                'date' => gmdate('Y-m-d H:i:s', time() - (6 - $event) * 60),
            ]);
        }
        $insert->execute([
            'ip' => '192.0.2.20',
            'status' => 'expired',
            'date' => gmdate('Y-m-d H:i:s', time() - 31 * 86400),
        ]);
        $insert->execute([
            'ip' => '192.0.2.20',
            'status' => 'current',
            'date' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $result = (new StatusHistoryCleaner($database))->clean(30, 3);

        self::assertSame(3, $result['deleted']);
        self::assertSame(1, $result['deleted_by_age']);
        self::assertSame(2, $result['deleted_by_limit']);
        self::assertSame(4, $result['remaining']);
        self::assertSame(
            ['event-3', 'event-4', 'event-5'],
            $pdo->query("SELECT status FROM stats WHERE ip='192.0.2.10' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN),
        );
        self::assertSame(
            ['current'],
            $pdo->query("SELECT status FROM stats WHERE ip='192.0.2.20' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    public function testCleanupDefaultsToOneYearAndOneThousandEvents(): void
    {
        $database = $this->app()->database();
        $pdo = $database->connection();
        $pdo->exec("
            INSERT INTO stats (ip, status, date_begin, date_end)
            VALUES ('192.0.2.30', 'expired', datetime('now', '-366 days'), datetime('now', '-366 days'))
        ");
        $insert = $pdo->prepare("INSERT INTO stats (ip, status) VALUES ('192.0.2.30', :status)");
        foreach (range(1, 1001) as $event) {
            $insert->execute(['status' => 'event-' . $event]);
        }

        $result = (new StatusHistoryCleaner($database))->clean();

        self::assertSame(2, $result['deleted']);
        self::assertSame(1, $result['deleted_by_age']);
        self::assertSame(1, $result['deleted_by_limit']);
        self::assertSame(1000, $result['remaining']);
        self::assertSame(
            'event-2',
            $pdo->query("SELECT status FROM stats WHERE ip='192.0.2.30' ORDER BY id LIMIT 1")->fetchColumn(),
        );
    }

    public function testCleanupCompactsWhenFreeSpaceCrossesThresholds(): void
    {
        $database = $this->app()->database();
        $pdo = $database->connection();
        $insert = $pdo->prepare("
            INSERT INTO stats (ip, status, date_begin, date_end)
            VALUES ('192.0.2.40', :status, datetime('now', '-31 days'), datetime('now', '-31 days'))
        ");
        $payload = str_repeat('x', 4096);
        foreach (range(1, 50) as $event) {
            $insert->execute(['status' => $payload . $event]);
        }

        $result = (new StatusHistoryCleaner($database, 1, 0.0))->clean(30, 1000);

        self::assertTrue($result['compacted']);
        self::assertGreaterThan(0, $result['reclaimable_bytes_before']);
        self::assertGreaterThan(0, $result['reclaimed_bytes']);
        self::assertLessThan($result['database_bytes_before'], $result['database_bytes_after']);
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
    }
}
