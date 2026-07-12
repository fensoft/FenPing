<?php

declare(strict_types=1);

namespace FenPing\Host;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;
use FenPing\Scan\ProfileCatalog;

final readonly class HostRepository
{
    public function __construct(private Backend $backend, private DatabaseManager $database)
    {
    }

    public function byId(int $id): array|false { return $this->backend->getId($id); }
    public function byIp(string $ip): array|false { return $this->backend->getIp($ip); }
    public function byMac(string $mac): array|false { return $this->backend->getMac($mac); }
    public function create(string $ip, string $mac): int { return (int) $this->backend->create($ip, $mac); }
    public function delete(int $id): int { return $this->backend->del($id); }

    public function update(
        int $id,
        ?string $ip,
        string $mac,
        string $name,
        mixed $repeater,
        mixed $important,
        mixed $web,
        ?string $router,
        ?string $dns,
        ?int $netbootImageId,
        string $profile = ProfileCatalog::MANAGED_DEFAULT,
        int $intervalHours = ProfileCatalog::MANAGED_INTERVAL_HOURS,
    ): int {
        return $this->backend->edit($id, $ip, $mac, $name, $repeater, $important, $web, $router, $dns, $netbootImageId, $profile, $intervalHours);
    }
}
