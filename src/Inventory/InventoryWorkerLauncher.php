<?php

declare(strict_types=1);

namespace FenPing\Inventory;

interface InventoryWorkerLauncher
{
    public function start(): void;
}
