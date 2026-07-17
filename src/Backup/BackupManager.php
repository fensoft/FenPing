<?php
declare(strict_types=1);
namespace FenPing\Backup;
use DateTimeImmutable;
use DateTimeZone;
use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
final readonly class BackupManager
{
    private const LOCK_PATH = '/tmp/fenping-backup.lck';
    private const META_SUFFIX = '.metadata.json';
    public function __construct(private AppConfig $config, private DatabaseManager $database, private BackupArchiveService $archives, private BackupFilesystem $filesystem, private BackupArchiveTools $tools) {}
    public function backup(array $args): int {
        return $this->command(function () use ($args): void {
            if (count($args) > 1) throw new InvalidArgumentException('Usage: php cli.php backup [backup.tgz]');
            $this->locked(function () use ($args): void {
                $target = $this->archives->backupTargetPath($args[0] ?? '');
                $this->create($target, $this->kind(basename($target), 'manual'));
                echo "backup written: $target" . PHP_EOL;
                echo 'size: ' . $this->filesystem->backupFormatBytes(filesize($target)) . PHP_EOL;
                $this->warnSameDisk();
            });
        });
    }
    public function restore(array $args): int {
        return $this->command(function () use ($args): void {
            if (count($args) !== 1) throw new InvalidArgumentException('Usage: php cli.php restore <backup.tgz>');
            $this->locked(function () use ($args): void {
                $this->archives->backupRestoreArchive($this->readableArchive($args[0]));
                $this->tools->backupReloadHosts();
                echo 'restore complete' . PHP_EOL;
            });
        });
    }
    public function verify(array $args): int {
        return $this->command(function () use ($args): void {
            if (count($args) !== 1) throw new InvalidArgumentException('Usage: php cli.php backup-verify <backup.tgz>');
            $this->locked(function () use ($args): void {
                $meta = $this->verifyArchive($this->readableArchive($args[0]));
                echo 'backup verified: ' . $meta['filename'] . PHP_EOL;
                echo 'sha256: ' . $meta['sha256'] . PHP_EOL;
                $this->warnSameDisk();
            });
        });
    }
    public function maintenance(array $args): int {
        return $this->command(function () use ($args): void {
            if (count($args) !== 1 || !in_array($args[0], ['daily', 'verify'], true)) {
                throw new InvalidArgumentException('Usage: php cli.php backup-maintenance <daily|verify>');
            }
            $this->locked(function () use ($args): void {
                if ($args[0] === 'daily') {
                    $target = $this->config->backupDir() . '/fenping-daily-' . gmdate('Ymd-His') . '.tgz';
                    $this->create($target, 'daily');
                    $this->verifyArchive($target);
                    $this->prune();
                    echo 'daily backup complete: ' . basename($target) . PHP_EOL;
                } else {
                    $archive = $this->leastRecentlyTested();
                    if ($archive === null) echo 'no retained backup is available for verification' . PHP_EOL;
                    else {
                        $this->verifyArchive($archive);
                        echo 'periodic restore test complete: ' . basename($archive) . PHP_EOL;
                    }
                }
                $this->warnSameDisk();
            });
        });
    }
    /** Internal worker. Its environment must point at disposable database and data paths. */
    public function restoreStage(array $args): int {
        return $this->command(function () use ($args): void {
            if (count($args) !== 1) throw new InvalidArgumentException('Usage: php cli.php backup-restore-stage <backup.tgz>');
            $this->archives->backupRestoreArchive($this->readableArchive($args[0]));
            $errors = $this->database->integrityErrors();
            if ($errors !== []) throw new RuntimeException('restored database integrity check failed: ' . implode('; ', $errors));
            $foreignKeys = $this->database->connection()->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            if ($foreignKeys !== []) throw new RuntimeException('restored database violates foreign keys');
            echo 'staged restore verified' . PHP_EOL;
        });
    }
    public function apiList(): array {
        $records = $this->records();
        $roles = $this->retentionRoles($records, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        foreach ($records as &$record) {
            $record['retention_roles'] = $roles[$record['filename']] ?? [];
            $record['download_url'] = '/api/backups/' . rawurlencode($record['filename']) . '/file';
        }
        unset($record);
        $sameDisk = $this->sameFilesystem();
        return [
            'backups' => $records,
            'storage' => [
                'same_filesystem' => $sameDisk,
                'warning' => $sameDisk
                    ? 'Backups are stored on the same filesystem as the appliance database. Keep a copy on another device.'
                    : null,
            ],
        ];
    }
    public function downloadPath(string $filename): string {
        if (basename($filename) !== $filename || !$this->filesystem->backupIsArchive($filename)) {
            throw new InvalidArgumentException('invalid backup file');
        }
        $path = $this->config->backupDir() . '/' . $filename;
        if (is_link($path) || !is_file($path) || !is_readable($path)) throw new RuntimeException('backup not found');
        $directory = realpath($this->config->backupDir());
        $real = realpath($path);
        if ($directory === false || $real === false || dirname($real) !== $directory) {
            throw new InvalidArgumentException('invalid backup file');
        }
        return $real;
    }
    public function managedPath(string $filename): string { return $this->config->backupDir() . '/' . $filename; }
    public function sameFilesystem(): bool {
        $this->filesystem->backupEnsureDir($this->config->backupDir());
        $this->filesystem->backupEnsureDir(dirname($this->config->databasePath));
        $database = stat(dirname($this->config->databasePath));
        $backups = stat($this->config->backupDir());
        return $database !== false && $backups !== false && $database['dev'] === $backups['dev'];
    }
    private function create(string $target, string $kind): void {
        $this->archives->backupCreateArchive($target);
        chmod($target, 0640);
        @chgrp($target, 'www-data');
        $this->writeMeta($target, [
            'filename' => basename($target),
            'kind' => $kind,
            'created_at' => gmdate(DATE_ATOM),
            'size' => filesize($target),
            'sha256' => hash_file('sha256', $target),
            'verification' => $this->verification('unverified'),
        ]);
    }
    private function verifyArchive(string $source): array {
        $checksum = hash_file('sha256', $source);
        if ($checksum === false) throw new RuntimeException('failed to checksum backup archive');
        $existing = $this->readMeta($source);
        try {
            $this->validateContents($source);
            $this->restoreTest($source);
            $after = hash_file('sha256', $source);
            if ($after === false || !hash_equals($checksum, $after)) throw new RuntimeException('backup changed during verification');
            chmod($source, 0640);
            @chgrp($source, 'www-data');
            $meta = array_replace($existing, [
                'filename' => basename($source),
                'kind' => $existing['kind'] ?? $this->kind(basename($source), 'imported'),
                'created_at' => $existing['created_at'] ?? gmdate(DATE_ATOM, filemtime($source) ?: time()),
                'size' => filesize($source),
                'sha256' => $checksum,
                'verification' => $this->verification('verified'),
            ]);
            $this->writeMeta($source, $meta);
            return $meta;
        } catch (Throwable $error) {
            $meta = array_replace($existing, [
                'filename' => basename($source),
                'kind' => $existing['kind'] ?? $this->kind(basename($source), 'imported'),
                'created_at' => $existing['created_at'] ?? gmdate(DATE_ATOM, filemtime($source) ?: time()),
                'size' => filesize($source),
                'sha256' => $checksum,
                'verification' => $this->verification('failed', $this->sanitize($error->getMessage(), $source)),
            ]);
            $this->writeMeta($source, $meta);
            throw $error;
        }
    }
    private function verification(string $status, ?string $message = null): array {
        $verified = $status === 'unverified' ? null : gmdate(DATE_ATOM);
        return [
            'status' => $status,
            'verified_at' => $verified,
            'restore_tested_at' => $status === 'verified' ? $verified : null,
            'image_id' => $status === 'unverified' ? null : (getenv('FENPING_VERIFY_IMAGE_ID') ?: 'current'),
            'message' => $message,
        ];
    }
    private function validateContents(string $source): void {
        $this->tools->backupValidateArchive($source);
        $stage = $this->filesystem->backupTempDir('fenping-validate-');
        try {
            $this->tools->backupRunProcess(['tar', '-xzf', $source, '-C', $stage]);
            $this->rejectLinks($stage);
            $manifest = $this->tools->backupReadJson($stage . '/manifest.json', 'manifest.json');
            $databasePath = $stage . '/db.json';
            $database = $this->tools->backupReadJson($databasePath, 'db.json');
            $netbootIndex = $this->tools->backupReadJson($stage . '/netboot-index.json', 'netboot-index.json');
            $this->tools->backupValidateDocument($manifest, BackupArchiveService::BACKUP_FORMAT, 'manifest.json');
            $this->tools->backupValidateDocument($database, BackupArchiveService::BACKUP_DATABASE_FORMAT, 'db.json');
            if (($manifest['version'] ?? null) !== ($database['version'] ?? null)) {
                throw new RuntimeException('manifest.json and db.json backup versions do not match');
            }
            $tables = $database['tables'] ?? null;
            if (!is_array($tables)) throw new RuntimeException('db.json does not contain a tables object');
            $rows = 0;
            foreach ($tables as $table) {
                if (!is_array($table) || !is_array($table['columns'] ?? null) || !is_array($table['rows'] ?? null)) {
                    throw new RuntimeException('db.json contains invalid table data');
                }
                $rows += count($table['rows']);
            }
            if ((int) ($manifest['database']['bytes'] ?? -1) !== filesize($databasePath)
                || (int) ($manifest['database']['tables'] ?? -1) !== count($tables)
                || (int) ($manifest['database']['rows'] ?? -1) !== $rows) {
                throw new RuntimeException('manifest database counts do not match db.json');
            }
            if (!array_is_list($netbootIndex)) throw new RuntimeException('netboot-index.json must contain a list');
            if ((int) ($manifest['counts']['netboot_rows'] ?? -1) !== count($netbootIndex)
                || (int) ($manifest['counts']['netboot_files'] ?? -1) !== $this->filesystem->backupCountFiles($stage . '/netboot')) {
                throw new RuntimeException('manifest netboot counts do not match the archive');
            }
        } finally {
            $this->filesystem->backupRemoveTree($stage);
        }
    }
    private function restoreTest(string $source): void {
        $base = $this->config->stateDir() . '/backup-tests';
        $this->filesystem->backupEnsureDir($base);
        $stage = tempnam($base, 'restore-');
        if ($stage === false) throw new RuntimeException('failed to create restore-test workspace');
        @unlink($stage);
        if (!mkdir($stage, 0700)) throw new RuntimeException('failed to create restore-test workspace');
        try {
            $this->filesystem->backupEnsureDir($stage . '/database');
            $this->filesystem->backupEnsureDir($stage . '/netboot');
            $this->tools->backupRunProcess(
                [PHP_BINARY, $this->config->projectDir . '/cli.php', 'backup-restore-stage', $source],
                ['FENPING_DATA_DIR' => $stage, 'DATABASE_PATH' => $stage . '/database/fenping.sqlite3'],
            );
        } finally {
            $this->filesystem->backupRemoveTree($stage);
        }
    }
    private function rejectLinks(string $directory): void {
        $items = scandir($directory);
        if ($items === false) throw new RuntimeException('failed to inspect extracted backup');
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory . '/' . $item;
            if (is_link($path)) throw new RuntimeException('backup archive contains a symbolic link');
            if (is_dir($path)) $this->rejectLinks($path);
        }
    }
    private function records(): array {
        $this->filesystem->backupEnsureDir($this->config->backupDir());
        $records = [];
        foreach (scandir($this->config->backupDir()) ?: [] as $filename) {
            if ($filename === '.' || $filename === '..' || !$this->filesystem->backupIsArchive($filename)) continue;
            $path = $this->config->backupDir() . '/' . $filename;
            if (is_link($path) || !is_file($path) || !is_readable($path)) continue;
            $meta = $this->readMeta($path);
            $records[] = [
                'filename' => $filename,
                'kind' => $meta['kind'] ?? $this->kind($filename, 'imported'),
                'created_at' => $meta['created_at'] ?? gmdate(DATE_ATOM, filemtime($path) ?: time()),
                'size' => filesize($path),
                'sha256' => $meta['sha256'] ?? null,
                'verification' => $meta['verification'] ?? $this->verification('unverified'),
            ];
        }
        usort($records, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return $records;
    }
    private function retentionRoles(array $records, DateTimeImmutable $now): array {
        $daily = [];
        $weekly = [];
        $checkpoints = [];
        foreach ($records as $record) {
            if (($record['verification']['status'] ?? '') !== 'verified') continue;
            $created = new DateTimeImmutable($record['created_at']);
            if ($record['kind'] === 'daily') {
                $age = (int) $now->setTime(0, 0)->diff($created->setTime(0, 0))->format('%r%a');
                $day = $created->format('Y-m-d');
                if ($age <= 0 && $age >= -6 && !isset($daily[$day])) $daily[$day] = $record['filename'];
                $week = $created->format('o-W');
                $currentWeek = $now->modify('monday this week')->setTime(0, 0);
                $createdWeek = $created->modify('monday this week')->setTime(0, 0);
                $weekAge = intdiv(max(0, $currentWeek->getTimestamp() - $createdWeek->getTimestamp()), 604800);
                if ($weekAge < 4 && !isset($weekly[$week])) $weekly[$week] = $record['filename'];
            } elseif ($record['kind'] === 'pre-upgrade' && count($checkpoints) < 2) {
                $checkpoints[] = $record['filename'];
            }
        }
        $roles = [];
        foreach ($daily as $file) $roles[$file][] = 'daily';
        foreach ($weekly as $file) $roles[$file][] = 'weekly';
        foreach ($checkpoints as $file) $roles[$file][] = 'checkpoint';
        return $roles;
    }
    private function prune(): void {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $records = $this->records();
        $roles = $this->retentionRoles($records, $now);
        $latestFailed = null;
        foreach ($records as $record) {
            if (($record['verification']['status'] ?? '') === 'failed') $latestFailed ??= $record['filename'];
        }
        foreach ($records as $record) {
            if (!in_array($record['kind'], ['daily', 'pre-upgrade'], true) || isset($roles[$record['filename']])) continue;
            $failed = ($record['verification']['status'] ?? '') === 'failed';
            if ($failed && ($record['filename'] === $latestFailed
                || new DateTimeImmutable($record['created_at']) > $now->modify('-7 days'))) continue;
            $path = $this->config->backupDir() . '/' . $record['filename'];
            @unlink($path);
            @unlink($this->metaPath($path));
        }
    }
    private function leastRecentlyTested(): ?string {
        $records = array_values(array_filter($this->records(), static fn(array $record): bool =>
            in_array($record['kind'], ['daily', 'pre-upgrade'], true)
            && ($record['verification']['status'] ?? '') === 'verified'));
        usort($records, static fn(array $a, array $b): int =>
            strcmp($a['verification']['restore_tested_at'] ?? '', $b['verification']['restore_tested_at'] ?? ''));
        return isset($records[0]) ? $this->config->backupDir() . '/' . $records[0]['filename'] : null;
    }
    private function readMeta(string $archive): array {
        $path = $this->metaPath($archive);
        if (!is_file($path) || is_link($path)) return [];
        try {
            return $this->tools->backupReadJson($path, basename($path));
        } catch (Throwable) {
            return [];
        }
    }
    private function writeMeta(string $archive, array $meta): void {
        if (realpath(dirname($archive)) !== realpath($this->config->backupDir())) return;
        $path = $this->metaPath($archive);
        $tmp = tempnam(dirname($path), basename($path) . '.');
        if ($tmp === false) throw new RuntimeException('failed to create backup metadata file');
        try {
            $this->tools->backupWriteJson($tmp, $meta);
            chmod($tmp, 0640);
            @chgrp($tmp, 'www-data');
            if (!rename($tmp, $path)) throw new RuntimeException('failed to store backup metadata');
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }
    private function metaPath(string $archive): string {
        return $archive . self::META_SUFFIX;
    }
    private function kind(string $filename, string $fallback): string {
        return match (true) {
            str_starts_with($filename, 'fenping-daily-') => 'daily',
            str_starts_with($filename, 'fenping-pre-upgrade-') => 'pre-upgrade',
            str_starts_with($filename, 'fenping-rollback-rescue-') => 'rollback-rescue',
            str_contains($filename, 'demo') => 'demo',
            default => $fallback,
        };
    }
    private function readableArchive(string $path): string {
        $source = $this->filesystem->backupAbsolutePath($path);
        if (!$this->filesystem->backupIsArchive($source) || is_link($source) || !is_file($source) || !is_readable($source)) {
            throw new InvalidArgumentException("backup not readable: $source");
        }
        return $source;
    }
    private function locked(callable $callback): mixed {
        $lock = fopen(self::LOCK_PATH, 'c');
        if ($lock === false) throw new RuntimeException('failed to open backup lock');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('backup operation already running');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    private function command(callable $callback): int {
        try {
            $callback();
            return 0;
        } catch (Throwable $error) {
            error_log($error->getMessage());
            return $error instanceof InvalidArgumentException ? 2 : 1;
        }
    }
    private function sanitize(string $message, string $archive): string {
        $message = str_replace(
            [$archive, $this->config->dataDir, $this->config->projectDir],
            [basename($archive), '[data]', '[app]'],
            $message,
        );
        return substr(preg_replace('/\s+/', ' ', trim($message)) ?: 'verification failed', 0, 240);
    }
    private function warnSameDisk(): void {
        if ($this->sameFilesystem()) {
            error_log('WARNING: backups and the appliance database are on the same filesystem; keep an off-appliance copy.');
        }
    }
}
