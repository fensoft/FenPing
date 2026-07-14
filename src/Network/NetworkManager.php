<?php

declare(strict_types=1);

namespace FenPing\Network;

use FenPing\Config\AppConfig;
use RuntimeException;

final readonly class NetworkManager
{
    public function __construct(private AppConfig $config, private RouteDetector $routes) {}

    public function configured(): array { return [$this->config->dhcpNetwork, ...$this->config->extraNetworks]; }

    public function forCidr(?string $cidr, bool $requireSelectable = true): Ipv4Network
    {
        $cidr = trim((string) $cidr);
        if ($cidr === '') return $this->config->dhcpNetwork;
        foreach ($this->configured() as $network) {
            if ($network->cidr !== $cidr) continue;
            return $network;
        }
        throw new NetworkPolicyException(400, 'network is not configured');
    }

    public function forIp(string $ip, bool $requireSelectable = true): Ipv4Network
    {
        foreach ($this->configured() as $network) {
            if (!$network->contains($ip)) continue;
            return $network;
        }
        throw new NetworkPolicyException(400, 'IP is outside configured networks');
    }

    public function assertDhcpIp(string $ip): void
    {
        if (!$this->config->dhcpNetwork->contains($ip)) throw new NetworkPolicyException(400, 'IP must be inside the DHCP network');
    }

    public function selectable(Ipv4Network $network): bool
    {
        return true;
    }

    public function descriptors(): array
    {
        $observation = $this->routeObservations();
        return array_map(function (Ipv4Network $network) use ($observation): array {
            $dhcp = $network->cidr === $this->config->dhcpNetwork->cidr;
            $routed = $dhcp || isset($observation['networks'][$network->cidr]);
            return [
                'cidr' => $network->cidr,
                'prefix' => $network->prefix(),
                'dhcp' => $dhcp,
                'routed' => $routed,
                'selectable' => true,
                'docker_network_names' => $this->config->dockerNetworkNames[$network->cidr] ?? [],
            ];
        }, $this->configured());
    }

    /** @return array{status: string, networks: array<string, array>} */
    public function routeObservations(): array
    {
        $inspection = $this->routes->inspect();
        $observations = [];
        foreach ($this->configured() as $network) {
            $route = RouteDetector::coveringRoute($inspection['routes'], $network);
            if ($route !== null) {
                $observations[$network->cidr] = $route;
            }
        }
        return ['status' => $inspection['status'], 'networks' => $observations];
    }

    public function nextScheduled(string $job): Ipv4Network
    {
        if (!in_array($job, ['ping', 'inventory'], true)) throw new RuntimeException('invalid network rotation job');
        $eligible = array_values(array_filter($this->configured(), fn(Ipv4Network $network): bool => $this->selectable($network)));
        if ($eligible === []) return $this->config->dhcpNetwork;
        $stateDir = $this->config->stateDir();
        if (!is_dir($stateDir) && !mkdir($stateDir, 0770, true) && !is_dir($stateDir)) throw new RuntimeException('failed to create network rotation state directory');
        $path = $stateDir . '/network-rotation.json';
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('failed to lock network rotation state');
        }
        try {
            $state = [];
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (is_array($decoded)) $state = $decoded;
            }
            $selected = $eligible[0];
            $last = (string) ($state[$job] ?? '');
            foreach ($eligible as $index => $network) {
                if ($network->cidr === $last) { $selected = $eligible[($index + 1) % count($eligible)]; break; }
            }
            $state[$job] = $selected->cidr;
            $temporary = $path . '.tmp-' . getmypid();
            if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false || !rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('failed to save network rotation state');
            }
            return $selected;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
