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
        public int $scanGlobalConcurrency = 4,
        public int $scanNetworkConcurrency = 2,
        public int $scanNetworkDailyBudget = 254,
        public array $scanNetworkOverrides = [],
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
        if ($scanGlobalConcurrency < 1 || $scanGlobalConcurrency > 20) {
            throw new InvalidArgumentException('SCAN_GLOBAL_CONCURRENCY must be between 1 and 20');
        }
        if ($scanNetworkConcurrency < 1 || $scanNetworkConcurrency > $scanGlobalConcurrency) {
            throw new InvalidArgumentException('SCAN_NETWORK_CONCURRENCY must be between 1 and SCAN_GLOBAL_CONCURRENCY');
        }
        if ($scanNetworkDailyBudget < 1 || $scanNetworkDailyBudget > 65535) {
            throw new InvalidArgumentException('SCAN_NETWORK_DAILY_BUDGET must be between 1 and 65535');
        }
        foreach ($scanNetworkOverrides as $cidr => $limits) {
            $network = Ipv4Network::from24((string) $cidr, 'SCAN_NETWORK_OVERRIDES network');
            if ($network->cidr !== $cidr || !is_array($limits)) {
                throw new InvalidArgumentException('invalid SCAN_NETWORK_OVERRIDES entry');
            }
            $concurrency = (int) ($limits['concurrency'] ?? 0);
            $budget = (int) ($limits['daily_budget'] ?? 0);
            if ($concurrency < 1 || $concurrency > $scanGlobalConcurrency) {
                throw new InvalidArgumentException("SCAN_NETWORK_OVERRIDES concurrency for $cidr must be between 1 and SCAN_GLOBAL_CONCURRENCY");
            }
            if ($budget < 1 || $budget > 65535) {
                throw new InvalidArgumentException("SCAN_NETWORK_OVERRIDES daily budget for $cidr must be between 1 and 65535");
            }
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
            scanGlobalConcurrency: self::boundedPositiveIntEnv('SCAN_GLOBAL_CONCURRENCY', 4, 20),
            scanNetworkConcurrency: self::boundedPositiveIntEnv('SCAN_NETWORK_CONCURRENCY', 2, 20),
            scanNetworkDailyBudget: self::boundedPositiveIntEnv('SCAN_NETWORK_DAILY_BUDGET', 254, 65535),
            scanNetworkOverrides: self::scanNetworkOverridesEnv(),
        );
    }

    public function scanLimitsForNetwork(string $cidr): array
    {
        return $this->scanNetworkOverrides[$cidr] ?? [
            'concurrency' => $this->scanNetworkConcurrency,
            'daily_budget' => $this->scanNetworkDailyBudget,
        ];
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

    private static function boundedPositiveIntEnv(string $name, int $default, int $maximum): int
    {
        $value = self::positiveIntEnv($name, $default);
        if ($value > $maximum) {
            throw new InvalidArgumentException("$name must be between 1 and $maximum");
        }
        return $value;
    }

    private static function scanNetworkOverridesEnv(): array
    {
        $raw = trim(self::env('SCAN_NETWORK_OVERRIDES'));
        if ($raw === '') {
            return [];
        }

        $overrides = [];
        foreach (explode(',', $raw) as $entry) {
            $parts = array_map('trim', explode(':', trim($entry)));
            if (count($parts) !== 3) {
                throw new InvalidArgumentException('SCAN_NETWORK_OVERRIDES must use CIDR:concurrency:daily_budget entries');
            }
            [$cidr, $concurrencyValue, $budgetValue] = $parts;
            $network = Ipv4Network::from24($cidr, 'SCAN_NETWORK_OVERRIDES network');
            if (isset($overrides[$network->cidr])) {
                throw new InvalidArgumentException("duplicate SCAN_NETWORK_OVERRIDES network: {$network->cidr}");
            }
            if (!ctype_digit($concurrencyValue) || !ctype_digit($budgetValue)) {
                throw new InvalidArgumentException('SCAN_NETWORK_OVERRIDES limits must be positive integers');
            }
            $overrides[$network->cidr] = [
                'concurrency' => (int) $concurrencyValue,
                'daily_budget' => (int) $budgetValue,
            ];
        }
        return $overrides;
    }
}
