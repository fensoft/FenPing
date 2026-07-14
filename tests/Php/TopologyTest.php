<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\Request;
use FenPing\Config\AppConfig;
use FenPing\Network\NetworkManager;
use FenPing\Network\RouteDetector;
use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;
use FenPing\Topology\TopologyRepository;
use FenPing\Topology\TopologyService;

final class TopologyTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->resetDatabase();
    }

    public function testTopologyUsesLatestTraceBearingScansAndAggregatesEvidence(): void
    {
        $database = $this->app()->database()->connection();
        $database->exec(<<<'SQL'
            INSERT INTO ips (id, name, display_name, mac, ip, router) VALUES
              (1, 'host-20', 'Application node', '02:00:00:00:00:20', '192.0.2.20', 2),
              (2, 'untraced', NULL, '02:00:00:00:00:40', '192.0.2.40', NULL);
            INSERT INTO ping (ip, mac, status, date) VALUES
              ('192.0.2.20', '02:00:00:00:00:20', 'Up', CURRENT_TIMESTAMP),
              ('192.0.2.40', '02:00:00:00:00:40', 'Up', CURRENT_TIMESTAMP);
            INSERT INTO scan_snapshots (id, ip, mode, result_hash, content_hash) VALUES
              (1, '192.0.2.10', 'standard', 'result-1', 'content-1'),
              (2, '192.0.2.20', 'standard', 'result-2', 'content-2'),
              (3, '192.0.2.30', 'standard', 'result-3', 'content-3'),
              (4, '192.0.2.10', 'lightweight', 'result-4', 'content-4');
            INSERT INTO scans (id, ip, mode, state, date_begin, date_end, snapshot_id) VALUES
              (1, '192.0.2.10', 'standard', 'complete', '2026-07-10 10:00:00', '2026-07-10 10:01:00', 1),
              (2, '192.0.2.20', 'standard', 'complete', '2026-07-10 11:00:00', '2026-07-10 11:01:00', 2),
              (3, '192.0.2.30', 'standard', 'complete', '2026-07-10 12:00:00', NULL, 3),
              (4, '192.0.2.10', 'lightweight', 'complete', '2026-07-10 13:00:00', '2026-07-10 13:01:00', 4);
            INSERT INTO scan_snapshot_trace_hops (snapshot_id, position, protocol, port, ttl, ip, hostname, rtt) VALUES
              (1, 0, 'tcp', 80, 1, '192.0.2.1', 'gateway.local', 0.2),
              (1, 1, 'tcp', 80, 2, '192.0.2.10', 'host-10.local', 0.5),
              (2, 0, 'tcp', 80, 1, '192.0.2.1', 'gateway.local', 0.2),
              (2, 1, 'tcp', 80, 3, '192.0.2.20', 'host-20.local', 0.8),
              (3, 0, 'tcp', 443, 1, '192.0.2.1', 'gateway.local', 0.2),
              (3, 1, 'tcp', 443, 2, '192.0.2.254', 'edge.local', 0.7);
            SQL);

        $snapshot = $this->service(new ProcessResult(0, implode("\n", [
            '192.0.2.0/24 dev eth0 src 192.0.2.100',
            '198.51.100.0/24 via 192.0.2.1 dev eth0 src 192.0.2.100',
        ])))->snapshot();

        self::assertSame('ok', $snapshot['route_observation_status']);
        self::assertSame(3, $snapshot['summary']['trace_target_count']);
        self::assertSame('2026-07-10 12:00:00', $snapshot['summary']['last_observed_at']);
        self::assertSame(['app_default'], $snapshot['networks'][1]['docker_network_names']);
        self::assertSame(1, $snapshot['networks'][0]['untraced_host_count']);

        $nodes = array_column($snapshot['nodes'], null, 'id');
        self::assertSame('router', $nodes['ip:192.0.2.1']['type']);
        self::assertSame('router', $nodes['ip:192.0.2.2']['type']);
        self::assertSame('Application node', $nodes['ip:192.0.2.20']['label']);
        self::assertSame(1, $nodes['ip:192.0.2.20']['host']['id']);

        $paths = array_column($snapshot['paths'], null, 'target_ip');
        self::assertSame(1, $paths['192.0.2.10']['scan_id']);
        self::assertTrue($paths['192.0.2.10']['reached_target']);
        self::assertFalse($paths['192.0.2.30']['reached_target']);
        self::assertSame('2026-07-10 12:00:00', $paths['192.0.2.30']['observed_at']);

        $shared = array_values(array_filter($snapshot['connections'], static fn(array $connection): bool =>
            $connection['kind'] === 'traceroute_observation'
            && $connection['from'] === 'ip:192.0.2.100'
            && $connection['to'] === 'ip:192.0.2.1'
        ));
        self::assertCount(1, $shared);
        self::assertSame(3, $shared[0]['observation_count']);
        self::assertSame(['192.0.2.10', '192.0.2.20', '192.0.2.30'], $shared[0]['targets']);

        $gap = array_values(array_filter($snapshot['connections'], static fn(array $connection): bool =>
            $connection['kind'] === 'traceroute_observation' && $connection['missing_hops'] === 1
        ));
        self::assertCount(1, $gap);
        self::assertFalse(in_array('ip:192.0.2.30', array_column($snapshot['connections'], 'to'), true));
    }

    public function testTopologyApiIsGuestVisibleAndRouteFailuresDegrade(): void
    {
        $response = $this->app()->api()->handle(new Request(
            'GET', '/api/topology', [], [], [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/topology'],
            [],
        ));
        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('disclaimer', $body);
        self::assertSame(0, $body['summary']['trace_target_count']);
        self::assertSame([], $body['paths']);

        $snapshot = $this->service(new ProcessResult(1, '', 'route command failed'))->snapshot();
        self::assertSame('unavailable', $snapshot['route_observation_status']);
        self::assertSame([], array_filter($snapshot['connections'], static fn(array $connection): bool => $connection['kind'] === 'route_observation'));
    }

    private function service(ProcessResult $routeResult): TopologyService
    {
        $base = $this->app()->config();
        $config = new AppConfig(
            projectDir: $base->projectDir,
            databasePath: $base->databasePath,
            dhcpNetwork: $base->dhcpNetwork,
            extraNetworks: $base->extraNetworks,
            interface: $base->interface,
            applianceIp: $base->applianceIp,
            dhcpDynamicBegin: $base->dhcpDynamicBegin,
            dhcpDynamicEnd: $base->dhcpDynamicEnd,
            password: $base->password,
            secret: $base->secret,
            discordWebhookUrl: $base->discordWebhookUrl,
            dataDir: $base->dataDir,
            dhcpDefaultRouter: $base->dhcpDefaultRouter,
            dockerNetworkNames: ['198.51.100.0/24' => ['app_default']],
        );
        $networks = new NetworkManager($config, new RouteDetector(new TopologyProcessRunner($routeResult)));
        return new TopologyService(
            $config,
            $networks,
            $this->app()->inventory(),
            new TopologyRepository($this->app()->database()),
        );
    }
}

final readonly class TopologyProcessRunner implements ProcessRunner
{
    public function __construct(private ProcessResult $result)
    {
    }

    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        return $this->result;
    }
}
