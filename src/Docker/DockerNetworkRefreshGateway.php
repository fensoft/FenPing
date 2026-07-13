<?php

declare(strict_types=1);

namespace FenPing\Docker;

interface DockerNetworkRefreshGateway
{
    /** @return array{status: string, networks: int, updated_at: ?int} */
    public function refresh(): array;
}
