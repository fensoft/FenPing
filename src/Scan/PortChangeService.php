<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;

final readonly class PortChangeService
{
    public function __construct(private Backend $backend, private DatabaseManager $database)
    {
    }

    public function backfill(): int { return $this->backend->scanPortChangesBackfill(); }
    public function compare(array $previous, array $current): array { return $this->backend->scanComparePorts($previous, $current); }
}
