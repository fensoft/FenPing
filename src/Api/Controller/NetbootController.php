<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Backend\Backend;

use FenPing\Dhcp\MutationCoordinator;
use FenPing\Netboot\NetbootImageService;

final readonly class NetbootController implements Controller
{
    public function __construct(private Backend $backend,
        private NetbootImageService $netboot,
        private MutationCoordinator $mutations,
        private RouteAdapter $adapter,
    ) {
    }

    public function routes(): array
    {
        return $this->adapter->adapt($this->backend->netbootApiRoutes());
    }
}
