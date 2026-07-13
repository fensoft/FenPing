<?php

declare(strict_types=1);

namespace FenPing\Doctor;

use FenPing\Config\AppConfig;

interface DoctorSystem
{
    public function interfaceExists(string $interface): bool;

    public function interfaceUp(string $interface): bool;

    public function bindError(string $protocol, string $address, int $port, ?string $interface = null): ?string;

    public function listenerError(
        string $protocol,
        string $address,
        int $port,
        ?string $interface,
        string $expectedProcess,
    ): ?string;

    /** @return list<string> */
    public function storageErrors(AppConfig $config): array;
}
