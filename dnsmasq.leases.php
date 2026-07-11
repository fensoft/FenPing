<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$leaseFile = '/var/lib/misc/dnsmasq.leases';
if (!is_readable($leaseFile))
  throw new RuntimeException('dnsmasq lease file is not readable');

$lines = file($leaseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false)
  throw new RuntimeException('failed to read dnsmasq lease file');

$leases = array();
foreach ($lines as $line) {
  $parts = preg_split('/\s+/', trim($line));
  if ($parts === false || count($parts) < 4 || !ctype_digit($parts[0]))
    continue;

  $expiry = (int)$parts[0];
  $mac = strtolower($parts[1]);
  $ip = $parts[2];
  $hostname = $parts[3] === '*' ? null : $parts[3];
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
    || preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) !== 1)
    continue;

  $leases[$mac . "\0" . $ip] = array(
    'ip' => $ip,
    'ends' => $expiry === 0 ? '9999-12-31 23:59:59' : date('Y-m-d H:i:s', $expiry),
    'mac' => $mac,
    'hostname' => $hostname
  );
}

if (count($lines) > 0 && count($leases) === 0)
  throw new RuntimeException('dnsmasq lease file contains no valid IPv4 leases');

$db = db();
$db->exec('DROP TABLE IF EXISTS lease_import_stage');
$db->exec("
  CREATE TEMPORARY TABLE lease_import_stage (
    ip TEXT NOT NULL,
    mac TEXT NOT NULL,
    hostname TEXT DEFAULT NULL,
    ends DATETIME NOT NULL,
    PRIMARY KEY (mac, ip)
  )
");

$stage = $db->prepare("
  INSERT INTO lease_import_stage (ip, mac, hostname, ends)
  VALUES (:ip, :mac, :hostname, :ends)
");
foreach ($leases as $lease)
  $stage->execute($lease);

$observedAt = date('Y-m-d H:i:s');
dbBeginImmediate($db);
try {
  $upsert = $db->prepare("
    INSERT INTO leases
      (ip, `hardware-ethernet`, `client-hostname`, ends, first_seen, last_seen, active)
    SELECT ip, mac, hostname, ends, :first_seen, :last_seen, 1
    FROM lease_import_stage
    WHERE 1
    ON CONFLICT(`hardware-ethernet`, ip) DO UPDATE SET
      `client-hostname`=excluded.`client-hostname`,
      ends=excluded.ends,
      last_seen=excluded.last_seen,
      active=1
  ");
  $upsert->execute(array('first_seen' => $observedAt, 'last_seen' => $observedAt));

  $db->exec("
    UPDATE leases
    SET active=0
    WHERE active=1 AND NOT EXISTS (
      SELECT 1 FROM lease_import_stage imported
      WHERE imported.mac=leases.`hardware-ethernet` AND imported.ip=leases.ip
    )
  ");
  dbCommit($db);
} catch (Throwable $e) {
  dbRollback($db);
  throw $e;
}
