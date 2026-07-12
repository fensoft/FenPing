<?php

declare(strict_types=1);

namespace FenPing\Ping;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;

final readonly class PingScanner
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database)
    {
    }

    public function scan(array $ips, array $localIps = []): array
    {
        return $this->backend->pingHosts($ips, $this->config->interface, $localIps);
    }

    public function store(array $hosts): void
    {
        $this->backend->savePingHosts($hosts);
    }
}
