<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Backup\BackupService;
use InvalidArgumentException;

final readonly class BackupCommand implements Command
{
    public function __construct(private BackupService $backups, private string $action)
    {
        if (!in_array($action, ['backup', 'restore', 'verify', 'maintenance', 'restore-stage'], true)) throw new InvalidArgumentException('invalid backup command action');
    }
    public function run(array $arguments): int
    {
        return match ($this->action) {
            'backup' => $this->backups->backup($arguments), 'restore' => $this->backups->restore($arguments),
            'verify' => $this->backups->verify($arguments), 'maintenance' => $this->backups->maintenance($arguments),
            'restore-stage' => $this->backups->restoreStage($arguments),
        };
    }
}
