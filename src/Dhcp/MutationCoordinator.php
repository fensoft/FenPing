<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;

final readonly class MutationCoordinator
{
    public function __construct(private Backend $backend,
        private DatabaseManager $database,
        private ConfigManager $config,
    ) {
    }

    public function commit(callable $mutation): array
    {
        return $this->backend->commitDhcpMutation($mutation);
    }
}
