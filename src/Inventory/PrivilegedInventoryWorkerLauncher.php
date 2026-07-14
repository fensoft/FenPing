<?php

declare(strict_types=1);

namespace FenPing\Inventory;

use FenPing\Config\AppConfig;

final readonly class PrivilegedInventoryWorkerLauncher implements InventoryWorkerLauncher
{
    public function __construct(private AppConfig $config)
    {
    }

    public function start(): void
    {
        $command = '/usr/bin/doas /usr/bin/php '
            . escapeshellarg($this->config->projectDir . '/cli.php')
            . ' inventory --work';
        exec($command . ' </dev/null >/dev/null 2>&1 &');
    }
}
