<?php

define('BACKUP_DIR', FENPING_DATA_DIR . '/backups');
const BACKUP_FORMAT = 'fenping-backup';
const BACKUP_DATABASE_FORMAT = 'fenping-db';
const BACKUP_VERSION = '1.6';

function runBackupCommand(array $args): int {
  try {
    if (count($args) > 1)
      throw new InvalidArgumentException(backupUsage());

    $target = backupTargetPath($args[0] ?? '');
    backupCreateArchive($target);
    echo "backup written: $target" . PHP_EOL;
    echo "size: " . backupFormatBytes(filesize($target)) . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return $e instanceof InvalidArgumentException ? 2 : 1;
  }
}

function runRestoreCommand(array $args): int {
  try {
    if (count($args) !== 1)
      throw new InvalidArgumentException(backupUsage());

    $source = backupAbsolutePath($args[0]);
    if (!is_file($source) || !is_readable($source))
      throw new InvalidArgumentException("backup not readable: $source");

    if (!backupIsArchive($source))
      throw new InvalidArgumentException("restore expects a .tgz or .tar.gz FenPing backup");

    backupRestoreArchive($source);
    backupReloadHosts();
    echo "restore complete" . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return $e instanceof InvalidArgumentException ? 2 : 1;
  }
}

function backupUsage(): string {
  return "Usage: php cli.php backup [backup.tgz]\n"
    . "       php cli.php restore <backup.tgz>";
}

function backupTargetPath(string $path): string {
  if ($path === '') {
    backupEnsureDir(BACKUP_DIR);
    return BACKUP_DIR . '/fenping-' . date('Ymd-His') . '.tgz';
  }

  $target = backupAbsolutePath($path);
  if (!backupIsArchive($target))
    throw new InvalidArgumentException("backup target must end with .tgz or .tar.gz");

  backupEnsureDir(dirname($target));
  return $target;
}

function backupCreateArchive(string $target): void {
  $stage = backupTempDir('fenping-backup-');
  $tmpArchive = tempnam(dirname($target), basename($target) . '.');
  if ($tmpArchive === false) {
    backupRemoveTree($stage);
    throw new RuntimeException('failed to create temporary backup file');
  }

  try {
    $database = $stage . '/db.json';
    $databaseCounts = backupWriteDatabaseJson($database);

    backupCopyDirectory(backupNetbootDir(), $stage . '/netboot');
    backupWriteJson($stage . '/netboot-index.json', backupNetbootIndex());
    backupWriteJson($stage . '/manifest.json', backupManifest($database, $databaseCounts));

    backupRunProcess(array('tar', '-czf', $tmpArchive, '-C', $stage, '.'));
    chmod($tmpArchive, 0600);
    if (!rename($tmpArchive, $target))
      throw new RuntimeException("failed to write backup: $target");
  } finally {
    if (is_file($tmpArchive))
      @unlink($tmpArchive);
    backupRemoveTree($stage);
  }
}

function backupRestoreArchive(string $source): void {
  backupValidateArchive($source);
  $stage = backupTempDir('fenping-restore-');

  try {
    backupRunProcess(array('tar', '-xzf', $source, '-C', $stage));

    $manifest = backupReadJson($stage . '/manifest.json', 'manifest.json');
    backupValidateDocument($manifest, BACKUP_FORMAT, 'manifest.json');

    $databasePath = $stage . '/db.json';
    $database = backupReadJson($databasePath, 'db.json');
    backupValidateDocument($database, BACKUP_DATABASE_FORMAT, 'db.json');
    if ($manifest['version'] !== $database['version'])
      throw new RuntimeException('manifest.json and db.json backup versions do not match');

    backupApplyCurrentSchema();
    backupRestoreDatabase($database);

    if (is_dir($stage . '/netboot')) {
      backupReplaceDirectory($stage . '/netboot', backupNetbootDir());
      echo "netboot files restored" . PHP_EOL;
    }
  } finally {
    backupRemoveTree($stage);
  }
}

