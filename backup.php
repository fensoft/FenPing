<?php

define('BACKUP_DIR', FENPING_DATA_DIR . '/backups');
const BACKUP_FORMAT = 'fenping-backup-v1';

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

    if (backupIsArchive($source)) {
      backupRestoreArchive($source);
    } elseif (backupIsSqlGz($source) || backupIsPlainSql($source)) {
      backupRestoreSqlGz($source);
    } else {
      throw new InvalidArgumentException("restore expects a .tgz, .tar.gz, .sql.gz, or .sql file");
    }

    backupApplyCurrentSchema();
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
    . "       php cli.php restore <backup.tgz|dump.sql.gz>";
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
    $sql = $stage . '/db.sql';
    $sqlGz = $stage . '/db.sql.gz';
    backupDumpDatabase($sql);
    backupGzipFile($sql, $sqlGz);
    @unlink($sql);

    backupCopyDirectory(SCAN_DIR, $stage . '/nmap');
    backupCopyDirectory(backupNetbootDir(), $stage . '/netboot');
    backupWriteJson($stage . '/nmap-index.json', backupNmapIndex());
    backupWriteJson($stage . '/netboot-index.json', backupNetbootIndex());
    backupWriteJson($stage . '/manifest.json', backupManifest($sqlGz));

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

    $sqlGz = $stage . '/db.sql.gz';
    $sql = $stage . '/db.sql';
    if (is_file($sqlGz)) {
      backupImportSqlGz($sqlGz);
    } elseif (is_file($sql)) {
      backupImportSql($sql);
    } else {
      throw new RuntimeException('archive does not contain db.sql.gz');
    }

    if (is_dir($stage . '/nmap')) {
      backupReplaceDirectory($stage . '/nmap', SCAN_DIR);
      echo "nmap files restored" . PHP_EOL;
    }

    if (is_dir($stage . '/netboot')) {
      backupReplaceDirectory($stage . '/netboot', backupNetbootDir());
      echo "netboot files restored" . PHP_EOL;
    }
  } finally {
    backupRemoveTree($stage);
  }
}

function backupRestoreSqlGz(string $source): void {
  if (backupIsSqlGz($source))
    backupImportSqlGz($source);
  else
    backupImportSql($source);
}

function backupDumpDatabase(string $target): void {
  global $db_name;

  $command = array_merge(
    array(backupFindExecutable(array('mariadb-dump', 'mysqldump'))),
    backupMysqlArgs(),
    array('--single-transaction', '--quick', '--routines', '--triggers', '--add-drop-database', '--databases', $db_name)
  );
  backupRunProcess($command, backupMysqlEnv(), null, $target);
}

function backupImportSqlGz(string $source): void {
  $stage = backupTempDir('fenping-sql-');
  $sql = $stage . '/restore.sql';

  try {
    backupGunzipFile($source, $sql);
    backupImportSql($sql);
  } finally {
    backupRemoveTree($stage);
  }
}

function backupImportSql(string $source): void {
  global $db_name;

  echo "restoring database" . PHP_EOL;
  $stage = backupTempDir('fenping-import-');
  $prepared = $stage . '/restore.sql';

  try {
    $databaseDump = backupExtractDatabaseSql($source, $prepared);
    $importFile = $databaseDump ? $prepared : $source;
    $command = array_merge(
      array(backupFindExecutable(array('mariadb', 'mysql'))),
      backupMysqlArgs()
    );
    if (!$databaseDump)
      $command[] = $db_name;

    backupRunProcess($command, backupMysqlEnv(), $importFile, null);
  } finally {
    backupRemoveTree($stage);
  }
  echo "database restored" . PHP_EOL;
}

function backupApplyCurrentSchema(): void {
  global $db_name;

  $schema = __DIR__ . '/db.sql';
  if (!is_file($schema) || !is_readable($schema))
    throw new RuntimeException('current schema file not readable: db.sql');

  echo "applying current schema" . PHP_EOL;
  $command = array_merge(
    array(backupFindExecutable(array('mariadb', 'mysql'))),
    backupMysqlArgs(),
    array($db_name)
  );
  backupRunProcess($command, backupMysqlEnv(), $schema, null);
  echo "schema updated" . PHP_EOL;
}

function backupExtractDatabaseSql(string $source, string $target): bool {
  global $db_name;

  $in = fopen($source, 'rb');
  if ($in === false)
    throw new RuntimeException("failed to read $source");

  $out = fopen($target, 'wb');
  if ($out === false) {
    fclose($in);
    throw new RuntimeException("failed to write $target");
  }

  $sawDatabase = false;
  $matchedDatabase = false;
  $write = true;

  try {
    while (($line = fgets($in)) !== false) {
      $database = backupSqlDatabaseMarker($line);
      if ($database !== null) {
        $sawDatabase = true;
        $write = $database === $db_name;
        if ($write)
          $matchedDatabase = true;
      }

      if ((!$sawDatabase || $write) && fwrite($out, $line) === false)
        throw new RuntimeException("failed to write $target");
    }

    if (!feof($in))
      throw new RuntimeException("failed to read $source");
  } finally {
    fclose($in);
    fclose($out);
  }

  if (!$sawDatabase) {
    @unlink($target);
    return false;
  }

  if (!$matchedDatabase)
    throw new RuntimeException("SQL dump does not contain database `$db_name`");

  echo "extracted database `$db_name` from multi-database dump" . PHP_EOL;
  return true;
}

