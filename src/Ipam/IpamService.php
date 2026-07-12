<?php

declare(strict_types=1);

namespace FenPing\Ipam;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Vendor\VendorLookup;

final readonly class IpamService
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database, private VendorLookup $vendors)
    {
    }

    public function summary(): array { return $this->backend->getIpam(); }
    public function approve(string $mac): array { return $this->backend->approveDevice($mac); }
    public function unapprove(string $mac): array { return $this->backend->unapproveDevice($mac); }
}
