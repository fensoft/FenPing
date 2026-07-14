<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Dhcp\ConfigManager;

final readonly class HostsCommand implements Command
{
    public function __construct(private ConfigManager $config) {}
    public function run(array $arguments): int { return $this->config->run($arguments); }
}
