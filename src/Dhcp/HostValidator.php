<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use FenPing\Backend\Backend;

final class HostValidator
{
    public function __construct(private readonly Backend $backend) {}

    public function create(mixed $ip, mixed $mac): array { return $this->backend->validateDhcpHostCreate($ip, $mac); }
    public function edit(mixed $ip, mixed $mac, mixed $name, mixed $router, mixed $dns): array { return $this->backend->validateDhcpHostEdit($ip, $mac, $name, $router, $dns); }
    public function mac(mixed $value, bool $required = true): string { return $this->backend->normalizeDhcpMac($value, $required); }
}