function backupTableNames(): array {
  return array(
    'device_approvals', 'netboot_images', 'ips', 'leases', 'oui_vendors',
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

function backupWriteDatabaseJson(string $target): array {
  $database = db();
  $schema = backupCurrentSchema();
  $out = fopen($target, 'wb');
  if ($out === false)
    throw new RuntimeException("failed to write $target");

  $tableCount = 0;
  $totalRows = 0;

  try {
    $database->beginTransaction();
    backupWriteChunk($out, "{\n");
    backupWriteChunk($out, '    "format": ' . backupJsonEncode(BACKUP_DATABASE_FORMAT) . ",\n");
    backupWriteChunk($out, '    "version": ' . backupJsonEncode(BACKUP_VERSION) . ",\n");
    backupWriteChunk($out, '    "created_at": ' . backupJsonEncode(date(DATE_ATOM)) . ",\n");
    backupWriteChunk($out, "    \"tables\": {\n");

    foreach (backupTableNames() as $table) {
      if (!isset($schema[$table]))
        continue;

      $columns = array_keys($schema[$table]);
      $quotedColumns = array_map('backupQuoteIdentifier', $columns);
      if ($tableCount > 0)
        backupWriteChunk($out, ",\n");
      backupWriteChunk($out, '        ' . backupJsonEncode($table) . ": {\n");
      backupWriteChunk($out, '            "columns": ' . backupJsonEncode($columns) . ",\n");
      backupWriteChunk($out, "            \"rows\": [");

      $stmt = $database->query(
        'SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . backupQuoteIdentifier($table)
      );
      $rowCount = 0;
      while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        backupWriteChunk($out, ($rowCount === 0 ? "\n" : ",\n")
          . '                ' . backupJsonEncode($row));
        $rowCount++;
        $totalRows++;
      }
      $stmt->closeCursor();
      if ($rowCount > 0)
        backupWriteChunk($out, "\n            ");
      backupWriteChunk($out, "]\n        }");
      $tableCount++;
    }
    backupWriteChunk($out, "\n    }\n}\n");
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

function backupRestoreDatabase(array $document): void {
  $tables = $document['tables'] ?? null;
  if (!is_array($tables))
    throw new RuntimeException('db.json does not contain a tables object');

  $database = db();
  $schema = backupCurrentSchema();
  $timestampShift = backupTimestampShift($document, $schema);
  echo "restoring database" . PHP_EOL;

  $orderedTables = backupTableNames();
  try {
    dbBeginImmediate($database);
    foreach (array_reverse($orderedTables) as $table) {
      if (isset($schema[$table]))
        $database->exec('DELETE FROM ' . backupQuoteIdentifier($table));
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

      $sql = 'INSERT INTO ' . backupQuoteIdentifier($table)
        . ' (' . implode(', ', array_map('backupQuoteIdentifier', $columns)) . ')'
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
            $value = backupShiftTimestamp($value, $timestampShift[$table][$column]);
          $values[] = $value;
        }
        $insert->execute($values);
      }
    }
    $violations = $database->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
    if ($violations !== array())
      throw new RuntimeException('restored database violates foreign keys');
    dbCommit($database);
  } catch (Throwable $e) {
    dbRollback($database);
    throw $e;
  }
  echo "database restored" . PHP_EOL;
}

function backupCurrentSchema(): array {
  $schema = array();
  foreach (backupTableNames() as $table) {
    $exists = db()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name");
    $exists->execute(array('name' => $table));
    if ((int)$exists->fetchColumn() !== 1)
      continue;
    $stmt = db()->query('PRAGMA table_xinfo(' . backupQuoteIdentifier($table) . ')');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $schema[$table][(string)$row['name']] = array(
        'type' => strtolower((string)$row['type']),
        'generated' => (int)($row['hidden'] ?? 0) !== 0
      );
    }
  }
  return $schema;
}

