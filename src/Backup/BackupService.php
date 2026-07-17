<?php

declare(strict_types=1);

namespace FenPing\Backup;

use FenPing\Health\OperationTracker;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Realtime\NullLiveUpdatePublisher;
use RuntimeException;

final readonly class BackupService
{
    private LiveUpdatePublisher $liveUpdates;

    public function __construct(
        private BackupManager $manager,
        private BackupArchiveTools $tools,
        private OperationTracker $operations,
        ?LiveUpdatePublisher $liveUpdates = null,
    ) {
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
    public function apiCreate(): array
    {
        $path = $this->uniquePath('fenping-manual');
        if ($this->quiet(fn(): int => $this->backup([$path])) !== 0) {
            throw new RuntimeException('backup creation failed');
        }
        return ['created' => basename($path)];
    }
    public function apiRestore(string $filename): array
    {
        $path = $this->manager->downloadPath($filename);
        $record = array_values(array_filter(
            $this->manager->apiList()['backups'],
            static fn(array $item): bool => $item['filename'] === $filename,
        ))[0] ?? null;
        $checksum = hash_file('sha256', $path);
        $verified = ($record['verification']['status'] ?? '') === 'verified'
            && is_string($record['sha256'] ?? null)
            && is_string($checksum)
            && hash_equals($record['sha256'], $checksum);
        if (!$verified && $this->quiet(fn(): int => $this->verify([$path])) !== 0) {
            throw new RuntimeException('backup verification failed');
        }

        $safetyPath = $this->uniquePath('fenping-before-restore');
        if ($this->quiet(fn(): int => $this->backup([$safetyPath])) !== 0) {
            throw new RuntimeException('safety backup creation failed');
        }
        if ($this->quiet(fn(): int => $this->restore([$path])) !== 0) {
            throw new RuntimeException('backup restore failed');
        }
        return ['restored' => $filename, 'safety_backup' => basename($safetyPath)];
    }
    public function downloadPath(string $filename): string { return $this->manager->downloadPath($filename); }
    public function sameFilesystem(): bool { return $this->manager->sameFilesystem(); }
    public function validateDocument(array $document, string $format, string $label): void { $this->tools->backupValidateDocument($document, $format, $label); }
    public function archiveEntryIsSafe(string $entry): bool { return $this->tools->backupArchiveEntrySafe($entry); }
    public function readJson(string $path, string $label): array { return $this->tools->backupReadJson($path, $label); }

    private function trackBackup(callable $command): int
    {
        $this->operations->started('backup');
        $code = $command();
        if ($code === 0) {
            $this->operations->succeeded('backup');
        } else {
            $this->operations->failed('backup', "command exited with code $code");
        }
        return $code;
    }

    private function uniquePath(string $prefix): string
    {
        $existing = array_fill_keys(array_column($this->manager->apiList()['backups'], 'filename'), true);
        $base = $prefix . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        for ($suffix = 0; $suffix < 1000; $suffix++) {
            $filename = $base . ($suffix === 0 ? '' : '-' . $suffix) . '.tgz';
            if (!isset($existing[$filename])) return $this->manager->managedPath($filename);
        }
        throw new RuntimeException('failed to allocate backup filename');
    }

    private function quiet(callable $operation): mixed
    {
        ob_start();
        try {
            return $operation();
        } finally {
            ob_end_clean();
        }
    }
}
