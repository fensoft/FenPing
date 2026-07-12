<?php

declare(strict_types=1);

namespace FenPing\Host;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;

final readonly class CategoryRepository
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database)
    {
    }

    public function create(string $ip, string $name): void { $this->backend->addCategory($ip, $name); }
    public function rename(string $ip, string $name): int { return $this->backend->renameCategory($ip, $name); }
    public function delete(string $ip): void { $this->backend->delCategory($ip); }
}