function backupTimestampShift(array $document, array $schema): array {
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

function backupShiftTimestamp($value, int $seconds): string {
  if (!is_string($value))
    throw new RuntimeException('timestamp_shift value is not a string');
  $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
  if ($date === false || $date->format('Y-m-d H:i:s') !== $value)
    throw new RuntimeException("invalid timestamp value in db.json: $value");
  return gmdate('Y-m-d H:i:s', $date->getTimestamp() + $seconds);
}

function backupApplyCurrentSchema(): void {
  echo "applying current schema" . PHP_EOL;
  databaseInitialize();
  echo "schema updated" . PHP_EOL;
}

function backupScanCounts(): array {
  $stmt = db()->query("
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

function backupNetbootIndex(): array {
  $stmt = db()->query("
    SELECT id, name, filename, original_name, size, created_at
    FROM netboot_images
    ORDER BY id ASC
  ");

  $rows = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $path = backupNetbootDir() . '/' . basename((string)$row['filename']);
    $row['id'] = (int)$row['id'];
    $row['size'] = (int)$row['size'];
    $row['file_exists'] = is_file($path);
    $row['file_size'] = is_file($path) ? filesize($path) : null;
    $rows[] = $row;
  }
  return $rows;
}

function backupManifest(string $database, array $databaseCounts): array {
  $scanCounts = backupScanCounts();

  return array(
    'format' => BACKUP_FORMAT,
    'version' => BACKUP_VERSION,
    'created_at' => date(DATE_ATOM),
    'hostname' => gethostname() ?: 'fenping',
    'includes' => array(
      'db' => 'db.json',
      'netboot' => 'netboot/',
      'netboot_index' => 'netboot-index.json'
    ),
    'counts' => array(
      'netboot_files' => backupCountFiles(backupNetbootDir()),
      'scan_rows' => $scanCounts['scan_rows'],
      'scan_snapshot_rows' => $scanCounts['snapshot_rows'],
      'scan_snapshot_bytes' => $scanCounts['snapshot_bytes'],
      'netboot_rows' => count(backupNetbootIndex())
    ),
    'database' => array(
      'name' => basename(DATABASE_PATH),
      'tables' => $databaseCounts['tables'],
      'rows' => $databaseCounts['rows'],
      'bytes' => filesize($database)
    )
  );
}

function backupWriteJson(string $path, array $data): void {
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
  if (file_put_contents($path, $json . PHP_EOL) === false)
    throw new RuntimeException("failed to write $path");
}

function backupJsonEncode($value): string {
  return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function backupWriteChunk($stream, string $value): void {
  $length = strlen($value);
  $written = 0;
  while ($written < $length) {
    $bytes = fwrite($stream, substr($value, $written));
    if ($bytes === false || $bytes === 0)
      throw new RuntimeException('failed to write db.json');
    $written += $bytes;
  }
}

function backupReadJson(string $path, string $label): array {
  if (!is_file($path) || !is_readable($path))
    throw new RuntimeException("archive does not contain $label");
  backupEnsureJsonMemory($path);
  $json = file_get_contents($path);
  if ($json === false)
    throw new RuntimeException("failed to read $label");
  try {
    $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    throw new RuntimeException("invalid $label: " . $e->getMessage());
  }
  if (!is_array($document))
    throw new RuntimeException("invalid $label: expected an object");
  return $document;
}

function backupEnsureJsonMemory(string $path): void {
  $bytes = filesize($path);
  if ($bytes === false)
    return;
  $needed = max(128 * 1024 * 1024, $bytes * 8 + 16 * 1024 * 1024);
  $configured = trim((string)ini_get('memory_limit'));
  if ($configured === '' || $configured === '-1')
    return;
  $unit = strtolower(substr($configured, -1));
  $current = (int)$configured;
  if ($unit === 'g')
    $current *= 1024 * 1024 * 1024;
  elseif ($unit === 'm')
    $current *= 1024 * 1024;
  elseif ($unit === 'k')
    $current *= 1024;
  if ($current < $needed)
    ini_set('memory_limit', (string)$needed);
}

function backupValidateDocument(array $document, string $format, string $label): void {
  if (($document['format'] ?? null) !== $format)
    throw new RuntimeException("unsupported $label format");
  $version = $document['version'] ?? null;
  if (!is_string($version) || !preg_match('/^([0-9]+)\.([0-9]+)$/', $version, $match))
    throw new RuntimeException("invalid backup version in $label");
  if ($match[1] !== '1' || version_compare($version, BACKUP_VERSION, '<'))
    throw new RuntimeException("unsupported backup version $version in $label");
}

function backupQuoteIdentifier(string $identifier): string {
  if (!preg_match('/^[A-Za-z0-9_-]+$/', $identifier))
    throw new RuntimeException("invalid database identifier: $identifier");
  return '`' . $identifier . '`';
}

function backupValidateArchive(string $source): void {
  $output = backupRunProcess(array('tar', '-tzf', $source));
  foreach (explode("\n", trim($output)) as $entry) {
    if ($entry === '')
      continue;
    if (!backupArchiveEntrySafe($entry))
      throw new RuntimeException("unsafe archive entry: $entry");
  }
}

function backupArchiveEntrySafe(string $entry): bool {
  $entry = str_replace('\\', '/', $entry);
  if ($entry === '.' || $entry === './')
    return true;
  if ($entry === '' || $entry[0] === '/')
    return false;
  foreach (explode('/', $entry) as $part) {
    if ($part === '..')
      return false;
  }
  return true;
}

function backupReloadHosts(): void {
  if (!function_exists('runHostsCommand'))
    return;

  $code = runHostsCommand();
  if ($code !== 0)
    throw new RuntimeException('dnsmasq reload failed after restore');
}

function backupRunProcess(array $command, array $env = array(), ?string $stdinFile = null, ?string $stdoutFile = null): string {
  $line = implode(' ', array_map('escapeshellarg', $command));
  $descriptors = array(
    0 => $stdinFile === null ? array('pipe', 'r') : array('file', $stdinFile, 'r'),
    1 => $stdoutFile === null ? array('pipe', 'w') : array('file', $stdoutFile, 'w'),
    2 => array('pipe', 'w')
  );

  $oldEnv = backupApplyEnv($env);
  try {
    $process = proc_open($line, $descriptors, $pipes);
    if (!is_resource($process))
      throw new RuntimeException("failed to run: $line");

    if ($stdinFile === null)
      fclose($pipes[0]);

    $stdout = '';
    if ($stdoutFile === null) {
      $stdout = stream_get_contents($pipes[1]);
      fclose($pipes[1]);
    }

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
  } finally {
    backupRestoreEnv($oldEnv);
  }

  if ($code !== 0) {
    $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    throw new RuntimeException($message !== '' ? $message : "command failed: $line");
  }

  return $stdout;
}

function backupApplyEnv(array $env): array {
  $old = array();
  foreach ($env as $name => $value) {
    $old[$name] = getenv($name);
    putenv($name . '=' . $value);
  }
  return $old;
}

function backupRestoreEnv(array $old): void {
  foreach ($old as $name => $value) {
    if ($value === false)
      putenv($name);
    else
      putenv($name . '=' . $value);
  }
}

function backupCopyDirectory(string $source, string $target): void {
  backupEnsureDir($target);
  if (!is_dir($source))
    return;

  $items = scandir($source);
  if ($items === false)
    throw new RuntimeException("failed to read $source");

  foreach ($items as $item) {
    if ($item === '.' || $item === '..')
      continue;

    $from = $source . '/' . $item;
    $to = $target . '/' . $item;
    if (is_link($from))
      continue;
    if (is_dir($from)) {
      backupCopyDirectory($from, $to);
      continue;
    }
    if (is_file($from)) {
      if (!copy($from, $to))
        throw new RuntimeException("failed to copy $from");
      chmod($to, 0644);
    }
  }
}

function backupReplaceDirectory(string $source, string $target): void {
  backupEnsureDir($target);
  backupClearDirectory($target);
  backupCopyDirectory($source, $target);
}

function backupClearDirectory(string $target): void {
  $real = realpath($target);
  $allowed = array(realpath(backupNetbootDir()));
  if ($real === false || !in_array($real, $allowed, true))
    throw new RuntimeException("refusing to clear unexpected directory: $target");

  $items = scandir($target);
  if ($items === false)
    throw new RuntimeException("failed to read $target");

  foreach ($items as $item) {
    if ($item === '.' || $item === '..')
      continue;
    backupRemoveTree($target . '/' . $item);
  }
}

function backupRemoveTree(string $path): void {
  if (is_link($path) || is_file($path)) {
    @unlink($path);
    return;
  }

  if (!is_dir($path))
    return;

  $items = scandir($path);
  if ($items !== false) {
    foreach ($items as $item) {
      if ($item === '.' || $item === '..')
        continue;
      backupRemoveTree($path . '/' . $item);
    }
  }
  @rmdir($path);
}

function backupTempDir(string $prefix): string {
  $base = sys_get_temp_dir();
  $dir = tempnam($base, $prefix);
  if ($dir === false)
    throw new RuntimeException('failed to create temporary directory');
  @unlink($dir);
  if (!mkdir($dir, 0700, true))
    throw new RuntimeException('failed to create temporary directory');
  return $dir;
}

function backupEnsureDir(string $dir): void {
  if (!is_dir($dir) && !mkdir($dir, 0755, true))
    throw new RuntimeException("failed to create $dir");
}

function backupAbsolutePath(string $path): string {
  if ($path === '')
    return $path;
  if ($path[0] === '/')
    return $path;
  return getcwd() . '/' . $path;
}

function backupNetbootDir(): string {
  return FENPING_DATA_DIR . '/netboot';
}

function backupIsArchive(string $path): bool {
  return str_ends_with($path, '.tgz') || str_ends_with($path, '.tar.gz');
}

function backupCountFiles(string $dir): int {
  if (!is_dir($dir))
    return 0;

  $count = 0;
  $items = scandir($dir);
  if ($items === false)
    return 0;

  foreach ($items as $item) {
    if ($item === '.' || $item === '..')
      continue;
    $path = $dir . '/' . $item;
    if (is_dir($path) && !is_link($path))
      $count += backupCountFiles($path);
    elseif (is_file($path))
      $count++;
  }
  return $count;
}

function backupFormatBytes($value): string {
  $bytes = (float)$value;
  $units = array('B', 'KB', 'MB', 'GB');
  $unit = 0;
  while ($bytes >= 1024 && $unit < count($units) - 1) {
    $bytes /= 1024;
    $unit++;
  }
  return ($unit === 0 ? (string)(int)$bytes : number_format($bytes, 1)) . ' ' . $units[$unit];
}
