<?php

declare(strict_types=1);

namespace FenPing\Tests;

use DateTimeImmutable;
use DateTimeZone;
use FenPing\Api\Request;

final class ScheduledReportTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->app()->auth()->isAuthenticated()) {
            $this->app()->auth()->logout();
        }
    }

    public function testPreviewAggregatesEveryReportCategory(): void
    {
        $database = $this->app()->database()->connection();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $old = $now->modify('-2 days')->format('Y-m-d H:i:s');
        $recent = $now->modify('-2 hours')->format('Y-m-d H:i:s');
        $database->exec("INSERT INTO stats (ip, mac, status, date_begin, date_end) VALUES
            ('192.0.2.10', '02:00:00:00:00:10', 'Up', '$old', '$old'),
            ('192.0.2.10', '02:00:00:00:00:10', 'Down', '$recent', '$recent'),
            ('192.0.2.20', '02:00:00:00:00:20', 'Up', '$recent', '$recent')");
        $database->exec("INSERT INTO ip_conflicts (network, ip, detected_at, last_seen_at)
            VALUES ('192.0.2.0/24', '192.0.2.30', '$recent', '$recent')");
        $database->exec("INSERT INTO scan_port_changes
            (scan_id, ip, mode, change_type, protocol, port, current_service, created_at)
            VALUES (50, '192.0.2.40', 'standard', 'appeared', 'tcp', 443, 'https', '$recent')");

        $expires = $now->modify('+5 days')->format('Y-m-d\TH:i:s');
        $database->exec("INSERT INTO scan_snapshots (id, ip, mode, result_hash, content_hash, created_at)
            VALUES (70, '192.0.2.50', 'standard', 'report-result', 'report-content', '$recent')");
        $database->exec("INSERT INTO scans (id, ip, mode, state, date_begin, date_end, snapshot_id)
            VALUES (70, '192.0.2.50', 'standard', 'complete', '$recent', '$recent', 70)");
        $database->exec("INSERT INTO scan_snapshot_ports (id, snapshot_id, protocol, port, state)
            VALUES (70, 70, 'tcp', 443, 'open')");
        $database->exec("INSERT INTO scan_snapshot_scripts (id, snapshot_id, port_id, position, script_id, output)
            VALUES (70, 70, 70, 0, 'ssl-cert', 'Not valid after:  $expires')");

        $report = $this->app()->scheduledReports()->preview('daily');
        self::assertSame([
            'outages' => 1,
            'new_devices' => 1,
            'conflicts' => 1,
            'changed_ports' => 1,
            'expiring_certificates' => 1,
        ], $report['counts']);
        self::assertSame('192.0.2.50', $report['expiring_certificates'][0]['ip']);
        self::assertSame(5, $report['expiring_certificates'][0]['days_remaining']);
    }

    public function testSettingsApiIsStrictAndDueRunsAreIdempotent(): void
    {
        self::assertTrue($this->app()->auth()->login(''));
        $settings = [
            'daily_enabled' => true,
            'weekly_enabled' => false,
            'hour_utc' => (int) gmdate('G'),
            'weekly_day' => 1,
            'certificate_warning_days' => 30,
        ];
        $response = $this->app()->api()->handle($this->request('PUT', '/api/notify/delivery', [
            'rules' => $this->app()->notificationRules()->notificationDefaultRules(),
            'reports' => $settings,
        ]));
        self::assertSame(200, $response->status, $response->body);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($settings, $body['reports']['settings']);

        $invalid = $settings;
        $invalid['hour_utc'] = 24;
        $response = $this->app()->api()->handle($this->request('PUT', '/api/notify/delivery', [
            'rules' => $this->app()->notificationRules()->notificationDefaultRules(),
            'reports' => $invalid,
        ]));
        self::assertSame(400, $response->status);

        $this->app()->database()->connection()->exec(
            "UPDATE scheduled_report_settings SET updated_at=datetime('now', '-2 days') WHERE id=1",
        );
        self::assertSame('skipped', $this->app()->scheduledReports()->runDue()['daily']);
        self::assertSame('already_run', $this->app()->scheduledReports()->runDue()['daily']);
        self::assertSame(1, (int) $this->app()->database()->connection()
            ->query("SELECT COUNT(*) FROM scheduled_report_runs WHERE frequency='daily'")->fetchColumn());
    }

    private function request(string $method, string $uri, array $body): Request
    {
        return new Request(
            $method,
            $uri,
            [],
            [],
            [],
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            [],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
