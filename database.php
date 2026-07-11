<?php

const DATABASE_SCHEMA_VERSION = 1;
const DATABASE_BUSY_TIMEOUT_MS = 30000;

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
  $version = (int)$database->query('PRAGMA user_version')->fetchColumn();
  if ($version > DATABASE_SCHEMA_VERSION)
    throw new RuntimeException("database schema version $version is newer than supported version " . DATABASE_SCHEMA_VERSION);
  if ($version === DATABASE_SCHEMA_VERSION)
    return;
  if ($version !== 0)
    throw new RuntimeException("unsupported database schema version: $version");

  $schema = file_get_contents(__DIR__ . '/db.sql');
  if ($schema === false)
    throw new RuntimeException('failed to read db.sql');
  dbBeginImmediate($database);
  try {
    $database->exec($schema);
    dbCommit($database);
  } catch (Throwable $error) {
    dbRollback($database);
    throw $error;
  }
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
