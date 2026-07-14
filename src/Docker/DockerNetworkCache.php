<?php

declare(strict_types=1);

namespace FenPing\Docker;

use RuntimeException;

final readonly class DockerNetworkCache
{
    public const DEFAULT_PATH = '/run/fenping/docker-networks.json';

    public function __construct(private string $path = self::DEFAULT_PATH)
    {
    }

    public static function pathFromEnvironment(): string
    {
        $path = getenv('DOCKER_NETWORK_CACHE');
        return $path === false || trim($path) === '' ? self::DEFAULT_PATH : trim($path);
    }

    /** @return list<string> */
    public function networks(): array
    {
        $data = $this->read();
        $rows = $data['networks'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $networks = [];
        foreach ($rows as $row) {
            $cidr = is_string($row) ? $row : (is_array($row) && is_string($row['cidr'] ?? null) ? $row['cidr'] : null);
            if ($cidr !== null) {
                $networks[$cidr] = true;
            }
        }
        return array_keys($networks);
    }

    /** @return array<string, list<string>> */
    public function networkNames(): array
    {
        $rows = $this->read()['networks'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['cidr'] ?? null) || !is_array($row['names'] ?? null)) {
                continue;
            }
            foreach ($row['names'] as $name) {
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }
                $result[$row['cidr']][trim($name)] = true;
            }
        }
        foreach ($result as $cidr => $names) {
            $result[$cidr] = array_keys($names);
            sort($result[$cidr], SORT_NATURAL | SORT_FLAG_CASE);
        }
        return $result;
    }

    /** @return list<array{cidr: string, network: string, container: string, ip: string}> */
    public function containers(): array
    {
        $rows = $this->read()['networks'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $containers = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['cidr'] ?? null) || !is_array($row['containers'] ?? null)) {
                continue;
            }
            $cidr = trim($row['cidr']);
            foreach ($row['containers'] as $container) {
                if (!is_array($container)) {
                    continue;
                }
                $network = is_string($container['network'] ?? null) ? trim($container['network']) : '';
                $name = is_string($container['container'] ?? null) ? trim($container['container']) : '';
                $ip = is_string($container['ip'] ?? null) ? trim($container['ip']) : '';
                if ($cidr === '' || $network === '' || $name === ''
                    || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }
                $key = $network . "\0" . $name . "\0" . $ip;
                $containers[$key] = [
                    'cidr' => $cidr,
                    'network' => $network,
                    'container' => $name,
                    'ip' => $ip,
                ];
            }
        }
        $containers = array_values($containers);
        usort($containers, static fn(array $left, array $right): int =>
            [$left['cidr'], $left['network'], $left['container'], $left['ip']]
            <=> [$right['cidr'], $right['network'], $right['container'], $right['ip']]
        );
        return $containers;
    }

    /** @return list<array{cidr: string, network: string, ip: string}> */
    public function gateways(): array
    {
        $rows = $this->read()['networks'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $gateways = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['cidr'] ?? null) || !is_array($row['gateways'] ?? null)) {
                continue;
            }
            $cidr = trim($row['cidr']);
            foreach ($row['gateways'] as $gateway) {
                if (!is_array($gateway)) {
                    continue;
                }
                $network = is_string($gateway['network'] ?? null) ? trim($gateway['network']) : '';
                $ip = is_string($gateway['ip'] ?? null) ? trim($gateway['ip']) : '';
                if ($cidr === '' || $network === ''
                    || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }
                $key = $network . "\0" . $ip;
                $gateways[$key] = [
                    'cidr' => $cidr,
                    'network' => $network,
                    'ip' => $ip,
                ];
            }
        }
        $gateways = array_values($gateways);
        usort($gateways, static fn(array $left, array $right): int =>
            [$left['cidr'], $left['network'], $left['ip']]
            <=> [$right['cidr'], $right['network'], $right['ip']]
        );
        return $gateways;
    }

    /** @return list<array{cidr: string, network: string, container: string, ip: string}> */
    public function containersForIp(string $cidr, string $ip): array
    {
        return array_values(array_filter(
            $this->containers(),
            static fn(array $container): bool => $container['cidr'] === $cidr && $container['ip'] === $ip,
        ));
    }

    /** @return array{cidr: string, network: string, container: string, ip: string}|null */
    public function container(string $network, string $container): ?array
    {
        foreach ($this->containers() as $candidate) {
            if ($candidate['network'] === $network && $candidate['container'] === $container) {
                return $candidate;
            }
        }
        return null;
    }

    public function updatedAt(): ?int
    {
        $value = $this->read()['updated_at'] ?? null;
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** @param list<string|array{cidr: string, names: list<string>, gateways?: list<array{network: string, ip: string}>, containers?: list<array{network: string, container: string, ip: string}>}> $networks */
    public function replace(array $networks, ?int $updatedAt = null): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('failed to create Docker network cache directory');
        }
        $temporary = tempnam($directory, '.docker-networks-');
        if ($temporary === false) {
            throw new RuntimeException('failed to create Docker network cache');
        }
        try {
            $json = json_encode([
                'updated_at' => $updatedAt ?? time(),
                'networks' => array_values($networks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (file_put_contents($temporary, $json, LOCK_EX) === false
                || !chmod($temporary, 0644)
                || !rename($temporary, $this->path)) {
                throw new RuntimeException('failed to replace Docker network cache');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $json = file_get_contents($this->path);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
