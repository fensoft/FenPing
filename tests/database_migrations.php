<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/database.php';

function assertMigration(bool $condition, string $message): void {
  if (!$condition)
    throw new RuntimeException($message);
}

function migrationDatabase(): PDO {
  $database = new PDO('sqlite::memory:');
  $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $database->exec('PRAGMA foreign_keys=ON');
  return $database;
}

function expectMigrationFailure(callable $callback, string $message): void {
  try {
    $callback();
  } catch (RuntimeException $error) {
    return;
  }
  throw new RuntimeException($message);
}

$upgrade = migrationDatabase();
$upgrade->exec("
  CREATE TABLE ips (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT COLLATE NOCASE UNIQUE,
    mac TEXT COLLATE NOCASE UNIQUE,
    ip TEXT UNIQUE,
    important INTEGER,
    repeater INTEGER,
    web INTEGER,
    router INTEGER,
    dns TEXT,
    netboot_image_id INTEGER,
    scan_profile TEXT NOT NULL DEFAULT 'deep',
    scan_interval_hours INTEGER NOT NULL DEFAULT 1
  );
  CREATE INDEX ips_netboot_image_id ON ips (netboot_image_id);
  INSERT INTO ips (name, mac, ip, scan_profile, scan_interval_hours)
  VALUES ('Existing host', '02:00:00:00:00:40', '192.0.2.40', 'deep', 1);
  PRAGMA user_version=1;
");

databaseApplyMigrations($upgrade, DATABASE_SCHEMA_VERSION, DATABASE_MIGRATIONS_DIR);
assertMigration(databaseSchemaVersion($upgrade) === 2, 'version-1 database did not migrate to version 2');
$existing = $upgrade->query("SELECT name, scan_profile, scan_interval_hours FROM ips WHERE ip='192.0.2.40'")->fetch(PDO::FETCH_ASSOC);
assertMigration($existing !== false && $existing['name'] === 'Existing host', 'migration lost an existing host');
assertMigration($existing['scan_profile'] === 'deep' && (int)$existing['scan_interval_hours'] === 1, 'migration changed an existing host schedule');

$upgrade->exec("INSERT INTO ips (name, mac, ip) VALUES ('New host', '02:00:00:00:00:41', '192.0.2.41')");
$created = $upgrade->query("SELECT scan_profile, scan_interval_hours FROM ips WHERE ip='192.0.2.41'")->fetch(PDO::FETCH_ASSOC);
assertMigration($created !== false && $created['scan_profile'] === 'standard', 'migrated schema has the wrong scan profile default');
assertMigration((int)$created['scan_interval_hours'] === 24, 'migrated schema has the wrong scan cadence default');
databaseApplyMigrations($upgrade, DATABASE_SCHEMA_VERSION, DATABASE_MIGRATIONS_DIR);

$temporary = sys_get_temp_dir() . '/fenping-migrations-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700))
  throw new RuntimeException('failed to create migration test directory');

try {
  file_put_contents($temporary . '/0002_add_note.sql', 'ALTER TABLE migration_probe ADD COLUMN note TEXT;');
  file_put_contents($temporary . '/0003_add_row.sql', "INSERT INTO migration_probe (value, note) VALUES ('kept', 'version 3');");

  $sequence = migrationDatabase();
  $sequence->exec('CREATE TABLE migration_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL); PRAGMA user_version=1;');
  databaseApplyMigrations($sequence, 3, $temporary);
  assertMigration(databaseSchemaVersion($sequence) === 3, 'sequential migrations did not reach version 3');
  assertMigration((int)$sequence->query("SELECT COUNT(*) FROM migration_probe WHERE value='kept' AND note='version 3'")->fetchColumn() === 1, 'sequential migration data is missing');

  file_put_contents($temporary . '/0004_transaction.sql', 'COMMIT;');
  expectMigrationFailure(
    fn() => databaseApplyMigrations($sequence, 4, $temporary),
    'migration transaction control was accepted'
  );
  assertMigration(databaseSchemaVersion($sequence) === 3, 'transaction-control migration changed user_version');
  unlink($temporary . '/0004_transaction.sql');

  file_put_contents($temporary . '/0004_failure.sql', "INSERT INTO migration_probe (value) VALUES ('rolled back'); SELECT * FROM missing_table;");
  expectMigrationFailure(
    fn() => databaseApplyMigrations($sequence, 4, $temporary),
    'failing migration was accepted'
  );
  assertMigration(databaseSchemaVersion($sequence) === 3, 'failing migration advanced user_version');
  assertMigration((int)$sequence->query("SELECT COUNT(*) FROM migration_probe WHERE value='rolled back'")->fetchColumn() === 0, 'failing migration was not rolled back');

  unlink($temporary . '/0004_failure.sql');
  expectMigrationFailure(
    fn() => databaseApplyMigrations($sequence, 4, $temporary),
    'missing migration version was accepted'
  );
  assertMigration(databaseSchemaVersion($sequence) === 3, 'missing migration changed user_version');
} finally {
  foreach (glob($temporary . '/*') ?: array() as $path)
    unlink($path);
  rmdir($temporary);
}

echo "database migration tests passed" . PHP_EOL;
