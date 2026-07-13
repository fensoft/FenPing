<?php

declare(strict_types=1);

namespace FenPing\Docker;

use JsonException;
use RuntimeException;

final class DockerNetworkParser
{
    /** @return list<array{cidr: string, names: list<string>}> */
    public function parse(string $json): array
    {
        try {
            $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Docker returned invalid network data', previous: $error);
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Docker returned invalid network data');
        }

        $networks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = is_string($row['Name'] ?? null) ? trim($row['Name']) : '';
            $subnets = $this->supportedSubnets($row['IPAM']['Config'] ?? null);
            if ($subnets === []) {
                continue;
            }

            foreach ($subnets as $subnet) {
                if ($subnet['prefix'] === 24) {
                    $this->addNetwork($networks, $this->slice24($subnet['network']), $name);
                }
                $gateway = $subnet['gateway'];
                if ($gateway !== null && $this->contains($subnet, $gateway)) {
                    $this->addNetwork($networks, $this->slice24($gateway), $name);
                }
            }

            $containers = $row['Containers'] ?? [];
            if (!is_array($containers)) {
                continue;
            }
            foreach ($containers as $container) {
                if (!is_array($container)) {
                    continue;
                }
                $address = $this->ipv4Address($container['IPv4Address'] ?? null);
                if ($address === null) {
                    continue;
                }
                foreach ($subnets as $subnet) {
                    if (!$this->contains($subnet, $address)) {
                        continue;
                    }
                    $this->addNetwork($networks, $this->slice24($address), $name);
                    break;
                }
            }
        }

        $result = array_keys($networks);
        usort($result, static fn(string $left, string $right): int => strcmp(
            (string) inet_pton(explode('/', $left, 2)[0]),
            (string) inet_pton(explode('/', $right, 2)[0]),
        ));
        return array_map(static function (string $cidr) use ($networks): array {
            $names = array_keys($networks[$cidr]);
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            return ['cidr' => $cidr, 'names' => $names];
        }, $result);
    }

    /** @param array<string, array<string, true>> $networks */
    private function addNetwork(array &$networks, string $cidr, string $name): void
    {
        $networks[$cidr] ??= [];
        if ($name !== '') {
            $networks[$cidr][$name] = true;
        }
    }

    /** @return list<array{network: string, prefix: int, mask: int, gateway: ?string}> */
    private function supportedSubnets(mixed $configs): array
    {
        if (!is_array($configs)) {
            return [];
        }
        $subnets = [];
        foreach ($configs as $config) {
            if (!is_array($config) || !is_string($config['Subnet'] ?? null)) {
                continue;
            }
            $subnet = $this->parseSubnet($config['Subnet'], $config['Gateway'] ?? null);
            if ($subnet !== null && $subnet['prefix'] <= 24) {
                $subnets[] = $subnet;
            }
        }
        return $subnets;
    }

    /** @return array{network: string, prefix: int, mask: int, gateway: ?string}|null */
    private function parseSubnet(string $value, mixed $gateway): ?array
    {
        if (!preg_match('/^([^\/]+)\/(\d{1,2})$/', trim($value), $matches)) {
            return null;
        }
        $address = $this->ipv4Address($matches[1]);
        $prefix = (int) $matches[2];
        if ($address === null || $prefix < 0 || $prefix > 32) {
            return null;
        }
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
        $networkLong = ip2long($address) & $mask;
        $network = long2ip($networkLong);
        if ($network === false) {
            return null;
        }
        return [
            'network' => $network,
            'prefix' => $prefix,
            'mask' => $mask,
            'gateway' => $this->ipv4Address($gateway),
        ];
    }

    /** @param array{network: string, prefix: int, mask: int, gateway: ?string} $subnet */
    private function contains(array $subnet, string $address): bool
    {
        return (ip2long($address) & $subnet['mask']) === (ip2long($subnet['network']) & $subnet['mask']);
    }

    private function ipv4Address(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $address = explode('/', trim($value), 2)[0];
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? null : $address;
    }

    private function slice24(string $address): string
    {
        $parts = explode('.', $address);
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
    }
}
