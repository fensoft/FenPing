<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;

final class HealthApiTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testOperatorHealthReportsExceptionsAndOperationalFailures(): void
    {
        $database = $this->app()->database()->connection();
        $hostId = $this->app()->hosts()->create('192.0.2.10', '02:00:00:00:00:10');
        $database->exec("UPDATE ips SET important=1 WHERE id=$hostId");
        $this->app()->backend()->savePingHosts([
            ['ip' => '192.0.2.10', 'mac' => '02:00:00:00:00:10', 'status' => 'Down'],
        ]);
        $database->exec("
            INSERT INTO leases (
              ip, `hardware-ethernet`, `client-hostname`, ends, first_seen, last_seen, active
            ) VALUES (
              '192.0.2.210', '02:00:00:00:00:99', 'new-device',
              datetime('now', '+1 day'), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1
            )
        ");

        $queued = $this->app()->scanJobs()->enqueue('192.0.2.20', 'standard');
        $queuedId = (int) $queued['metadata']['id'];
        $database->exec("UPDATE scans SET queued_at=datetime('now', '-30 minutes') WHERE id=$queuedId");
        $database->exec("
            INSERT INTO scans (ip, mode, state, date_begin, date_end, error)
            VALUES
              ('192.0.2.21', 'standard', 'failed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'test failure'),
              ('192.0.2.22', 'standard', 'timeout', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'test timeout')
        ");

        $this->app()->backend()->operations->succeeded('database_integrity');
        $this->app()->backend()->operations->failed('dnsmasq_generation', 'invalid generated configuration');
        $this->app()->backend()->operations->failed('notification_delivery', 'HTTP 503');
        $this->app()->backend()->operations->failed('telegram_notification_delivery', 'HTTP 429');

        $response = $this->app()->api()->handle($this->request('/api/health'));
        self::assertSame(200, $response->status);
        $health = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $health['scans']['queued']);
        self::assertSame(0, $health['scans']['running']);
        self::assertSame(1, $health['scans']['failed']);
        self::assertSame(1, $health['scans']['timed_out']);
        self::assertSame('warning', $health['scans']['queue_status']);
        self::assertGreaterThanOrEqual(1800, $health['scans']['oldest_queued_age_seconds']);
        self::assertSame(1, $health['exceptions']['new_devices']);
        self::assertSame(1, $health['exceptions']['important_hosts_down']);
        self::assertSame(1, $health['dnsmasq']['generation']['recent_failures']);
        self::assertSame(1, $health['notifications']['delivery']['recent_failures']);
        self::assertSame(1, $health['notifications']['discord']['delivery']['recent_failures']);
        self::assertSame(1, $health['notifications']['telegram']['delivery']['recent_failures']);
        self::assertFalse($health['notifications']['discord']['configured']);
        self::assertFalse($health['notifications']['telegram']['configured']);
        self::assertFalse($health['notifications']['telegram']['chat_selected']);
        self::assertFalse($health['notifications']['telegram']['enabled']);
        self::assertSame('ok', $health['integrity']['status']);
        self::assertNotNull($health['jobs']['ping']['last_success_at']);
        self::assertGreaterThan(0, $health['storage']['sqlite_bytes']);
        self::assertArrayHasKey('utilization_percent', $health['dhcp']);
    }

    public function testLivenessIsIndependentAndReadinessControlsItsStatusCode(): void
    {
        $this->app()->backend()->operations->succeeded('database_integrity');

        $live = $this->app()->api()->handle($this->request('/api/health/live'));
        self::assertSame(200, $live->status);
        self::assertSame('ok', json_decode($live->body, true, flags: JSON_THROW_ON_ERROR)['status']);

        $ready = $this->app()->api()->handle($this->request('/api/health/ready'));
        $body = json_decode($ready->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($body['ready'] ? 200 : 503, $ready->status);
        self::assertArrayHasKey('reasons', $body);
        self::assertArrayHasKey('integrity', $body);
    }

    private function request(string $uri): Request
    {
        return new Request('GET', $uri, [], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
        ], []);
    }
}
