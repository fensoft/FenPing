<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$leaseFile = '/var/lib/misc/dnsmasq.leases';
$lines = is_readable($leaseFile) ? file($leaseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
if ($lines === false)
  $lines = array();

$leases = array();
foreach ($lines as $line) {
  $parts = preg_split('/\s+/', trim($line));
  if ($parts === false || count($parts) < 4 || !ctype_digit($parts[0]))
    continue;

  $expiry = (int)$parts[0];
  $mac = strtolower($parts[1]);
  $ip = $parts[2];
  $hostname = $parts[3] === '*' ? '' : $parts[3];
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
    || preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) !== 1)
    continue;

  $leases[] = array(
    'ip' => $ip,
    'ends' => $expiry === 0 ? '9999-12-31 23:59:59' : date('Y-m-d H:i:s', $expiry),
    'mac' => $mac,
    'hostname' => $hostname
  );
}

usort($leases, 'compareLeaseRows');
$db = db();
$current = $db->query("
  SELECT ip, ends, LOWER(`hardware-ethernet`) AS mac, IFNULL(`client-hostname`, '') AS hostname
  FROM leases
")->fetchAll(PDO::FETCH_ASSOC);
usort($current, 'compareLeaseRows');

if ($leases === $current)
  exit(0);

$db->beginTransaction();
try {
  $db->exec('DELETE FROM leases');
  $stmt = $db->prepare("
    INSERT INTO leases (`ip`, `starts`, `ends`, `cltt`, `hardware-ethernet`, `client-hostname`)
    VALUES (:ip, :starts, :ends, :cltt, :mac, :hostname)
  ");
  $now = date('Y-m-d H:i:s');
  foreach ($leases as $lease) {
    $stmt->execute(array(
      'ip' => $lease['ip'],
      'starts' => $now,
      'ends' => $lease['ends'],
      'cltt' => $now,
      'mac' => $lease['mac'],
      'hostname' => $lease['hostname']
    ));
  }
  $db->commit();
} catch (Throwable $e) {
  if ($db->inTransaction())
    $db->rollBack();
  throw $e;
}

function compareLeaseRows(array $a, array $b): int {
  return array($a['ip'], $a['mac'], $a['ends'], $a['hostname'])
    <=> array($b['ip'], $b['mac'], $b['ends'], $b['hostname']);
}
