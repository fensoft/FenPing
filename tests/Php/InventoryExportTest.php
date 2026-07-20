<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;

final class InventoryExportTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
        if ($this->app()->auth()->isAuthenticated()) $this->app()->auth()->logout();
        $this->seedExportData();
    }

    protected function tearDown(): void
    {
        if ($this->app()->auth()->isAuthenticated()) $this->app()->auth()->logout();
    }

    public function testExportsRequireAuthenticationAndValidateInputs(): void
    {
        self::assertSame(403, $this->download('hosts', 'csv')->status);
        self::assertTrue($this->app()->auth()->login(''));
        self::assertSame(400, $this->download('unknown', 'csv')->status);
        self::assertSame(400, $this->download('hosts', 'xml')->status);
        self::assertSame(400, $this->download('hosts', 'csv', '203.0.113.0/24')->status);
    }

    public function testEveryDatasetExportsAsVersionedJson(): void
    {
        self::assertTrue($this->app()->auth()->login(''));
        foreach (['hosts', 'leases', 'services', 'scan_changes', 'anomalies', 'uptime_history'] as $dataset) {
            $response = $this->download($dataset, 'json');
            self::assertSame(200, $response->status, $response->body);
            self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
            self::assertStringContainsString('attachment; filename="fenping-' . str_replace('_', '-', $dataset), $response->headers['Content-Disposition']);
            self::assertSame('private, no-store', $response->headers['Cache-Control']);
            $document = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('fenping-inventory-export', $document['format']);
            self::assertSame(1, $document['version']);
            self::assertSame($dataset, $document['dataset']);
            self::assertSame('192.0.2.0/24', $document['network']);
            self::assertGreaterThanOrEqual(1, $document['count'], $dataset);
        }
    }

    public function testCsvUsesStableColumnsAndNeutralizesSpreadsheetFormulas(): void
    {
        self::assertTrue($this->app()->auth()->login(''));
        $response = $this->download('leases', 'csv');
        self::assertSame(200, $response->status);
        self::assertSame('text/csv; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringStartsWith("\xEF\xBB\xBFip,mac,hostname,ends,first_seen,last_seen,active\n", $response->body);
        self::assertStringContainsString("'=unsafe-formula", $response->body);
        self::assertSame((string) strlen($response->body), $response->headers['Content-Length']);
    }

    private function seedExportData(): void
    {
        $database = $this->app()->database()->connection();
        $database->exec("INSERT INTO ips (id, name, display_name, mac, ip, important)
            VALUES (91, 'export-host', 'Export Host', '02:00:00:00:00:91', '192.0.2.91', 1)");
        $database->exec("INSERT INTO ping (ip, mac, status, date)
            VALUES ('192.0.2.91', '02:00:00:00:00:91', 'Up', CURRENT_TIMESTAMP)");
        $database->exec("INSERT INTO leases
            (ip, `hardware-ethernet`, `client-hostname`, ends, first_seen, last_seen, active)
            VALUES ('192.0.2.91', '02:00:00:00:00:91', '=unsafe-formula', datetime('now', '+1 day'), datetime('now', '-2 days'), CURRENT_TIMESTAMP, 1)");
        $database->exec("INSERT INTO stats (ip, mac, status, date_begin, date_end, nb_scan) VALUES
            ('192.0.2.91', '02:00:00:00:00:91', 'Down', datetime('now', '-2 hours'), datetime('now', '-1 hour'), 1),
            ('192.0.2.91', '02:00:00:00:00:91', 'Up', datetime('now', '-1 hour'), CURRENT_TIMESTAMP, 2)");
        $database->exec("INSERT INTO scan_snapshots (id, ip, mode, result_hash, content_hash)
            VALUES (91, '192.0.2.91', 'standard', 'export-result', 'export-content')");
        $database->exec("INSERT INTO scans
            (id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id)
            VALUES (91, '192.0.2.91', 'standard', 'complete', 'up', datetime('now', '-10 minutes'), CURRENT_TIMESTAMP, 600, 1, 91)");
        $database->exec("INSERT INTO scan_snapshot_ports
            (snapshot_id, protocol, port, state, service, product, version)
            VALUES (91, 'tcp', 443, 'open', 'https', 'nginx', '1.27')");
        $database->exec("INSERT INTO scan_port_changes
            (scan_id, ip, mode, change_type, protocol, port, current_service, current_version)
            VALUES (91, '192.0.2.91', 'standard', 'appeared', 'tcp', 443, 'https', 'nginx 1.27')");
        $database->exec("INSERT INTO network_anomaly_events
            (network, anomaly_type, subtype, event_type, ip, mac, vendor, important, details_json, dedupe_key)
            VALUES ('192.0.2.0/24', 'unexpected_vendor', 'network_first_seen', 'detected',
                    '192.0.2.91', '02:00:00:00:00:91', 'Export Devices', 1,
                    '{\"vendor\":\"Export Devices\"}', 'export-vendor')");
    }

    private function download(string $dataset, string $format, string $network = '192.0.2.0/24')
    {
        return $this->app()->api()->handle(new Request(
            'GET',
            '/api/exports/' . $dataset,
            ['format' => $format, 'network' => $network],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/exports/' . $dataset],
            [],
        ));
    }
}
