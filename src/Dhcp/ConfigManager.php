<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;

final readonly class ConfigManager
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database)
    {
    }

    public function run(array $arguments = []): int { return $this->backend->runHostsCommand($arguments); }
    public function render(): array { return $this->backend->buildDnsmasqFiles(); }
    public function synchronize(): void { $this->backend->syncDnsmasqFromDatabase(); }
}
