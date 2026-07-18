<?php

declare(strict_types=1);

namespace FenPing\Backup;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;

final readonly class BackupArchiveService
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private BackupFilesystem $filesystem,
        private BackupArchiveTools $tools,
        private BackupDatabaseDocument $documents,
        private BackupTableCatalog $tables,
    ) {
    }

public const BACKUP_FORMAT = 'fenping-backup';
public const BACKUP_DATABASE_FORMAT = 'fenping-db';
public const BACKUP_VERSION = '1.6';

public function runBackupCommand(array $args): int {
  try {
    if (count($args) > 1)
      throw new InvalidArgumentException($this->backupUsage());

    $target = $this->backupTargetPath($args[0] ?? '');
    $this->backupCreateArchive($target);
    echo "backup written: $target" . PHP_EOL;
    echo "size: " . $this->filesystem->backupFormatBytes(filesize($target)) . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return $e instanceof InvalidArgumentException ? 2 : 1;
  }
}

public function runRestoreCommand(array $args): int {
  try {
    if (count($args) !== 1)
      throw new InvalidArgumentException($this->backupUsage());

    $source = $this->filesystem->backupAbsolutePath($args[0]);
    if (!is_file($source) || !is_readable($source))
      throw new InvalidArgumentException("backup not readable: $source");

    if (!$this->filesystem->backupIsArchive($source))
      throw new InvalidArgumentException("restore expects a .tgz or .tar.gz FenPing backup");

    $this->backupRestoreArchive($source);
    $this->tools->backupReloadHosts();
    echo "restore complete" . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return $e instanceof InvalidArgumentException ? 2 : 1;
  }
}

public function backupUsage(): string {
  return "Usage: php cli.php backup [backup.tgz]\n"
    . "       php cli.php restore <backup.tgz>";
}

public function backupTargetPath(string $path): string {
  if ($path === '') {
    $this->filesystem->backupEnsureDir($this->config->backupDir());
    return $this->config->backupDir() . '/fenping-' . date('Ymd-His') . '.tgz';
  }

  $target = $this->filesystem->backupAbsolutePath($path);
  if (!$this->filesystem->backupIsArchive($target))
    throw new InvalidArgumentException("backup target must end with .tgz or .tar.gz");

  $this->filesystem->backupEnsureDir(dirname($target));
  return $target;
}

public function backupCreateArchive(string $target): void {
  $stage = $this->filesystem->backupTempDir('fenping-backup-');
  $tmpArchive = tempnam(dirname($target), basename($target) . '.');
  if ($tmpArchive === false) {
    $this->filesystem->backupRemoveTree($stage);
    throw new RuntimeException('failed to create temporary backup file');
  }

  try {
    $database = $stage . '/db.json';
    $databaseCounts = $this->backupWriteDatabaseJson($database);

    $this->filesystem->backupCopyDirectory($this->filesystem->backupNetbootDir(), $stage . '/netboot');
    $this->tools->backupWriteJson($stage . '/netboot-index.json', $this->documents->backupNetbootIndex());
    $this->tools->backupWriteJson($stage . '/manifest.json', $this->documents->backupManifest($database, $databaseCounts));

    $this->tools->backupRunProcess(array('tar', '-czf', $tmpArchive, '-C', $stage, '.'));
    chmod($tmpArchive, 0600);
    if (!rename($tmpArchive, $target))
      throw new RuntimeException("failed to write backup: $target");
  } finally {
    if (is_file($tmpArchive))
      @unlink($tmpArchive);
    $this->filesystem->backupRemoveTree($stage);
  }
}

public function backupRestoreArchive(string $source): void {
  $this->tools->backupValidateArchive($source);
  $stage = $this->filesystem->backupTempDir('fenping-restore-');

  try {
    $this->tools->backupRunProcess(array('tar', '-xzf', $source, '-C', $stage));

    $manifest = $this->tools->backupReadJson($stage . '/manifest.json', 'manifest.json');
    $this->tools->backupValidateDocument($manifest, self::BACKUP_FORMAT, 'manifest.json');

    $databasePath = $stage . '/db.json';
    $database = $this->tools->backupReadJson($databasePath, 'db.json');
    $this->tools->backupValidateDocument($database, self::BACKUP_DATABASE_FORMAT, 'db.json');
    if ($manifest['version'] !== $database['version'])
      throw new RuntimeException('manifest.json and db.json backup versions do not match');

    $this->documents->backupApplyCurrentSchema();
    $this->documents->backupRestoreDatabase($database);

    if (is_dir($stage . '/netboot')) {
      $this->filesystem->backupReplaceDirectory($stage . '/netboot', $this->filesystem->backupNetbootDir());
      echo "netboot files restored" . PHP_EOL;
    }
  } finally {
    $this->filesystem->backupRemoveTree($stage);
  }
}

