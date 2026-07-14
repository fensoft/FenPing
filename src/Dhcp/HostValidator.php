<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use FenPing\Network\NetworkManager;
use InvalidArgumentException;

final readonly class HostValidator
{
    public function __construct(private NetworkManager $networks)
    {
    }

    public function create(mixed $ip, mixed $mac): array
    {
        $normalizedIp = $this->ip($ip, true);
        $this->networks->assertDhcpIp($normalizedIp);
        return ['ip' => $normalizedIp, 'mac' => $this->mac($mac)];
    }

    public function edit(mixed $ip, mixed $mac, mixed $name, mixed $router, mixed $dns): array
    {
        $normalizedIp = $this->ip($ip, true);
        $this->networks->assertDhcpIp($normalizedIp);
        return [
            'ip' => $normalizedIp,
            'mac' => $this->mac($mac),
            'name' => $this->hostname($name),
            'router' => $this->router($router),
            'dns' => $this->dnsServers($dns),
        ];
    }

    public function ip(mixed $value, bool $required = true): ?string
    {
        $ip = $this->scalarText($value, 'ip');
        if ($ip === '') {
            if ($required) {
                throw new InvalidArgumentException('ip is required');
            }
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('invalid ip');
        }
        return $ip;
    }

    public function mac(mixed $value, bool $required = true): string
    {
        $mac = strtolower(str_replace('-', ':', $this->scalarText($value, 'mac')));
        if ($mac === '') {
            if ($required) {
                throw new InvalidArgumentException('mac is required');
            }
            return '';
        }
        if (preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) !== 1) {
            throw new InvalidArgumentException('invalid mac; expected six hexadecimal octets');
        }
        return $mac;
    }

    public function hostname(mixed $value, bool $required = false): string
    {
        $name = $this->scalarText($value, 'name');
        if ($name === '') {
            if ($required) {
                throw new InvalidArgumentException('host name is required');
            }
            return '';
        }
        if (strlen($name) > 50
            || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $name) !== 1) {
            throw new InvalidArgumentException(
                'invalid host name; use a single DNS label containing letters, numbers, or hyphens',
            );
        }
        return $name;
    }

    public function router(mixed $value): ?string
    {
        $router = $this->scalarText($value, 'router');
        if ($router === '') {
            return null;
        }
        if (!ctype_digit($router) || (int) $router < 1 || (int) $router > 254) {
            throw new InvalidArgumentException('invalid router; expected a host number from 1 to 254');
        }
        return (string) (int) $router;
    }

    public function dnsServers(mixed $value): ?string
    {
        $dns = $this->scalarText($value, 'dns');
        if ($dns === '') {
            return null;
        }
        $servers = preg_split('/[\s,;]+/', $dns, -1, PREG_SPLIT_NO_EMPTY);
        if ($servers === false || $servers === []) {
            throw new InvalidArgumentException('invalid dns servers');
        }
        $normalized = [];
        foreach ($servers as $server) {
            if (filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException("invalid dns server: $server");
            }
            $normalized[$server] = true;
        }
        return implode(' ', array_keys($normalized));
    }

    public function bootFilename(mixed $value): string
    {
        $filename = $this->scalarText($value, 'netboot filename');
        if ($filename === '') {
            return '';
        }
        if (basename($filename) !== $filename
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) !== 1) {
            throw new InvalidArgumentException('invalid netboot filename');
        }
        return $filename;
    }

    private function scalarText(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("invalid $field");
        }
        return trim((string) $value);
    }
}
