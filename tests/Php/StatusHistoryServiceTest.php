<?php

declare(strict_types=1);

namespace FenPing\Tests;

final class StatusHistoryServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testStatsMapMatchesIndividualHistorySummaries(): void
    {
        $pdo = $this->app()->database()->connection();
        $pdo->exec("
            INSERT INTO stats (ip, status, date_begin, date_end) VALUES
              ('192.0.2.10', 'Up', datetime('now', '-2 days'), CURRENT_TIMESTAMP),
              ('192.0.2.20', 'Up', datetime('now', '-2 days'), datetime('now', '-1 day')),
              ('192.0.2.20', 'Down', datetime('now', '-1 day'), CURRENT_TIMESTAMP),
              ('192.0.2.30', 'Down', datetime('now', '-1 hour'), CURRENT_TIMESTAMP)
        ");

        $history = $this->app()->history();
        $expectedUnstable = $history->summary($history->history('192.0.2.20'));
        $map = $history->statsMap();

        self::assertArrayNotHasKey('192.0.2.10', $map);
        self::assertArrayHasKey('192.0.2.20', $map);
        self::assertArrayHasKey('192.0.2.30', $map);
        foreach (['uptime_percent', 'transitions', 'current_status', 'stable', 'level', 'label'] as $field) {
            self::assertSame($expectedUnstable[$field], $map['192.0.2.20'][$field]);
        }
    }
}
