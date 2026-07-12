<?php

declare(strict_types=1);

namespace FenPing\Config;

final readonly class AppConfig
{
    public function __construct(
        public string $projectDir,
        public string $databasePath,
        public string $network,
        public string $interface,
        public string $applianceIp,
        public string $dhcpDynamicBegin,
        public string $dhcpDynamicEnd,
        public string $password,
        public string $secret,
        public string $discordWebhookUrl,
        public string $dataDir,
    ) {
    }

    public static function fromEnvironment(string $projectDir): self
    {
        $dataDir = rtrim(self::env('FENPING_DATA_DIR', '/var/lib/fenping'), '/');
        if ($dataDir === '') {
            $dataDir = '/var/lib/fenping';
        }

        return new self(
            projectDir: rtrim($projectDir, '/'),
            databasePath: self::env('DATABASE_PATH', '/var/lib/fenping/database/fenping.sqlite3'),
            network: self::env('NETWORK', '192.168.0'),
            interface: self::env('IFACE', self::env('INTERFACE', self::env('HOST_INTERFACE', 'eth0'))),
            applianceIp: self::env('IP', '192.168.0.100'),
            dhcpDynamicBegin: self::env('DHCP_DYNAMIC_BEGIN', '200'),
            dhcpDynamicEnd: self::env('DHCP_DYNAMIC_END', '250'),
            password: self::env('PASSWORD'),
            secret: self::env('SECRET', 'token'),
            discordWebhookUrl: self::env('DISCORD_WEBHOOK_URL'),
            dataDir: $dataDir,
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
}
