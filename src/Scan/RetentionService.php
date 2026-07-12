<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;

final readonly class RetentionService
{
    public function __construct(private Backend $backend, private DatabaseManager $database)
    {
    }

    public function prune(string $ip): void
    {
        $this->backend->scanPruneHistory($ip);
    }
}
