<?php

declare(strict_types=1);

namespace FenPing\Ping;

interface PingScannerGateway
{
    /**
     * @param array<int|string, string> $ips
     * @param list<string> $localIps
     * @return list<array{ip: string, mac: string, status: string}>
     */
    public function scan(array $ips, array $localIps = []): array;
}
