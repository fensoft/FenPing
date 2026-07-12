<?php

declare(strict_types=1);

namespace FenPing\Config;

use FenPing\Network\Ipv4Network;
use InvalidArgumentException;

final readonly class AppConfig
{
    public string $network;

    public function __construct(
        public string $projectDir,
        public string $databasePath,
        public Ipv4Network $dhcpNetwork,
        public array $extraNetworks,
        public string $interface,
        public string $applianceIp,
        public string $dhcpDynamicBegin,
        public string $dhcpDynamicEnd,
        public string $password,
        public string $secret,
        public string $discordWebhookUrl,
        public string $dataDir,
        public int $inventoryDownRetentionDays = 7,
    ) {
        $this->network = $dhcpNetwork->prefix();
    }

    public static function fromEnvironment(string $projectDir): self
    {
        if (($legacyNetwork = getenv('NETWORK')) !== false && trim($legacyNetwork) !== '') {
            throw new InvalidArgumentException('NETWORK is no longer supported; configure DHCP_NETWORK as a canonical /24 CIDR');
        }
        $dataDir = rtrim(self::env('FENPING_DATA_DIR', '/var/lib/fenping'), '/');
        if ($dataDir === '') {
            $dataDir = '/var/lib/fenping';
        }

        $dhcpNetwork = Ipv4Network::from24(self::requiredEnv('DHCP_NETWORK'), 'DHCP_NETWORK');
        $extraNetworks = [];
        $seen = [$dhcpNetwork->cidr => true];
        $extras = trim(self::env('EXTRA_NETWORKS'));
        foreach ($extras === '' ? [] : explode(',', $extras) as $value) {
            $network = Ipv4Network::from24($value, 'EXTRA_NETWORKS');
            if (isset($seen[$network->cidr])) throw new InvalidArgumentException("duplicate configured network: {$network->cidr}");
            $seen[$network->cidr] = true;
            $extraNetworks[] = $network;
        }

        return new self(
            projectDir: rtrim($projectDir, '/'),
            databasePath: self::env('DATABASE_PATH', $dataDir . '/database/fenping.sqlite3'),
            dhcpNetwork: $dhcpNetwork,
            extraNetworks: $extraNetworks,
            interface: self::env('IFACE', self::env('INTERFACE', self::env('HOST_INTERFACE', 'eth0'))),
            applianceIp: self::env('IP', '192.168.0.100'),
            dhcpDynamicBegin: self::env('DHCP_DYNAMIC_BEGIN', '200'),
            dhcpDynamicEnd: self::env('DHCP_DYNAMIC_END', '250'),
            password: self::env('PASSWORD'),
            secret: self::env('SECRET', 'token'),
            discordWebhookUrl: self::env('DISCORD_WEBHOOK_URL'),
            dataDir: $dataDir,
            inventoryDownRetentionDays: self::nonNegativeIntEnv('INVENTORY_DOWN_RETENTION_DAYS', 7),
        );
    }

    public function netbootDir(): string
    {
        return $this->dataDir . '/netboot';
    }

    public function backupDir(): string
    {
        return $this->dataDir . '/backups';
    }

    public function stateDir(): string
    {
        return $this->dataDir . '/state';
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : $value;
    }

    private static function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new InvalidArgumentException("$name is required");
        }
        return trim($value);
    }

    private static function nonNegativeIntEnv(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }
        $value = trim($value);
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException("$name must be a non-negative integer");
        }
        $parsed = (int) $value;
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        if ((string) $parsed !== $normalized) {
            throw new InvalidArgumentException("$name must be a non-negative integer");
        }
        return $parsed;
    }
}
