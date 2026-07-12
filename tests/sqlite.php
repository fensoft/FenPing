<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/scans.php';
require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/inventory.php';
require_once dirname(__DIR__) . '/ping.php';

if (!str_contains(basename(DATABASE_PATH), 'test'))
  throw new RuntimeException('refusing to run destructive SQLite tests outside a test database');

function assertSqlite(bool $condition, string $message): void {
  if (!$condition)
    throw new RuntimeException($message);
}

databaseInitialize();
databaseInitialize();
$database = db();
assertSqlite((int)$database->query('PRAGMA user_version')->fetchColumn() === DATABASE_SCHEMA_VERSION, 'schema version is wrong');
assertSqlite(strtolower((string)$database->query('PRAGMA journal_mode')->fetchColumn()) === 'wal', 'WAL mode is disabled');
assertSqlite((int)$database->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'foreign keys are disabled');
assertSqlite(databaseIntegrityErrors() === array(), 'fresh database failed integrity checks');

$database->exec('DELETE FROM ips');
$managedIp = '192.0.2.30';
$managedId = (int)create($managedIp, '02:00:00:00:00:30');
$managed = getId($managedId);
assertSqlite($managed !== false, 'managed host was not created');
assertSqlite($managed['scan_profile'] === SCAN_MANAGED_DEFAULT_PROFILE, 'new managed host profile is not the safe default');
assertSqlite((int)$managed['scan_interval_hours'] === SCAN_MANAGED_DEFAULT_INTERVAL_HOURS, 'new managed host cadence is not the safe default');

$legacyIp = '192.0.2.31';
$legacy = $database->prepare("INSERT INTO ips (mac, ip, scan_profile, scan_interval_hours) VALUES (:mac, :ip, 'deep', 1)");
$legacy->execute(array('mac' => '02:00:00:00:00:31', 'ip' => $legacyIp));
$scheduleDay = gmmktime(0, 0, 0, 7, 12, 2026);
$unmanagedIp = '192.0.2.32';
$unmanagedDueTime = $scheduleDay + inventoryInitialUnmanagedScanHour($unmanagedIp) * 3600;
$unmanagedDue = inventoryScheduledTargets(array($unmanagedIp), $unmanagedDueTime);
assertSqlite(count($unmanagedDue) === 1 && $unmanagedDue[0]['profile'] === SCAN_UNMANAGED_DEFAULT_PROFILE, 'unmanaged host did not receive its staggered lightweight scan');
assertSqlite(inventoryScheduledTargets(array($unmanagedIp), $unmanagedDueTime + 3600) === array(), 'unmanaged first scan was not staggered');

$managedDue = inventoryScheduledTargets(array($managedIp), $scheduleDay);
assertSqlite(count($managedDue) === 1 && $managedDue[0]['profile'] === SCAN_MANAGED_DEFAULT_PROFILE, 'new managed host did not receive its standard scan');
$legacyDue = inventoryScheduledTargets(array($legacyIp), $scheduleDay);
assertSqlite(count($legacyDue) === 1 && $legacyDue[0]['profile'] === 'deep', 'existing managed scan settings were not preserved');

$recentScan = $database->prepare("
  INSERT INTO scans (ip, mode, state, date_begin, date_end)
  VALUES (:ip, :mode, 'complete', :scanned_at, :scanned_at)
");
$recentScan->execute(array(
  'ip' => $managedIp,
  'mode' => SCAN_MANAGED_DEFAULT_PROFILE,
  'scanned_at' => gmdate('Y-m-d H:i:s', $scheduleDay)
));
assertSqlite(inventoryScheduledTargets(array($managedIp), $scheduleDay) === array(), 'managed scan cadence ignored a recent scan');

$database->exec('DELETE FROM ping');
$database->exec('DELETE FROM stats');
savePingHosts(array(
  array('ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Up'),
  array('ip' => '192.0.2.11', 'mac' => '', 'status' => 'Down')
));
assertSqlite((int)$database->query('SELECT COUNT(*) FROM ping')->fetchColumn() === 2, 'ping batch was not stored');
assertSqlite((int)$database->query('SELECT COUNT(*) FROM stats')->fetchColumn() === 2, 'initial status rows are wrong');
savePingHosts(array(array('ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Up')));
assertSqlite((int)$database->query("SELECT COUNT(*) FROM stats WHERE ip='192.0.2.10'")->fetchColumn() === 1, 'unchanged status created history');
$database->exec("UPDATE stats SET date_end=datetime('now', '-2 days') WHERE ip='192.0.2.10'");
savePingHosts(array(array('ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Up')));
assertSqlite((int)$database->query("SELECT nb_scan FROM stats WHERE ip='192.0.2.10'")->fetchColumn() === 2, 'daily status extension was not recorded');
savePingHosts(array(array('ip' => '192.0.2.10', 'mac' => '00:11:22:33:44:55', 'status' => 'Down')));
assertSqlite((int)$database->query("SELECT COUNT(*) FROM stats WHERE ip='192.0.2.10'")->fetchColumn() === 2, 'status transition was not recorded');

$database->exec('DELETE FROM scans');
$first = scanMetadataEnqueue('192.0.2.20', 'lightweight');
$upgraded = scanMetadataEnqueue('192.0.2.20', 'deep');
assertSqlite($first['created'] && !$upgraded['created'], 'queued scan was not upgraded');
assertSqlite((int)$first['metadata']['id'] === (int)$upgraded['metadata']['id'], 'scan upgrade created a second job');
foreach (range(21, 26) as $octet)
  scanMetadataEnqueue('192.0.2.' . $octet, 'standard');
$claimed = scanMetadataClaimQueued(4);
assertSqlite(count($claimed) === 4, 'scan claim did not fill four slots');
assertSqlite(scanMetadataRunningCount() === 4, 'running scan count exceeded or missed capacity');
assertSqlite(scanMetadataClaimQueued(4) === array(), 'full queue claimed additional jobs');
scanMetadataFailed((int)$claimed[0]['id'], 'test completion');
assertSqlite(count(scanMetadataClaimQueued(4)) === 1, 'freed scan slot was not reclaimed');
assertSqlite(scanMetadataRunningCount() === 4, 'reclaim changed global concurrency');

$duplicateStates = (int)$database->query("
  SELECT COUNT(*) FROM (
    SELECT ip, state, COUNT(*) AS total FROM scans
    WHERE state IN ('queued', 'running') GROUP BY ip, state HAVING total>1
  )
")->fetchColumn();
assertSqlite($duplicateStates === 0, 'duplicate active scan state exists for an IP');
assertSqlite(databaseIntegrityErrors() === array(), 'populated database failed integrity checks');

echo "SQLite tests passed\n";
