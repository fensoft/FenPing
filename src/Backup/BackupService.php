<?php

declare(strict_types=1);

namespace FenPing\Backup;

use FenPing\Backend\Backend;
use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;

final readonly class BackupService
{
    private BackupManager $manager;

    public function __construct(private Backend $backend, AppConfig $config, DatabaseManager $database)
    {
        $this->manager = new BackupManager($backend, $config, $database);
    }

    public function backup(array $arguments): int { return $this->manager->backup($arguments); }
    public function restore(array $arguments): int { return $this->manager->restore($arguments); }
    public function verify(array $arguments): int { return $this->manager->verify($arguments); }
    public function maintenance(array $arguments): int { return $this->manager->maintenance($arguments); }
    public function restoreStage(array $arguments): int { return $this->manager->restoreStage($arguments); }
    public function apiList(): array { return $this->manager->apiList(); }
    public function downloadPath(string $filename): string { return $this->manager->downloadPath($filename); }
    public function sameFilesystem(): bool { return $this->manager->sameFilesystem(); }
    public function validateDocument(array $document, string $format, string $label): void { $this->backend->backupValidateDocument($document, $format, $label); }
    public function archiveEntryIsSafe(string $entry): bool { return $this->backend->backupArchiveEntrySafe($entry); }
    public function readJson(string $path, string $label): array { return $this->backend->backupReadJson($path, $label); }
}
