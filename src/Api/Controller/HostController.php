<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Backend\Backend;

use FenPing\Dhcp\HostValidator;
use FenPing\Dhcp\MutationCoordinator;
use FenPing\Host\CategoryRepository;
use FenPing\Host\HostRepository;
use FenPing\Netboot\NetbootImageService;
use FenPing\Scan\ResultService;
use FenPing\Scan\ScanJobRepository;
use FenPing\Status\StatusHistoryService;

final readonly class HostController implements Controller
{
    public function __construct(private Backend $backend,
        private HostRepository $hosts,
        private CategoryRepository $categories,
        private StatusHistoryService $history,
        private ScanJobRepository $scans,
        private ResultService $results,
        private NetbootImageService $netboot,
        private HostValidator $validator,
        private MutationCoordinator $mutations,
        private RouteAdapter $adapter,
    ) {
    }

    public function routes(): array
    {
        return $this->adapter->adapt($this->backend->hostsApiRoutes());
    }
}
