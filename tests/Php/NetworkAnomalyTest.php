<?php

declare(strict_types=1);

namespace FenPing\Tests;

use DateTimeImmutable;
use DateTimeZone;
use FenPing\Anomaly\NetworkChurnAnalyzer;
use FenPing\Api\Request;

final class NetworkAnomalyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
        $this->app()->database()->connection()->exec("
            INSERT OR REPLACE INTO oui_vendors (prefix_length, prefix, vendor) VALUES
              (6, '001122', 'Baseline Devices'),
              (6, '10BBCC', 'Unexpected Devices')
        ");
    }

    public function testBootstrapIsSilentThenVendorAndIpChangesArePersisted(): void
    {
        $network = $this->app()->config()->dhcpNetwork;
        $first = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        self::assertSame([], $this->app()->anomalies()->observePing($network, [
            $this->host('192.0.2.10', '00:11:22:00:00:10'),
        ], $first));

        $events = $this->app()->anomalies()->observePing($network, [
            $this->host('192.0.2.11', '00:11:22:00:00:10'),
            $this->host('192.0.2.20', '10:bb:cc:00:00:20'),
        ], $first->modify('+15 minutes'));
        self::assertEqualsCanonicalizing(['ip_change', 'unexpected_vendor'], array_values(array_unique(array_column($events, 'anomaly_type'))));
        $move = array_values(array_filter($events, static fn(array $event): bool => $event['anomaly_type'] === 'ip_change'))[0];
        self::assertSame('192.0.2.10', $move['previous_ip']);
        self::assertSame('192.0.2.11', $move['ip']);

        $notify = $this->app()->notifications()->recent(24);
        self::assertSame(2, $notify['summary']['anomaly_total']);
        self::assertEqualsCanonicalizing(['ip_change', 'unexpected_vendor'], array_values(array_unique(array_column($notify['anomaly_changes'], 'type'))));
    }

    public function testDuplicateMacProducesOneDetectionAndOneResolution(): void
    {
        $network = $this->app()->config()->dhcpNetwork;
        $first = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->app()->anomalies()->observePing($network, [
            $this->host('192.0.2.10', '00:11:22:00:00:10'),
        ], $first);

        $detected = $this->app()->anomalies()->observePing($network, [
            $this->host('192.0.2.10', '00:11:22:00:00:10'),
            $this->host('192.0.2.11', '00:11:22:00:00:10'),
        ], $first->modify('+15 minutes'));
        self::assertCount(1, $detected);
        self::assertSame('duplicate_identity', $detected[0]['anomaly_type']);
        self::assertSame('duplicate_mac', $detected[0]['subtype']);
        self::assertSame('detected', $detected[0]['event_type']);

        self::assertSame([], $this->app()->anomalies()->observePing($network, [
            $this->host('192.0.2.10', '00:11:22:00:00:10'),
            $this->host('192.0.2.11', '00:11:22:00:00:10'),
        ], $first->modify('+30 minutes')));
        $resolved = $this->app()->anomalies()->observePing($network, [
            $this->host('192.0.2.10', '00:11:22:00:00:10'),
        ], $first->modify('+45 minutes'));
        self::assertCount(1, $resolved);
        self::assertSame('resolved', $resolved[0]['event_type']);
    }

    public function testBootstrapDuplicateHostnameIsSilentUntilItRecurs(): void
    {
        $database = $this->app()->database()->connection();
        $database->exec("INSERT INTO leases
            (ip, `hardware-ethernet`, `client-hostname`, ends, first_seen, last_seen, active) VALUES
            ('192.0.2.10', '00:11:22:00:00:10', 'Shared-Name.', datetime('now', '+1 day'), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1),
            ('192.0.2.20', '00:11:22:00:00:20', 'shared-name', datetime('now', '+1 day'), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)");
        $network = $this->app()->config()->dhcpNetwork;
        $first = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $hosts = [
            $this->host('192.0.2.10', '00:11:22:00:00:10'),
            $this->host('192.0.2.20', '00:11:22:00:00:20'),
        ];
        self::assertSame([], $this->app()->anomalies()->observePing($network, $hosts, $first));
        self::assertSame([], $this->app()->anomalies()->observePing($network, $hosts, $first->modify('+15 minutes')));

        $database->exec("UPDATE leases SET `client-hostname`='unique-name' WHERE ip='192.0.2.20'");
        self::assertSame([], $this->app()->anomalies()->observePing($network, $hosts, $first->modify('+30 minutes')));
        $database->exec("UPDATE leases SET `client-hostname`='SHARED-NAME.' WHERE ip='192.0.2.20'");
        $events = $this->app()->anomalies()->observePing($network, $hosts, $first->modify('+45 minutes'));
        self::assertCount(1, $events);
        self::assertSame('duplicate_hostname', $events[0]['subtype']);
        self::assertSame('shared-name', $events[0]['hostname']);
    }

    public function testAppearedPortsJoinUnifiedFeedAndHoursAreValidated(): void
    {
        $database = $this->app()->database()->connection();
        $database->exec("
            INSERT INTO scan_port_changes
              (scan_id, ip, mode, change_type, protocol, port, current_service, created_at)
            VALUES (10, '192.0.2.10', 'standard', 'appeared', 'tcp', 8443, 'https', CURRENT_TIMESTAMP)
        ");
        $notify = $this->app()->notifications()->recent();
        self::assertSame('open_port', $notify['anomaly_changes'][0]['type']);
        self::assertSame(8443, $notify['anomaly_changes'][0]['details']['port']);
        self::assertSame(1, $notify['summary']['total'], 'the legacy port row and anomaly projection count once');

        $response = $this->app()->api()->handle(new Request(
            'GET', '/api/notify?hours=bad', ['hours' => 'bad'], [], [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/notify?hours=bad'], [],
        ));
        self::assertSame(400, $response->status);
    }

    public function testBalancedChurnThresholdsUseSuccessfulHourlyBaselineAndSuppressBlips(): void
    {
        $database = $this->app()->database()->connection();
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $run = $database->prepare('INSERT INTO network_observation_runs (network, observed_at) VALUES (:network, :time)');
        for ($hours = 25; $hours >= 2; $hours--) {
            $run->execute(['network' => '192.0.2.0/24', 'time' => $at->modify("-$hours hours")->format('Y-m-d H:i:s')]);
        }
        $event = $database->prepare("
            INSERT INTO network_presence_events (network, mac, ip, change_type, important, occurred_at)
            VALUES ('192.0.2.0/24', :mac, '192.0.2.10', :type, 0, :time)
        ");
        for ($index = 0; $index < 6; $index++) {
            $event->execute([
                'mac' => '00:11:22:00:00:10', 'type' => $index % 2 === 0 ? 'arrival' : 'departure',
                'time' => $at->modify('-' . (55 - $index * 10) . ' minutes')->format('Y-m-d H:i:s'),
            ]);
        }
        $event->execute(['mac' => '00:11:22:00:00:20', 'type' => 'arrival', 'time' => $at->modify('-90 seconds')->format('Y-m-d H:i:s')]);
        $event->execute(['mac' => '00:11:22:00:00:20', 'type' => 'departure', 'time' => $at->modify('-30 seconds')->format('Y-m-d H:i:s')]);

        $analyzer = new NetworkChurnAnalyzer();
        self::assertSame(6, $analyzer->flappingMacs($database, '192.0.2.0/24', $at, 6)['00:11:22:00:00:10']);
        self::assertArrayNotHasKey('00:11:22:00:00:20', $analyzer->flappingMacs($database, '192.0.2.0/24', $at, 1));
        $churn = $analyzer->networkChurn($database, '192.0.2.0/24', $at);
        self::assertNotNull($churn);
        self::assertSame(8, $churn['transition_count']);
        self::assertSame(0, $churn['baseline_percentile']);
    }

    public function testDeliveryRulesSeparateNormalAndImportantAnomalies(): void
    {
        $repository = $this->app()->notificationRules();
        $rules = $repository->notificationDefaultRules();
        $rules['network_anomalies']['unexpected_vendors']['normal'] = false;
        $rules['network_anomalies']['open_ports']['normal'] = false;
        $repository->notificationRulesUpdate($rules);

        $vendors = $repository->filterAnomalies([
            ['anomaly_type' => 'unexpected_vendor', 'important' => 0],
            ['anomaly_type' => 'unexpected_vendor', 'important' => 1],
        ]);
        self::assertCount(1, $vendors);
        self::assertSame(1, $vendors[0]['important']);

        $ports = $repository->filterServiceChanges([
            ['change_type' => 'appeared', 'important' => 0],
            ['change_type' => 'appeared', 'important' => 1],
        ]);
        self::assertCount(1, $ports);
        self::assertSame(1, $ports[0]['important']);
    }

    private function host(string $ip, string $mac, string $status = 'Up'): array
    {
        return ['ip' => $ip, 'mac' => $mac, 'status' => $status];
    }
}
