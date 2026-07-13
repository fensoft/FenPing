<?php

declare(strict_types=1);

namespace FenPing\Config;

use FenPing\Docker\DockerNetworkCache;
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
        public string $discordMention = '',
        public string $telegramBotToken = '',
        public int $inventoryDownRetentionDays = 7,
        public string $dhcpDefaultRouter = '',
        public int $healthFailureWindowHours = 24,
        public int $healthPingMaxAgeMinutes = 30,
        public int $healthDiscoveryMaxAgeMinutes = 120,
        public int $healthLeaseImportMaxAgeMinutes = 5,
        public int $healthOuiMaxAgeDays = 35,
        public int $healthBackupMaxAgeDays = 7,
        public int $healthScanQueueMaxAgeMinutes = 15,
        public int $healthDiskWarningPercent = 80,
        public int $healthDiskCriticalPercent = 90,
        public int $healthDhcpWarningPercent = 80,
        public int $healthDhcpCriticalPercent = 90,
        public array $dockerNetworkNames = [],
    ) {
        if ($healthDiskWarningPercent >= $healthDiskCriticalPercent) {
            throw new InvalidArgumentException('disk warning threshold must be lower than critical threshold');
        }
        if ($healthDhcpWarningPercent >= $healthDhcpCriticalPercent) {
            throw new InvalidArgumentException('DHCP warning threshold must be lower than critical threshold');
        }
        if ($discordMention !== '' && $discordMention !== '@everyone' && !ctype_digit($discordMention)) {
            throw new InvalidArgumentException('DISCORD_MENTION must be @everyone or a numeric Discord user ID');
        }
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
        $dockerCache = new DockerNetworkCache(DockerNetworkCache::pathFromEnvironment());
        $cachedDockerNames = $dockerCache->networkNames();
        $dockerNetworkNames = [];
        foreach ($dockerCache->networks() as $value) {
            try {
                $network = Ipv4Network::from24($value, 'Docker network cache');
            } catch (InvalidArgumentException) {
                continue;
            }
            $names = $cachedDockerNames[$value] ?? [];
            if ($names !== []) {
                $dockerNetworkNames[$network->cidr] = $names;
            }
            if (isset($seen[$network->cidr])) {
                continue;
            }
            $seen[$network->cidr] = true;
            $extraNetworks[] = $network;
        }

        $telegramBotToken = trim(self::env('TELEGRAM_BOT_TOKEN'));
        $discordMention = self::discordMentionEnv();
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
            discordMention: $discordMention,
            telegramBotToken: $telegramBotToken,
            inventoryDownRetentionDays: self::nonNegativeIntEnv('INVENTORY_DOWN_RETENTION_DAYS', 7),
            dhcpDefaultRouter: self::env('DHCP_DEFAULT_ROUTER'),
            healthFailureWindowHours: self::positiveIntEnv('HEALTH_FAILURE_WINDOW_HOURS', 24),
            healthPingMaxAgeMinutes: self::positiveIntEnv('HEALTH_PING_MAX_AGE_MINUTES', 30),
            healthDiscoveryMaxAgeMinutes: self::positiveIntEnv('HEALTH_DISCOVERY_MAX_AGE_MINUTES', 120),
            healthLeaseImportMaxAgeMinutes: self::positiveIntEnv('HEALTH_LEASE_IMPORT_MAX_AGE_MINUTES', 5),
            healthOuiMaxAgeDays: self::positiveIntEnv('HEALTH_OUI_MAX_AGE_DAYS', 35),
            healthBackupMaxAgeDays: self::positiveIntEnv('HEALTH_BACKUP_MAX_AGE_DAYS', 7),
            healthScanQueueMaxAgeMinutes: self::positiveIntEnv('HEALTH_SCAN_QUEUE_MAX_AGE_MINUTES', 15),
            healthDiskWarningPercent: self::percentEnv('HEALTH_DISK_WARNING_PERCENT', 80),
            healthDiskCriticalPercent: self::percentEnv('HEALTH_DISK_CRITICAL_PERCENT', 90),
            healthDhcpWarningPercent: self::percentEnv('HEALTH_DHCP_WARNING_PERCENT', 80),
            healthDhcpCriticalPercent: self::percentEnv('HEALTH_DHCP_CRITICAL_PERCENT', 90),
            dockerNetworkNames: $dockerNetworkNames,
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

    private static function discordMentionEnv(): string
    {
        $value = trim(self::env('DISCORD_MENTION'));
        if ($value === '' || $value === '@everyone') {
            return $value;
        }
        if (preg_match('/^[0-9]{1,30}$/', $value)) {
            return $value;
        }
        if (preg_match('/^@([0-9]{1,30})$/', $value, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^<@([0-9]{1,30})>$/', $value, $matches)) {
            return $matches[1];
        }
        throw new InvalidArgumentException(
            'DISCORD_MENTION must be @everyone or a numeric Discord user ID',
        );
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

    private static function positiveIntEnv(string $name, int $default): int
    {
        $value = self::nonNegativeIntEnv($name, $default);
        if ($value < 1) {
            throw new InvalidArgumentException("$name must be a positive integer");
        }
        return $value;
    }

    private static function percentEnv(string $name, int $default): int
    {
        $value = self::positiveIntEnv($name, $default);
        if ($value > 100) {
            throw new InvalidArgumentException("$name must be between 1 and 100");
        }
        return $value;
    }
}