public function backupTableNames(): array {
  return array(
    'device_approvals', 'netboot_images', 'ips', 'tags', 'inventory_saved_filters',
    'inventory_device_metadata', 'host_tags', 'inventory_saved_filter_tags',
    'inventory_device_tags', 'leases', 'oui_vendors',
    'ip_conflicts', 'ip_conflict_devices', 'ip_conflict_monitor',
    'notification_delivery_settings', 'scheduled_report_settings', 'scheduled_report_runs',
    'ping', 'range', 'scan_snapshots', 'scans', 'scan_port_changes',
    'scan_snapshot_addresses', 'scan_snapshot_hostnames',
    'scan_snapshot_scopes', 'scan_snapshot_ports',
    'scan_snapshot_port_cpes', 'scan_snapshot_extra_ports',
    'scan_snapshot_extra_reasons', 'scan_snapshot_os_matches',
    'scan_snapshot_os_classes', 'scan_snapshot_os_cpes',
    'scan_snapshot_scripts', 'scan_snapshot_script_nodes',
    'scan_snapshot_trace_hops', 'stats', 'stats_old', 'users'
  );
}

public function backupWriteDatabaseJson(string $target): array {
  $database = $this->database->connection();
  $schema = $this->documents->backupCurrentSchema();
  $out = fopen($target, 'wb');
  if ($out === false)
    throw new RuntimeException("failed to write $target");

  $tableCount = 0;
  $totalRows = 0;

  try {
    $database->beginTransaction();
    $this->tools->backupWriteChunk($out, "{\n");
    $this->tools->backupWriteChunk($out, '    "format": ' . $this->tools->backupJsonEncode(self::BACKUP_DATABASE_FORMAT) . ",\n");
    $this->tools->backupWriteChunk($out, '    "version": ' . $this->tools->backupJsonEncode(self::BACKUP_VERSION) . ",\n");
    $this->tools->backupWriteChunk($out, '    "created_at": ' . $this->tools->backupJsonEncode(date(DATE_ATOM)) . ",\n");
    $this->tools->backupWriteChunk($out, "    \"tables\": {\n");

    foreach ($this->tables->backupTableNames() as $table) {
      if (!isset($schema[$table]))
        continue;

      $columns = array_keys($schema[$table]);
      if ($table === 'notification_delivery_settings') {
        $columns = array_values(array_diff(
          $columns,
          array('telegram_chat_id', 'telegram_bot_fingerprint')
        ));
      }
      $quotedColumns = array_map($this->tools->backupQuoteIdentifier(...), $columns);
      if ($tableCount > 0)
        $this->tools->backupWriteChunk($out, ",\n");
      $this->tools->backupWriteChunk($out, '        ' . $this->tools->backupJsonEncode($table) . ": {\n");
      $this->tools->backupWriteChunk($out, '            "columns": ' . $this->tools->backupJsonEncode($columns) . ",\n");
      $this->tools->backupWriteChunk($out, "            \"rows\": [");

      $stmt = $database->query(
        'SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . $this->tools->backupQuoteIdentifier($table)
      );
      $rowCount = 0;
      while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $this->tools->backupWriteChunk($out, ($rowCount === 0 ? "\n" : ",\n")
          . '                ' . $this->tools->backupJsonEncode($row));
        $rowCount++;
        $totalRows++;
      }
      $stmt->closeCursor();
      if ($rowCount > 0)
        $this->tools->backupWriteChunk($out, "\n            ");
      $this->tools->backupWriteChunk($out, "]\n        }");
      $tableCount++;
    }
    $this->tools->backupWriteChunk($out, "\n    }\n}\n");
    $database->commit();
  } catch (Throwable $e) {
    if ($database->inTransaction())
      $database->rollBack();
    @unlink($target);
    throw $e;
  } finally {
    fclose($out);
  }

  return array('tables' => $tableCount, 'rows' => $totalRows);
}
}
