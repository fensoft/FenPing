<?php

declare(strict_types=1);

namespace FenPing\Docker;

interface DockerNetworkSource
{
    public function available(): bool;

    /** @return list<array{cidr: string, names: list<string>, gateways?: list<array{network: string, ip: string}>, containers?: list<array{network: string, container: string, ip: string}>}> */
    public function networks(): array;

    /** @return list<string> */
    public function eventCommand(): array;
}
