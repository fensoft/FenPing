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
$db->exec('DROP TEMPORARY TABLE IF EXISTS lease_import_stage');
$db->exec("
  CREATE TEMPORARY TABLE lease_import_stage (
    ip varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mac char(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    hostname varchar(255) DEFAULT NULL,
    ends datetime NOT NULL,
    PRIMARY KEY (mac, ip)
  ) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$stage = $db->prepare("
  INSERT INTO lease_import_stage (ip, mac, hostname, ends)
  VALUES (:ip, :mac, :hostname, :ends)
");
foreach ($leases as $lease)
  $stage->execute($lease);

$observedAt = date('Y-m-d H:i:s');
$db->beginTransaction();
try {
  $upsert = $db->prepare("
    INSERT INTO leases
      (ip, `hardware-ethernet`, `client-hostname`, ends, first_seen, last_seen, active)
    SELECT ip, mac, hostname, ends, :first_seen, :last_seen, 1
    FROM lease_import_stage
    ON DUPLICATE KEY UPDATE
      `client-hostname`=VALUES(`client-hostname`),
      ends=VALUES(ends),
      last_seen=VALUES(last_seen),
      active=1
  ");
  $upsert->execute(array('first_seen' => $observedAt, 'last_seen' => $observedAt));

  $db->exec("
    UPDATE leases current
    LEFT JOIN lease_import_stage imported
      ON imported.mac=current.`hardware-ethernet` AND imported.ip=current.ip
    SET current.active=0
    WHERE current.active=1 AND imported.mac IS NULL
  ");
  $db->commit();
} catch (Throwable $e) {
  if ($db->inTransaction())
    $db->rollBack();
  throw $e;
}
