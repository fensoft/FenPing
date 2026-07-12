<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

trait BackupDatabaseDocumentBehavior
{
public function backupRestoreDatabase(array $document): void {
  $tables = $document['tables'] ?? null;
  if (!is_array($tables))
    throw new RuntimeException('db.json does not contain a tables object');

  $database = $this->db();
  $schema = $this->backupCurrentSchema();
  $timestampShift = $this->backupTimestampShift($document, $schema);
  echo "restoring database" . PHP_EOL;

  $orderedTables = $this->backupTableNames();
  try {
    $this->dbBeginImmediate($database);
    foreach (array_reverse($orderedTables) as $table) {
      if (isset($schema[$table]))
        $database->exec('DELETE FROM ' . $this->backupQuoteIdentifier($table));
    }
    $database->exec('DELETE FROM sqlite_sequence');

    foreach ($orderedTables as $table) {
      if (!isset($tables[$table]) || !isset($schema[$table]))
        continue;
      $tableData = $tables[$table];
      if (!is_array($tableData) || !isset($tableData['columns'], $tableData['rows'])
          || !is_array($tableData['columns']) || !is_array($tableData['rows']))
        throw new RuntimeException("invalid table data for $table in db.json");

      $sourceIndexes = array();
      $columns = array();
      foreach ($tableData['columns'] as $index => $column) {
        if (!is_string($column) || !isset($schema[$table][$column]) || $schema[$table][$column]['generated'])
          continue;
        $sourceIndexes[] = $index;
        $columns[] = $column;
      }
      if ($tableData['rows'] !== array() && $columns === array())
        throw new RuntimeException("db.json has no restorable columns for $table");
      if ($columns === array())
        continue;

      $sql = 'INSERT INTO ' . $this->backupQuoteIdentifier($table)
        . ' (' . implode(', ', array_map([$this, 'backupQuoteIdentifier'], $columns)) . ')'
        . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
      $insert = $database->prepare($sql);
      foreach ($tableData['rows'] as $row) {
        if (!is_array($row))
          throw new RuntimeException("invalid row for $table in db.json");
        $values = array();
        foreach ($sourceIndexes as $position => $sourceIndex) {
          if (!array_key_exists($sourceIndex, $row))
            throw new RuntimeException("short row for $table in db.json");
          $column = $columns[$position];
          $value = $row[$sourceIndex];
          if ($value !== null && !is_scalar($value))
            throw new RuntimeException("invalid value for $table.$column in db.json");
          if (isset($timestampShift[$table][$column]) && $value !== null)
            $value = $this->backupShiftTimestamp($value, $timestampShift[$table][$column]);
          $values[] = $value;
        }
        $insert->execute($values);
      }
    }
    $violations = $database->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
    if ($violations !== array())
      throw new RuntimeException('restored database violates foreign keys');
    $this->dbCommit($database);
  } catch (Throwable $e) {
    $this->dbRollback($database);
    throw $e;
  }
  echo "database restored" . PHP_EOL;
}

public function backupCurrentSchema(): array {
  $schema = array();
  foreach ($this->backupTableNames() as $table) {
    $exists = $this->db()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name");
    $exists->execute(array('name' => $table));
    if ((int)$exists->fetchColumn() !== 1)
      continue;
    $stmt = $this->db()->query('PRAGMA table_xinfo(' . $this->backupQuoteIdentifier($table) . ')');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $schema[$table][(string)$row['name']] = array(
        'type' => strtolower((string)$row['type']),
        'generated' => (int)($row['hidden'] ?? 0) !== 0
      );
    }
  }
  return $schema;
}

