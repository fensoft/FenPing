<?php

declare(strict_types=1);

namespace FenPing\Backup;

use FenPing\Backend\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;

final readonly class BackupService
{
    public function __construct(private Backend $backend, private AppConfig $config, private DatabaseManager $database)
    {
    }

    public function backup(array $arguments): int { return $this->backend->runBackupCommand($arguments); }
    public function restore(array $arguments): int { return $this->backend->runRestoreCommand($arguments); }
    public function validateDocument(array $document, string $format, string $label): void { $this->backend->backupValidateDocument($document, $format, $label); }
    public function archiveEntryIsSafe(string $entry): bool { return $this->backend->backupArchiveEntrySafe($entry); }
    public function readJson(string $path, string $label): array { return $this->backend->backupReadJson($path, $label); }
}
