<?php

declare(strict_types=1);

namespace FenPing\Service;

interface SshConnector
{
    public function banner(string $host, int $port, float $timeoutSeconds): string;
}