function backupSqlDatabaseMarker(string $line): ?string {
  if (preg_match('/^-- Current Database: `([^`]+)`/', $line, $match))
    return $match[1];

  if (preg_match('/^(?:CREATE|DROP) DATABASE\b.*`([^`]+)`/i', $line, $match))
    return $match[1];

  if (preg_match('/^USE `([^`]+)`;/i', $line, $match))
    return $match[1];

  return null;
}

function backupMysqlArgs(): array {
  global $db_host, $db_port, $db_user;

  $args = array('--user=' . $db_user);
  if (($db_host ?? '') === 'localhost' && is_file('/var/run/mysqld/mysqld.sock'))
    $args[] = '--socket=/var/run/mysqld/mysqld.sock';
  elseif (($db_host ?? '') !== '') {
    $args[] = '--host=' . $db_host;
    $args[] = '--port=' . ($db_port ?? '3306');
  }
  return $args;
}

function backupMysqlEnv(): array {
  global $db_pass;

  if (($db_pass ?? '') === '')
    return array();
  return array('MYSQL_PWD' => $db_pass);
}

function backupNmapIndex(): array {
  $stmt = db()->query("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, xml_hash, error
    FROM scans
    ORDER BY ip ASC, id ASC
  ");
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function backupManifest(string $sqlGz): array {
  global $db_name;

  return array(
    'format' => BACKUP_FORMAT,
    'created_at' => date(DATE_ATOM),
    'database' => $db_name,
    'hostname' => gethostname() ?: 'fenping',
    'includes' => array(
      'db' => 'db.sql.gz',
      'nmap' => 'nmap/',
      'netboot' => 'netboot/',
      'nmap_index' => 'nmap-index.json',
      'netboot_index' => 'netboot-index.json'
    ),
    'counts' => array(
      'nmap_files' => backupCountFiles(SCAN_DIR),
      'netboot_files' => backupCountFiles(backupNetbootDir()),
      'scan_rows' => count(backupNmapIndex()),
      'netboot_rows' => count(backupNetbootIndex())
    ),
    'db_sql_gz_bytes' => filesize($sqlGz)
  );
}

function backupWriteJson(string $path, array $data): void {
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false)
    throw new RuntimeException("failed to encode " . basename($path));
  if (file_put_contents($path, $json . PHP_EOL) === false)
    throw new RuntimeException("failed to write $path");
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

function backupFindExecutable(array $names): string {
  foreach ($names as $name) {
    $output = array();
    $code = 0;
    exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $output, $code);
    if ($code === 0 && isset($output[0]) && trim($output[0]) !== '')
      return trim($output[0]);
  }
  throw new RuntimeException('missing command: ' . implode(' or ', $names));
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

function backupGzipFile(string $source, string $target): void {
  $in = fopen($source, 'rb');
  if ($in === false)
    throw new RuntimeException("failed to read $source");

  $out = gzopen($target, 'wb9');
  if ($out === false) {
    fclose($in);
    throw new RuntimeException("failed to write $target");
  }

  try {
    while (!feof($in)) {
      $chunk = fread($in, 1024 * 1024);
      if ($chunk === false)
        throw new RuntimeException("failed to read $source");
      gzwrite($out, $chunk);
    }
  } finally {
    fclose($in);
    gzclose($out);
  }
}

function backupGunzipFile(string $source, string $target): void {
  if (backupIsPlainSql($source)) {
    if (!copy($source, $target))
      throw new RuntimeException("failed to copy $source");
    return;
  }

  $in = gzopen($source, 'rb');
  if ($in === false)
    throw new RuntimeException("failed to read $source");

  $out = fopen($target, 'wb');
  if ($out === false) {
    gzclose($in);
    throw new RuntimeException("failed to write $target");
  }

  try {
    while (!gzeof($in)) {
      $chunk = gzread($in, 1024 * 1024);
      if ($chunk === false)
        throw new RuntimeException("failed to read $source");
      fwrite($out, $chunk);
    }
  } finally {
    gzclose($in);
    fclose($out);
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
  $allowed = array(realpath(SCAN_DIR), realpath(backupNetbootDir()));
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

function backupIsSqlGz(string $path): bool {
  return str_ends_with($path, '.sql.gz');
}

function backupIsPlainSql(string $path): bool {
  return str_ends_with($path, '.sql');
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
