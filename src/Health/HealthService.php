<?php

declare(strict_types=1);

namespace FenPing\Health;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;
use FenPing\Support\Clock;

final readonly class HealthService
{
    public function __construct(private Backend $backend, private DatabaseManager $database, private Clock $clock)
    {
    }

    public function status(): array
    {
        return $this->backend->getHealth();
    }
}
