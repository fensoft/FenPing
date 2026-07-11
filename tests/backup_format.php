<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backup.php';

function assertBackup(bool $condition, string $message): void {
  if (!$condition)
    throw new RuntimeException($message);
}

function assertBackupRejected(array $document, string $format): void {
  try {
    backupValidateDocument($document, $format, 'test.json');
  } catch (RuntimeException $e) {
    return;
  }
  throw new RuntimeException('unsupported backup document was accepted');
}

backupValidateDocument(
  array('format' => BACKUP_DATABASE_FORMAT, 'version' => '1.6'),
  BACKUP_DATABASE_FORMAT,
  'test.json'
);
backupValidateDocument(
  array('format' => BACKUP_DATABASE_FORMAT, 'version' => '1.99'),
  BACKUP_DATABASE_FORMAT,
  'test.json'
);
assertBackupRejected(
  array('format' => BACKUP_DATABASE_FORMAT, 'version' => '1.5'),
  BACKUP_DATABASE_FORMAT
);
assertBackupRejected(
  array('format' => BACKUP_DATABASE_FORMAT, 'version' => '2.0'),
  BACKUP_DATABASE_FORMAT
);

assertBackup(backupArchiveEntrySafe('./db.json'), 'safe archive entry was rejected');
assertBackup(!backupArchiveEntrySafe('../db.json'), 'parent archive entry was accepted');
assertBackup(!backupArchiveEntrySafe('/db.json'), 'absolute archive entry was accepted');

$demoDbPath = __DIR__ . '/../demo/db.json';
$manifest = backupReadJson(__DIR__ . '/../demo/manifest.json', 'manifest.json');
$database = backupReadJson($demoDbPath, 'db.json');
backupValidateDocument($manifest, BACKUP_FORMAT, 'manifest.json');
backupValidateDocument($database, BACKUP_DATABASE_FORMAT, 'db.json');
assertBackup($manifest['version'] === $database['version'], 'demo versions differ');
assertBackup(($manifest['includes']['db'] ?? null) === 'db.json', 'demo does not include db.json');
assertBackup(($manifest['database']['bytes'] ?? null) === filesize($demoDbPath), 'demo byte count is stale');

$rows = 0;
foreach ($database['tables'] as $table) {
  assertBackup(is_array($table['columns'] ?? null), 'demo table columns are invalid');
  assertBackup(is_array($table['rows'] ?? null), 'demo table rows are invalid');
  $rows += count($table['rows']);
}
assertBackup(($manifest['database']['tables'] ?? null) === count($database['tables']), 'demo table count is stale');
assertBackup(($manifest['database']['rows'] ?? null) === $rows, 'demo row count is stale');

echo "backup format tests passed" . PHP_EOL;
