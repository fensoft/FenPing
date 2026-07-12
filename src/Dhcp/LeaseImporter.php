<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;

final readonly class LeaseImporter
{
    public function __construct(private Backend $backend, private DatabaseManager $database)
    {
    }

    public function import(): void
    {
        $this->backend->importDnsmasqLeases();
    }
}
