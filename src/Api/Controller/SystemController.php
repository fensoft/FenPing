<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Backend\Backend;

use FenPing\Health\HealthService;
use FenPing\Inventory\InventoryService;
use FenPing\Ping\PingScanner;
use FenPing\Status\NotificationService;

final readonly class SystemController implements Controller
{
    public function __construct(private Backend $backend,
        private HealthService $health,
        private InventoryService $inventory,
        private NotificationService $notifications,
        private PingScanner $ping,
        private RouteAdapter $adapter,
    ) {
    }

    public function routes(): array
    {
        return $this->adapter->adapt($this->backend->systemApiRoutes());
    }
}
