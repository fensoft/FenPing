<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Backend\Backend;
use FenPing\Api\JsonResponse;
use FenPing\Api\Route;

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
        $legacy = array_values(array_filter(
            $this->backend->systemApiRoutes(),
            static fn(array $route): bool => $route['pattern'] !== '/health',
        ));
        return [
            new Route('GET', '/health', fn(array $params): array => $this->health->status()),
            new Route('GET', '/health/live', fn(array $params): array => $this->health->liveness()),
            new Route('GET', '/health/ready', function (array $params): JsonResponse {
                $readiness = $this->health->readiness();
                return new JsonResponse($readiness, $readiness['ready'] ? 200 : 503);
            }),
            ...$this->adapter->adapt($legacy),
        ];
    }
}
