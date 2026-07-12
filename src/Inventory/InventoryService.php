<?php

declare(strict_types=1);

namespace FenPing\Inventory;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Scan\ScanJobRepository;
use FenPing\Support\Clock;

final readonly class InventoryService
{
    public function __construct(private Backend $backend,
        private AppConfig $config,
        private DatabaseManager $database,
        private ScanJobRepository $scans,
        private Clock $clock,
    ) {
    }

    public function inventory(): array { return $this->backend->getInventory(); }
    public function run(array $arguments): int { return $this->backend->runInventoryCommand($arguments); }
    public function scheduledTargets(array $hosts, ?int $now = null): array { return $this->backend->inventoryScheduledTargets($hosts, $now); }
    public function initialUnmanagedHour(string $ip): int { return $this->backend->inventoryInitialUnmanagedScanHour($ip); }
}
