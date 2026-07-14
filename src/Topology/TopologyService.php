<?php

declare(strict_types=1);

namespace FenPing\Topology;

use FenPing\Config\AppConfig;
use FenPing\Inventory\InventoryService;
use FenPing\Network\NetworkManager;

final readonly class TopologyService
{
    public const DISCLAIMER = 'Connections are traceroute or route-table observations and gateway configurations, not verified physical links.';

    public function __construct(
        private AppConfig $config,
        private NetworkManager $networks,
        private InventoryService $inventory,
        private TopologyRepository $repository,
    ) {
    }

    public function snapshot(): array
    {
        $generatedAt = gmdate('c');
        $routeObservation = $this->networks->routeObservations();
        $nodes = [];
        $connections = [];
        $paths = [];
        $applianceId = $this->ensureIpNode($nodes, $this->config->applianceIp);
        $this->addRole($nodes, $applianceId, 'appliance');

        foreach ($this->networks->configured() as $network) {
            $id = 'network:' . $network->cidr;
            $nodes[$id] = [
                'id' => $id,
                'type' => 'network',
                'ip' => null,
                'label' => $network->cidr,
                'network' => $network->cidr,
                'roles' => ['network' => true],
            ];
        }

        $lastObservedAt = null;
        foreach ($this->repository->latestTracePaths() as $observation) {
            $targetIp = $this->validIp($observation['target_ip']);
            if ($targetIp === null) {
                continue;
            }
            $observedAt = $observation['observed_at'];
            if ($observedAt !== '' && ($lastObservedAt === null || $observedAt > $lastObservedAt)) {
                $lastObservedAt = $observedAt;
            }
            $targetNetwork = $this->networkForIp($targetIp);
            $previousId = $applianceId;
            $previousTtl = 0;
            $orderedIds = [$applianceId];
            $protocol = '';
            $port = null;
            $lastHopIp = null;

            foreach ($observation['hops'] as $hop) {
                $hopIp = $this->validIp($hop['ip']);
                if ($hopIp === null || $hop['ttl'] < 1) {
                    continue;
                }
                $hopId = $this->ensureIpNode($nodes, $hopIp);
                $this->addRole($nodes, $hopId, 'hop');
                $this->rememberHostname($nodes, $hopId, $hop['hostname'], $observedAt);
                $protocol = $protocol !== '' ? $protocol : $hop['protocol'];
                $port ??= $hop['port'];
                $missingHops = max(0, $hop['ttl'] - $previousTtl - 1);
                $this->addConnection($connections, [
                    'kind' => 'traceroute_observation',
                    'from' => $previousId,
                    'to' => $hopId,
                    'label' => $missingHops > 0 ? 'Traceroute observation with unknown hops' : 'Traceroute observation',
                    'observed_at' => $observedAt,
                    'missing_hops' => $missingHops,
                    'network' => $targetNetwork,
                    'target' => $targetIp,
                    'scan_id' => $observation['scan_id'],
                    'evidence' => [
                        'source' => 'traceroute',
                        'scan_id' => $observation['scan_id'],
                        'target_ip' => $targetIp,
                        'ttl_from' => $previousTtl,
                        'ttl_to' => $hop['ttl'],
                        'rtt' => $hop['rtt'],
                    ],
                ]);
                $orderedIds[] = $hopId;
                $previousId = $hopId;
                $previousTtl = $hop['ttl'];
                $lastHopIp = $hopIp;
            }

            $targetId = $this->ensureIpNode($nodes, $targetIp);
            $this->addRole($nodes, $targetId, 'target');
            $paths[] = [
                'id' => 'trace:' . $observation['scan_id'],
                'scan_id' => $observation['scan_id'],
                'target_ip' => $targetIp,
                'target_node_id' => $targetId,
                'network' => $targetNetwork,
                'mode' => $observation['mode'],
                'protocol' => $protocol,
                'port' => $port,
                'observed_at' => $observedAt,
                'reached_target' => $lastHopIp === $targetIp,
                'node_ids' => $orderedIds,
            ];
        }

        foreach ($routeObservation['networks'] as $cidr => $route) {
            $networkId = 'network:' . $cidr;
            $gateway = $this->validIp((string) ($route['gateway'] ?? ''));
            $evidence = [
                'source' => 'route_table',
                'network' => $cidr,
                'destination' => $route['destination'],
                'gateway' => $gateway,
                'interface' => $route['interface'],
                'source_address' => $route['source'],
            ];
            if ($gateway !== null) {
                $gatewayId = $this->ensureIpNode($nodes, $gateway);
                $this->addRole($nodes, $gatewayId, 'router');
                $this->addConnection($connections, $this->routeConnection($applianceId, $gatewayId, $cidr, $generatedAt, $evidence));
                $this->addConnection($connections, $this->routeConnection($gatewayId, $networkId, $cidr, $generatedAt, $evidence));
            } else {
                $this->addConnection($connections, $this->routeConnection($applianceId, $networkId, $cidr, $generatedAt, $evidence));
            }
        }

        $this->addGatewayConfigurations($nodes, $connections);
        [$inventoryByIp, $networkCounts] = $this->inventorySnapshot($nodes);
        $this->enrichNodes($nodes, $inventoryByIp);
        $networkRows = $this->networkRows($routeObservation['networks'], $networkCounts);
        $nodes = $this->finalizeNodes($nodes);
        $connections = $this->finalizeConnections($connections);
        usort($paths, static fn(array $left, array $right): int => [$left['network'] ?? '', $left['target_ip']] <=> [$right['network'] ?? '', $right['target_ip']]);

        $routerCount = count(array_filter($nodes, static fn(array $node): bool => in_array('router', $node['roles'], true)));
        $untracedHosts = array_sum(array_column($networkRows, 'untraced_host_count'));
        return [
            'generated_at' => $generatedAt,
            'disclaimer' => self::DISCLAIMER,
            'route_observation_status' => $routeObservation['status'],
            'summary' => [
                'network_count' => count($networkRows),
                'node_count' => count($nodes),
                'connection_count' => count($connections),
                'trace_target_count' => count($paths),
                'router_count' => $routerCount,
                'host_count' => array_sum(array_column($networkRows, 'host_count')),
                'untraced_host_count' => $untracedHosts,
                'last_observed_at' => $lastObservedAt,
            ],
            'networks' => $networkRows,
            'nodes' => $nodes,
            'connections' => $connections,
            'paths' => $paths,
        ];
    }

    private function routeConnection(string $from, string $to, string $network, string $observedAt, array $evidence): array
    {
        return [
            'kind' => 'route_observation', 'from' => $from, 'to' => $to,
            'label' => 'Route-table observation', 'observed_at' => $observedAt,
            'missing_hops' => 0, 'network' => $network, 'target' => null,
            'scan_id' => null, 'evidence' => $evidence,
        ];
    }

    private function addGatewayConfigurations(array &$nodes, array &$connections): void
    {
        $assignments = [];
        $default = $this->validIp($this->config->dhcpDefaultRouter);
        if ($default !== null) {
            $assignments[$default][] = ['source' => 'dhcp_default_router'];
        }
        foreach ($this->repository->gatewayAssignments() as $assignment) {
            $octet = $assignment['router_octet'];
            if (!ctype_digit($octet) || (int) $octet < 1 || (int) $octet > 254) {
                continue;
            }
            $gateway = $this->config->dhcpNetwork->host((int) $octet);
            $assignments[$gateway][] = [
                'source' => 'host_router_override',
                'host_id' => $assignment['host_id'],
                'host_ip' => $assignment['host_ip'],
                'host_name' => $assignment['host_name'],
            ];
        }
        foreach ($assignments as $gateway => $evidenceRows) {
            $routerId = $this->ensureIpNode($nodes, $gateway);
            $this->addRole($nodes, $routerId, 'router');
            foreach ($evidenceRows as $evidence) {
                $this->addConnection($connections, [
                    'kind' => 'gateway_configuration',
                    'from' => 'network:' . $this->config->dhcpNetwork->cidr,
                    'to' => $routerId,
                    'label' => 'Configured gateway assignment',
                    'observed_at' => null,
                    'missing_hops' => 0,
                    'network' => $this->config->dhcpNetwork->cidr,
                    'target' => $evidence['host_ip'] ?? null,
                    'scan_id' => null,
                    'evidence' => $evidence,
                ]);
            }
        }
    }

    private function inventorySnapshot(array $nodes): array
    {
        $byIp = [];
        $counts = [];
        foreach ($this->networks->configured() as $network) {
            $rows = $this->inventory->forNetwork($network->cidr);
            $counts[$network->cidr] = ['host_count' => count($rows), 'untraced_host_count' => 0];
            foreach ($rows as $row) {
                $ip = $this->validIp((string) ($row['ip'] ?? ''));
                if ($ip === null) {
                    continue;
                }
                $byIp[$ip][] = $this->hostPayload($row);
                if (!isset($nodes['ip:' . $ip])) {
                    $counts[$network->cidr]['untraced_host_count']++;
                }
            }
        }
        return [$byIp, $counts];
    }

    private function hostPayload(array $row): array
    {
        $identity = is_array($row['device_identity'] ?? null) ? $row['device_identity'] : null;
        $name = trim((string) ($row['display_name'] ?? '')) ?: trim((string) ($row['name'] ?? ''));
        if ($name === '' && $identity !== null) {
            $name = trim((string) ($identity['container'] ?? ''));
        }
        return [
            'id' => isset($row['id']) && (int) $row['id'] > 0 ? (int) $row['id'] : null,
            'name' => $name,
            'mac' => strtolower(trim((string) ($row['mac'] ?? ''))),
            'status' => (string) ($row['status'] ?? ''),
            'last_seen' => $row['date'] ?? null,
            'vendor' => (string) ($row['vendor'] ?? ''),
            'important' => (int) ($row['important'] ?? 0),
            'device_identity' => $identity,
        ];
    }

    private function enrichNodes(array &$nodes, array $inventoryByIp): void
    {
        foreach ($nodes as &$node) {
            $ip = $node['ip'] ?? null;
            if ($ip === null || !isset($inventoryByIp[$ip])) {
                continue;
            }
            usort($inventoryByIp[$ip], static fn(array $left, array $right): int => [!($left['id'] ?? null), $left['name']] <=> [!($right['id'] ?? null), $right['name']]);
            $node['hosts'] = $inventoryByIp[$ip];
            $node['host'] = $inventoryByIp[$ip][0];
            $node['roles']['host'] = true;
        }
        unset($node);
    }

    private function networkRows(array $routes, array $counts): array
    {
        $rows = [];
        foreach ($this->networks->configured() as $network) {
            $dhcp = $network->cidr === $this->config->dhcpNetwork->cidr;
            $rows[] = [
                'id' => 'network:' . $network->cidr,
                'cidr' => $network->cidr,
                'dhcp' => $dhcp,
                'routed' => $dhcp || isset($routes[$network->cidr]),
                'docker_network_names' => $this->config->dockerNetworkNames[$network->cidr] ?? [],
                'route' => $routes[$network->cidr] ?? null,
                'host_count' => $counts[$network->cidr]['host_count'] ?? 0,
                'untraced_host_count' => $counts[$network->cidr]['untraced_host_count'] ?? 0,
            ];
        }
        return $rows;
    }

    private function ensureIpNode(array &$nodes, string $ip): string
    {
        $id = 'ip:' . $ip;
        $nodes[$id] ??= [
            'id' => $id, 'type' => 'hop', 'ip' => $ip, 'label' => $ip,
            'network' => $this->networkForIp($ip), 'roles' => [],
            '_hostname' => '', '_hostname_observed_at' => '',
        ];
        return $id;
    }

    private function addRole(array &$nodes, string $id, string $role): void
    {
        $nodes[$id]['roles'][$role] = true;
    }

    private function rememberHostname(array &$nodes, string $id, string $hostname, string $observedAt): void
    {
        $hostname = trim($hostname);
        if ($hostname !== '' && $observedAt >= $nodes[$id]['_hostname_observed_at']) {
            $nodes[$id]['_hostname'] = $hostname;
            $nodes[$id]['_hostname_observed_at'] = $observedAt;
        }
    }

    private function addConnection(array &$connections, array $candidate): void
    {
        $key = implode('|', [$candidate['kind'], $candidate['from'], $candidate['to'], $candidate['missing_hops']]);
        if (!isset($connections[$key])) {
            $connections[$key] = [
                'id' => 'connection:' . sha1($key),
                'kind' => $candidate['kind'], 'from' => $candidate['from'], 'to' => $candidate['to'],
                'label' => $candidate['label'], 'observed_at' => $candidate['observed_at'],
                'missing_hops' => $candidate['missing_hops'], 'networks' => [], 'targets' => [],
                'scan_ids' => [], 'evidence' => [], '_evidence' => [],
            ];
        }
        $connection = &$connections[$key];
        if ($candidate['observed_at'] !== null && ($connection['observed_at'] === null || $candidate['observed_at'] > $connection['observed_at'])) {
            $connection['observed_at'] = $candidate['observed_at'];
        }
        if ($candidate['network'] !== null) $connection['networks'][$candidate['network']] = true;
        if ($candidate['target'] !== null) $connection['targets'][$candidate['target']] = true;
        if ($candidate['scan_id'] !== null) $connection['scan_ids'][(int) $candidate['scan_id']] = true;
        $evidenceKey = json_encode($candidate['evidence'], JSON_UNESCAPED_SLASHES);
        if ($evidenceKey !== false && !isset($connection['_evidence'][$evidenceKey])) {
            $connection['_evidence'][$evidenceKey] = true;
            $connection['evidence'][] = $candidate['evidence'];
        }
        unset($connection);
    }

    private function finalizeNodes(array $nodes): array
    {
        foreach ($nodes as &$node) {
            $node['roles'] = array_keys($node['roles']);
            sort($node['roles']);
            if ($node['type'] !== 'network') {
                $node['type'] = in_array('appliance', $node['roles'], true) ? 'appliance'
                    : (in_array('router', $node['roles'], true) ? 'router'
                    : (in_array('host', $node['roles'], true) || in_array('target', $node['roles'], true) ? 'host' : 'hop'));
                $hostname = $node['_hostname'];
                $hostName = trim((string) ($node['host']['name'] ?? ''));
                $node['label'] = $node['type'] === 'appliance' ? 'FenPing'
                    : ($hostName !== '' ? $hostName : ($hostname !== '' ? $hostname : ($node['type'] === 'router' ? 'Router' : $node['ip'])));
                $node['hostname'] = $hostname;
                unset($node['_hostname'], $node['_hostname_observed_at']);
            }
        }
        unset($node);
        $nodes = array_values($nodes);
        $priority = ['appliance' => 0, 'router' => 1, 'hop' => 2, 'host' => 3, 'network' => 4];
        usort($nodes, static fn(array $left, array $right): int => [$priority[$left['type']] ?? 9, $left['id']] <=> [$priority[$right['type']] ?? 9, $right['id']]);
        return $nodes;
    }

    private function finalizeConnections(array $connections): array
    {
        foreach ($connections as &$connection) {
            $connection['networks'] = array_keys($connection['networks']);
            $connection['targets'] = array_keys($connection['targets']);
            $connection['scan_ids'] = array_map('intval', array_keys($connection['scan_ids']));
            sort($connection['networks']); sort($connection['targets']); sort($connection['scan_ids']);
            $connection['observation_count'] = count($connection['evidence']);
            unset($connection['_evidence']);
        }
        unset($connection);
        $connections = array_values($connections);
        usort($connections, static fn(array $left, array $right): int => [$left['kind'], $left['from'], $left['to']] <=> [$right['kind'], $right['from'], $right['to']]);
        return $connections;
    }

    private function networkForIp(string $ip): ?string
    {
        foreach ($this->networks->configured() as $network) {
            if ($network->contains($ip)) return $network->cidr;
        }
        return null;
    }

    private function validIp(string $value): ?string
    {
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $value;
    }
}