public function backupTimestampShift(array $document, array $schema): array {
  $config = $document['restore']['timestamp_shift'] ?? null;
  if (!is_array($config))
    return array();

  $anchor = $config['anchor'] ?? null;
  $columns = $config['columns'] ?? null;
  $offset = $config['target_offset_seconds'] ?? 0;
  if (!is_string($anchor) || !is_array($columns) || !is_int($offset))
    throw new RuntimeException('invalid timestamp_shift in db.json');

  $anchorDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $anchor, new DateTimeZone('UTC'));
  if ($anchorDate === false || $anchorDate->format('Y-m-d H:i:s') !== $anchor)
    throw new RuntimeException('invalid timestamp_shift anchor in db.json');
  $seconds = time() + $offset - $anchorDate->getTimestamp();

  $result = array();
  foreach ($columns as $table => $names) {
    if (!is_string($table) || !isset($schema[$table]) || !is_array($names))
      continue;
    foreach ($names as $column) {
      if (!is_string($column) || !isset($schema[$table][$column]))
        continue;
      if (!in_array($schema[$table][$column]['type'], array('date', 'datetime', 'timestamp'), true))
        throw new RuntimeException("timestamp_shift column is not a date: $table.$column");
      $result[$table][$column] = $seconds;
    }
  }
  return $result;
}

public function backupShiftTimestamp($value, int $seconds): string {
  if (!is_string($value))
    throw new RuntimeException('timestamp_shift value is not a string');
  $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
  if ($date === false || $date->format('Y-m-d H:i:s') !== $value)
    throw new RuntimeException("invalid timestamp value in db.json: $value");
  return gmdate('Y-m-d H:i:s', $date->getTimestamp() + $seconds);
}

public function backupApplyCurrentSchema(): void {
  echo "applying current schema" . PHP_EOL;
  $this->databaseInitialize();
  echo "schema updated" . PHP_EOL;
}

public function backupScanCounts(): array {
  $stmt = $this->db()->query("
    SELECT
      (SELECT COUNT(*) FROM scans) AS scan_rows,
      (SELECT COUNT(*) FROM scan_snapshots) AS snapshot_rows,
      (
        (SELECT COALESCE(SUM(length(CAST(output AS BLOB))), 0) FROM scan_snapshot_scripts) +
        (SELECT COALESCE(SUM(length(CAST(value AS BLOB))), 0) FROM scan_snapshot_script_nodes) +
        (SELECT COALESCE(SUM(
          length(CAST(COALESCE(service, '') AS BLOB)) + length(CAST(COALESCE(product, '') AS BLOB)) +
          length(CAST(COALESCE(version, '') AS BLOB)) + length(CAST(COALESCE(extra_info, '') AS BLOB))
        ), 0) FROM scan_snapshot_ports)
      ) AS snapshot_bytes
  ");
  $counts = $stmt->fetch(PDO::FETCH_ASSOC);
  return array(
    'scan_rows' => (int)($counts['scan_rows'] ?? 0),
    'snapshot_rows' => (int)($counts['snapshot_rows'] ?? 0),
    'snapshot_bytes' => (int)($counts['snapshot_bytes'] ?? 0)
  );
}

public function backupNetbootIndex(): array {
  $stmt = $this->db()->query("
    SELECT id, name, filename, original_name, size, created_at
    FROM netboot_images
    ORDER BY id ASC
  ");

  $rows = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $path = $this->backupNetbootDir() . '/' . basename((string)$row['filename']);
    $row['id'] = (int)$row['id'];
    $row['size'] = (int)$row['size'];
    $row['file_exists'] = is_file($path);
    $row['file_size'] = is_file($path) ? filesize($path) : null;
    $rows[] = $row;
  }
  return $rows;
}

public function backupManifest(string $database, array $databaseCounts): array {
  $scanCounts = $this->backupScanCounts();

  return array(
    'format' => self::BACKUP_FORMAT,
    'version' => self::BACKUP_VERSION,
    'created_at' => date(DATE_ATOM),
    'hostname' => gethostname() ?: 'fenping',
    'includes' => array(
      'db' => 'db.json',
      'netboot' => 'netboot/',
      'netboot_index' => 'netboot-index.json'
    ),
    'counts' => array(
      'netboot_files' => $this->backupCountFiles($this->backupNetbootDir()),
      'scan_rows' => $scanCounts['scan_rows'],
      'scan_snapshot_rows' => $scanCounts['snapshot_rows'],
      'scan_snapshot_bytes' => $scanCounts['snapshot_bytes'],
      'netboot_rows' => count($this->backupNetbootIndex())
    ),
    'database' => array(
      'name' => basename($this->config->databasePath),
      'tables' => $databaseCounts['tables'],
      'rows' => $databaseCounts['rows'],
      'bytes' => filesize($database)
    )
  );
}
}
