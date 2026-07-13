<?php

declare(strict_types=1);

namespace FenPing\Backup;

use FenPing\Backend\Backend;
use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Realtime\NullLiveUpdatePublisher;

final readonly class BackupService
{
    private BackupManager $manager;
    private LiveUpdatePublisher $liveUpdates;

    public function __construct(private Backend $backend, AppConfig $config, DatabaseManager $database, ?LiveUpdatePublisher $liveUpdates = null)
    {
        $this->manager = new BackupManager($backend, $config, $database);
        $this->liveUpdates = $liveUpdates ?? new NullLiveUpdatePublisher();
    }

    public function backup(array $arguments): int {
        $code = count($arguments) <= 1 ? $this->trackBackup(fn(): int => $this->manager->backup($arguments))
            : $this->manager->backup($arguments);
        if (count($arguments) <= 1) $this->liveUpdates->publish(LiveUpdateScope::Backups);
        return $code;
    }
    public function restore(array $arguments): int {
        $code = $this->manager->restore($arguments);
        if ($code === 0 && count($arguments) === 1) $this->liveUpdates->publish(LiveUpdateScope::All);
        return $code;
    }
    public function verify(array $arguments): int {
        $code = $this->manager->verify($arguments);
        if (count($arguments) === 1) $this->liveUpdates->publish(LiveUpdateScope::Backups);
        return $code;
    }
    public function maintenance(array $arguments): int {
        $code = $arguments === ['daily'] ? $this->trackBackup(fn(): int => $this->manager->maintenance($arguments))
            : $this->manager->maintenance($arguments);
        if (count($arguments) === 1 && in_array($arguments[0], ['daily', 'verify'], true)) {
            $this->liveUpdates->publish(LiveUpdateScope::Backups);
        }
        return $code;
    }
    public function restoreStage(array $arguments): int { return $this->manager->restoreStage($arguments); }
    public function apiList(): array { return $this->manager->apiList(); }
    public function downloadPath(string $filename): string { return $this->manager->downloadPath($filename); }
    public function sameFilesystem(): bool { return $this->manager->sameFilesystem(); }
    public function validateDocument(array $document, string $format, string $label): void { $this->backend->backupValidateDocument($document, $format, $label); }
    public function archiveEntryIsSafe(string $entry): bool { return $this->backend->backupArchiveEntrySafe($entry); }
    public function readJson(string $path, string $label): array { return $this->backend->backupReadJson($path, $label); }

    private function trackBackup(callable $command): int
    {
        $this->backend->operations->started('backup');
        $code = $command();
        if ($code === 0) {
            $this->backend->operations->succeeded('backup');
        } else {
            $this->backend->operations->failed('backup', "command exited with code $code");
        }
        return $code;
    }
}
