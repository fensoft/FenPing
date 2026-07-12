<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Backend\Backend;

use FenPing\Scan\ProfileCatalog;
use FenPing\Scan\ResultService;
use FenPing\Scan\ScanJobRepository;
use FenPing\Vendor\VendorLookup;

final readonly class ScanController implements Controller
{
    public function __construct(private Backend $backend,
        private ScanJobRepository $jobs,
        private ProfileCatalog $profiles,
        private ResultService $results,
        private VendorLookup $vendors,
        private RouteAdapter $adapter,
    ) {
    }

    public function routes(): array
    {
        return $this->adapter->adapt($this->backend->scansApiRoutes());
    }
}
