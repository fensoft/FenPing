<?php

require_once __DIR__ . "/config.php";

$leaseFile = "/var/lib/misc/dnsmasq.leases";
$lines = is_readable($leaseFile) ? file($leaseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
if ($lines === false)
  $lines = array();

$db = new PDO('mysql:host=' . $db_host . ';port=' . ($db_port ?? '3306') . ';dbname=' . $db_name, $db_user, $db_pass);
$stmt = $db->prepare("truncate leases");
$stmt->execute();

$stmt = $db->prepare("
  INSERT INTO leases (`ip`, `starts`, `ends`, `cltt`, `hardware-ethernet`, `client-hostname`)
  VALUES (:ip, :starts, :ends, :cltt, :mac, :hostname)
");

$now = date("Y-m-d H:i:s");
foreach ($lines as $line) {
  $parts = preg_split('/\s+/', trim($line));
  if (count($parts) < 4)
    continue;

  $expiry = (int)$parts[0];
  $mac = strtolower($parts[1]);
  $ip = $parts[2];
  $hostname = $parts[3] === "*" ? "" : $parts[3];
  $ends = $expiry === 0 ? "9999-12-31 23:59:59" : date("Y-m-d H:i:s", $expiry);

  $stmt->execute(array(
    "ip" => $ip,
    "starts" => $now,
    "ends" => $ends,
    "cltt" => $now,
    "mac" => $mac,
    "hostname" => $hostname
  ));
}
