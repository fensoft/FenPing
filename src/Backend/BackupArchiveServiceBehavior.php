<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

trait BackupArchiveServiceBehavior
{
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
    echo "size: " . $this->backupFormatBytes(filesize($target)) . PHP_EOL;
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

    $source = $this->backupAbsolutePath($args[0]);
    if (!is_file($source) || !is_readable($source))
      throw new InvalidArgumentException("backup not readable: $source");

    if (!$this->backupIsArchive($source))
      throw new InvalidArgumentException("restore expects a .tgz or .tar.gz FenPing backup");

    $this->backupRestoreArchive($source);
    $this->backupReloadHosts();
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
    $this->backupEnsureDir($this->config->backupDir());
    return $this->config->backupDir() . '/fenping-' . date('Ymd-His') . '.tgz';
  }

  $target = $this->backupAbsolutePath($path);
  if (!$this->backupIsArchive($target))
    throw new InvalidArgumentException("backup target must end with .tgz or .tar.gz");

  $this->backupEnsureDir(dirname($target));
  return $target;
}

public function backupCreateArchive(string $target): void {
  $stage = $this->backupTempDir('fenping-backup-');
  $tmpArchive = tempnam(dirname($target), basename($target) . '.');
  if ($tmpArchive === false) {
    $this->backupRemoveTree($stage);
    throw new RuntimeException('failed to create temporary backup file');
  }

  try {
    $database = $stage . '/db.json';
    $databaseCounts = $this->backupWriteDatabaseJson($database);

    $this->backupCopyDirectory($this->backupNetbootDir(), $stage . '/netboot');
    $this->backupWriteJson($stage . '/netboot-index.json', $this->backupNetbootIndex());
    $this->backupWriteJson($stage . '/manifest.json', $this->backupManifest($database, $databaseCounts));

    $this->backupRunProcess(array('tar', '-czf', $tmpArchive, '-C', $stage, '.'));
    chmod($tmpArchive, 0600);
    if (!rename($tmpArchive, $target))
      throw new RuntimeException("failed to write backup: $target");
  } finally {
    if (is_file($tmpArchive))
      @unlink($tmpArchive);
    $this->backupRemoveTree($stage);
  }
}

public function backupRestoreArchive(string $source): void {
  $this->backupValidateArchive($source);
  $stage = $this->backupTempDir('fenping-restore-');

  try {
    $this->backupRunProcess(array('tar', '-xzf', $source, '-C', $stage));

    $manifest = $this->backupReadJson($stage . '/manifest.json', 'manifest.json');
    $this->backupValidateDocument($manifest, self::BACKUP_FORMAT, 'manifest.json');

    $databasePath = $stage . '/db.json';
    $database = $this->backupReadJson($databasePath, 'db.json');
    $this->backupValidateDocument($database, self::BACKUP_DATABASE_FORMAT, 'db.json');
    if ($manifest['version'] !== $database['version'])
      throw new RuntimeException('manifest.json and db.json backup versions do not match');

    $this->backupApplyCurrentSchema();
    $this->backupRestoreDatabase($database);

    if (is_dir($stage . '/netboot')) {
      $this->backupReplaceDirectory($stage . '/netboot', $this->backupNetbootDir());
      echo "netboot files restored" . PHP_EOL;
    }
  } finally {
    $this->backupRemoveTree($stage);
  }
}

public function backupTableNames(): array {
  return array(
    'device_approvals', 'netboot_images', 'ips', 'leases', 'oui_vendors',
    'ip_conflicts', 'ip_conflict_devices', 'ip_conflict_monitor',
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
  $database = $this->db();
  $schema = $this->backupCurrentSchema();
  $out = fopen($target, 'wb');
  if ($out === false)
    throw new RuntimeException("failed to write $target");

  $tableCount = 0;
  $totalRows = 0;

  try {
    $database->beginTransaction();
    $this->backupWriteChunk($out, "{\n");
    $this->backupWriteChunk($out, '    "format": ' . $this->backupJsonEncode(self::BACKUP_DATABASE_FORMAT) . ",\n");
    $this->backupWriteChunk($out, '    "version": ' . $this->backupJsonEncode(self::BACKUP_VERSION) . ",\n");
    $this->backupWriteChunk($out, '    "created_at": ' . $this->backupJsonEncode(date(DATE_ATOM)) . ",\n");
    $this->backupWriteChunk($out, "    \"tables\": {\n");

    foreach ($this->backupTableNames() as $table) {
      if (!isset($schema[$table]))
        continue;

      $columns = array_keys($schema[$table]);
      $quotedColumns = array_map([$this, 'backupQuoteIdentifier'], $columns);
      if ($tableCount > 0)
        $this->backupWriteChunk($out, ",\n");
      $this->backupWriteChunk($out, '        ' . $this->backupJsonEncode($table) . ": {\n");
      $this->backupWriteChunk($out, '            "columns": ' . $this->backupJsonEncode($columns) . ",\n");
      $this->backupWriteChunk($out, "            \"rows\": [");

      $stmt = $database->query(
        'SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . $this->backupQuoteIdentifier($table)
      );
      $rowCount = 0;
      while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $this->backupWriteChunk($out, ($rowCount === 0 ? "\n" : ",\n")
          . '                ' . $this->backupJsonEncode($row));
        $rowCount++;
        $totalRows++;
      }
      $stmt->closeCursor();
      if ($rowCount > 0)
        $this->backupWriteChunk($out, "\n            ");
      $this->backupWriteChunk($out, "]\n        }");
      $tableCount++;
    }
    $this->backupWriteChunk($out, "\n    }\n}\n");
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
