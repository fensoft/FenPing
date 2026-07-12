<?php

declare(strict_types=1);

namespace FenPing\Vendor;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;

final readonly class VendorLookup
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database)
    {
    }

    public function forMac(string $mac): string
    {
        return $this->backend->getVendor($mac);
    }
}
