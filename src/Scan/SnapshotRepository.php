<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;

final readonly class SnapshotRepository
{
    public function __construct(private Backend $backend, private DatabaseManager $database)
    {
    }

    public function read(string $ip, ?array $metadata = null): ?array
    {
        return $this->backend->scanReadSnapshot($ip, $metadata);
    }
}
