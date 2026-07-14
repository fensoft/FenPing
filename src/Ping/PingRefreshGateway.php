<?php

declare(strict_types=1);

namespace FenPing\Ping;

interface PingRefreshGateway
{
    public function refresh(string $networkCidr): void;
}
