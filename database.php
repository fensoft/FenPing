<?php

const DATABASE_SCHEMA_VERSION = 2;
const DATABASE_BUSY_TIMEOUT_MS = 30000;
const DATABASE_MIGRATIONS_DIR = __DIR__ . '/migrations';

function db(): PDO {
  static $database = null;
  if ($database !== null)
    return $database;

  $path = DATABASE_PATH;
  $directory = dirname($path);
  if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory))
    throw new RuntimeException("failed to create database directory: $directory");

  $database = new PDO('sqlite:' . $path);
  $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $database->exec('PRAGMA busy_timeout=' . DATABASE_BUSY_TIMEOUT_MS);
  $database->exec('PRAGMA foreign_keys=ON');
  $database->exec('PRAGMA temp_store=MEMORY');
  $database->exec('PRAGMA synchronous=NORMAL');
  $journal = strtolower((string)$database->query('PRAGMA journal_mode=WAL')->fetchColumn());
  if ($journal !== 'wal')
    throw new RuntimeException("failed to enable SQLite WAL mode: $journal");
  $database->sqliteCreateFunction('ipv4_num', 'databaseIpv4Number', 1, PDO::SQLITE_DETERMINISTIC);
  return $database;
}

function databaseIpv4Number($value): ?int {
  $packed = filter_var((string)$value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
  if ($packed === false)
    return null;
  $number = ip2long($packed);
  return $number === false ? null : (int)sprintf('%u', $number);
}

function databaseInitialize(): void {
  $database = db();
  $version = databaseSchemaVersion($database);
  if ($version > DATABASE_SCHEMA_VERSION)
    throw new RuntimeException("database schema version $version is newer than supported version " . DATABASE_SCHEMA_VERSION);

  if ($version === 0)
    databaseApplyBaseSchema($database);
  else
    databaseApplyMigrations($database, DATABASE_SCHEMA_VERSION, DATABASE_MIGRATIONS_DIR);

  $version = databaseSchemaVersion($database);
  if ($version !== DATABASE_SCHEMA_VERSION)
    throw new RuntimeException("database schema initialization stopped at version $version; expected " . DATABASE_SCHEMA_VERSION);
}

function databaseApplyBaseSchema(PDO $database): void {
  $schema = file_get_contents(__DIR__ . '/db.sql');
  if ($schema === false)
    throw new RuntimeException('failed to read db.sql');

  dbBeginImmediate($database);
  try {
    $database->exec($schema);
    $version = databaseSchemaVersion($database);
    if ($version !== DATABASE_SCHEMA_VERSION)
      throw new RuntimeException("db.sql created schema version $version; expected " . DATABASE_SCHEMA_VERSION);
    dbCommit($database);
  } catch (Throwable $error) {
    dbRollback($database);
    throw $error;
  }
}

function databaseApplyMigrations(PDO $database, int $targetVersion, string $directory): void {
  $currentVersion = databaseSchemaVersion($database);
  if ($currentVersion > $targetVersion)
    throw new RuntimeException("database schema version $currentVersion is newer than supported version $targetVersion");

  $migrations = databaseMigrationFiles($directory, $targetVersion);
  for ($version = $currentVersion + 1; $version <= $targetVersion; $version++) {
    if (!isset($migrations[$version]))
      throw new RuntimeException("missing database migration for version $version");
    databaseApplyMigration($database, $version, $migrations[$version]);
  }
}

function databaseMigrationFiles(string $directory, int $targetVersion): array {
  if (!is_dir($directory))
    throw new RuntimeException("database migrations directory not found: $directory");

  $migrations = array();
  foreach (glob(rtrim($directory, '/') . '/*.sql') ?: array() as $path) {
    $filename = basename($path);
    if (!preg_match('/^(\d{4})_[a-z0-9][a-z0-9_-]*\.sql$/', $filename, $matches))
      throw new RuntimeException("invalid database migration filename: $filename");

    $version = (int)$matches[1];
    if ($version < 2)
      throw new RuntimeException("database migration versions must begin at 2: $filename");
    if ($version > $targetVersion)
      throw new RuntimeException("database migration $filename exceeds schema version $targetVersion");
    if (isset($migrations[$version]))
      throw new RuntimeException("duplicate database migration version $version");
    $migrations[$version] = $path;
  }

  ksort($migrations, SORT_NUMERIC);
  return $migrations;
}

function databaseApplyMigration(PDO $database, int $version, string $path): void {
  $filename = basename($path);
  $sql = file_get_contents($path);
  if ($sql === false)
    throw new RuntimeException("failed to read database migration: $filename");
  if (trim($sql) === '')
    throw new RuntimeException("database migration is empty: $filename");
  $guardSql = preg_replace(array('/--[^\r\n]*/', '/\/\*.*?\*\//s'), '', $sql);
  if (preg_match('/\bPRAGMA\s+user_version\b/i', $guardSql))
    throw new RuntimeException("database migration must not set user_version: $filename");
  if (preg_match('/(?:^|;)\s*(?:BEGIN|COMMIT|END|ROLLBACK|SAVEPOINT|RELEASE)\b/i', $guardSql))
    throw new RuntimeException("database migration must not manage transactions: $filename");

  dbBeginImmediate($database);
  try {
    $database->exec($sql);
    $database->exec('PRAGMA user_version=' . $version);
    if (databaseSchemaVersion($database) !== $version)
      throw new RuntimeException("database migration did not reach version $version: $filename");
    dbCommit($database);
  } catch (Throwable $error) {
    dbRollback($database);
    throw new RuntimeException("database migration $filename failed: " . $error->getMessage(), 0, $error);
  }
}

function databaseSchemaVersion(PDO $database): int {
  return (int)$database->query('PRAGMA user_version')->fetchColumn();
}

function dbBeginImmediate(?PDO $database = null): void {
  $database ??= db();
  if ($database->inTransaction())
    throw new RuntimeException('database transaction already active');
  $database->exec('BEGIN IMMEDIATE');
}

function dbCommit(?PDO $database = null): void {
  $database ??= db();
  if (!$database->inTransaction())
    throw new RuntimeException('database transaction is not active');
  $database->exec('COMMIT');
}

function dbRollback(?PDO $database = null): void {
  $database ??= db();
  if ($database->inTransaction())
    $database->exec('ROLLBACK');
}

function databaseIntegrityErrors(): array {
  $errors = array();
  foreach (db()->query('PRAGMA integrity_check') as $row) {
    $message = (string)($row['integrity_check'] ?? array_values($row)[0] ?? '');
    if ($message !== 'ok')
      $errors[] = $message;
  }
  foreach (db()->query('PRAGMA foreign_key_check') as $row)
    $errors[] = 'foreign key: ' . implode(', ', array_map('strval', array_values($row)));
  return $errors;
}

function pingStatements(): array {
  static $statements = null;
  if ($statements !== null)
    return $statements;

  $database = db();
  $statements = array(
    'upsert' => $database->prepare("
      INSERT INTO ping (ip, mac, status)
      VALUES (:ip, NULLIF(:mac, ''), :status)
      ON CONFLICT(ip) DO UPDATE SET
        date=CASE
          WHEN COALESCE(excluded.mac, ping.mac) IS NOT ping.mac OR excluded.status IS NOT ping.status
          THEN CURRENT_TIMESTAMP ELSE ping.date END,
        mac=COALESCE(excluded.mac, ping.mac),
        status=excluded.status
    "),
    'latestStatus' => $database->prepare("
      SELECT id, ip, mac, status, date_begin, date_end
      FROM stats WHERE ip=:ip ORDER BY id DESC LIMIT 1
    "),
    'extendStatus' => $database->prepare("
      UPDATE stats SET date_end=CURRENT_TIMESTAMP, nb_scan=nb_scan+1
      WHERE id=:id AND (date_end IS NULL OR date_end<=datetime('now', '-1 day'))
    "),
    'insertStatus' => $database->prepare("
      INSERT INTO stats (ip, mac, status) VALUES (:ip, :mac, :status)
    ")
  );
  return $statements;
}

function updateStatusHistory(array $statements, string $ip, ?string $mac, string $status): void {
  $status = trim($status) === '' ? 'Down' : $status;
  $statements['latestStatus']->execute(array('ip' => $ip));
  $latest = $statements['latestStatus']->fetch(PDO::FETCH_ASSOC);
  if ($latest !== false
      && (string)$latest['ip'] === $ip
      && ($latest['mac'] ?? null) === $mac
      && (string)$latest['status'] === $status) {
    $statements['extendStatus']->execute(array('id' => $latest['id']));
    return;
  }
  $statements['insertStatus']->execute(array('ip' => $ip, 'mac' => $mac, 'status' => $status));
}
