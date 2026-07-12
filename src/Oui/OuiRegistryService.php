<?php

declare(strict_types=1);

namespace FenPing\Oui;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Http\HttpClient;

final readonly class OuiRegistryService
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database, private HttpClient $http)
    {
    }

    public function refresh(array $arguments): int { return $this->backend->runIeeeOuiRefreshCommand($arguments); }
    public function synchronize(array $arguments): int { return $this->backend->runIeeeOuiSyncCommand($arguments); }
    public function normalizeMac(string $mac): string { return $this->backend->ieeeOuiNormalizeMac($mac); }
}
