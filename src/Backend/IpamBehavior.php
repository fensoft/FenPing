<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

trait IpamBehavior
{
public const IPAM_PENDING_DAYS = 7;

public function getIpam(): array {
  $pool = $this->ipamPoolConfig();
  $observations = $this->ipamLatestObservations();
  $approvals = $this->ipamApprovals();
  $managed = $this->ipamManagedMacs();
  $cutoff = time() - self::IPAM_PENDING_DAYS * 86400;
  $pending = array();
  $approved = array();

  foreach ($observations as $mac => $observation) {
    if (!$this->config->dhcpNetwork->contains((string)($observation['ip'] ?? '')))
      continue;
    if (isset($managed[$mac]) || isset($approvals[$mac]))
      continue;
    $seen = strtotime((string)($observation['last_seen'] ?? ''));
    if ($seen !== false && $seen >= $cutoff)
      $pending[] = $this->ipamDeviceRow($mac, $observation, null);
  }

  foreach ($approvals as $mac => $approvedAt) {
    if (isset($managed[$mac]))
      continue;
    if (isset($observations[$mac]) && !$this->config->dhcpNetwork->contains((string)($observations[$mac]['ip'] ?? '')))
      continue;
    $approved[] = $this->ipamDeviceRow($mac, $observations[$mac] ?? array(), $approvedAt);
  }

  usort($pending, fn($left, $right) => strcmp((string)$right['last_seen'], (string)$left['last_seen']));
  usort($approved, fn($left, $right) => strcmp((string)$right['approved_at'], (string)$left['approved_at']));

  return array(
    'network' => $this->config->network,
    'pool' => $this->ipamPoolUtilization($pool),
    'pending' => $pending,
    'approved' => $approved
  );
}

public function ipamPoolConfig(): array {
  $prefix = trim((string)($this->config->network ?? ''));
  if (filter_var($prefix . '.0', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
    || substr_count($prefix, '.') !== 2)
    throw new RuntimeException('invalid DHCP_NETWORK for DHCP pool');

  $beginText = trim((string)($this->config->dhcpDynamicBegin ?? ''));
  $endText = trim((string)($this->config->dhcpDynamicEnd ?? ''));
  if (!ctype_digit($beginText) || !ctype_digit($endText))
    throw new RuntimeException('invalid DHCP pool bounds');
  $begin = (int)$beginText;
  $end = (int)$endText;
  if ($begin < 1 || $begin > 254 || $end < 1 || $end > 254 || $begin > $end)
    throw new RuntimeException('invalid DHCP pool bounds');

  return array(
    'prefix' => $prefix,
    'begin' => $begin,
    'end' => $end,
    'start' => $prefix . '.' . $begin,
    'finish' => $prefix . '.' . $end,
    'total' => $end - $begin + 1
  );
}

public function ipamPoolUtilization(array $pool): array {
  $leases = array();
  $stmt = $this->db()->query("
    SELECT ip
    FROM leases
    WHERE active=1 AND ends>CURRENT_TIMESTAMP
  ");
  while ($ip = $stmt->fetchColumn()) {
    $ip = (string)$ip;
    if ($this->ipamAddressInPool($ip, $pool))
      $leases[$ip] = true;
  }

  $reservations = array();
  $stmt = $this->db()->query("SELECT ip FROM ips WHERE ip IS NOT NULL AND ip<>'' AND mac IS NOT NULL AND mac<>''");
  while ($ip = $stmt->fetchColumn()) {
    $ip = (string)$ip;
    if ($this->ipamAddressInPool($ip, $pool))
      $reservations[$ip] = true;
  }

  $occupied = $leases + $reservations;
  $used = count($occupied);
  $available = max(0, $pool['total'] - $used);
  return array(
    'start' => $pool['start'],
    'end' => $pool['finish'],
    'total' => $pool['total'],
    'occupied' => $used,
    'available' => $available,
    'utilization_percent' => $pool['total'] === 0 ? 0 : round($used * 100 / $pool['total'], 1),
    'active_leases' => count($leases),
    'fixed_reservations' => count($reservations)
  );
}

public function ipamAddressInPool(string $ip, array $pool): bool {
  if (!str_starts_with($ip, $pool['prefix'] . '.'))
    return false;
  $octet = substr($ip, strlen($pool['prefix']) + 1);
  return ctype_digit($octet) && (int)$octet >= $pool['begin'] && (int)$octet <= $pool['end'];
}

public function ipamLatestObservations(): array {
  $observations = array();
  $stmt = $this->db()->query("
    SELECT current.ip, current.`hardware-ethernet` AS mac, current.`client-hostname` AS name,
      current.ends, current.last_seen,
      CASE WHEN current.active=1 AND current.ends>CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS lease_active
    FROM leases current
    INNER JOIN (
      SELECT `hardware-ethernet` AS mac, MAX(last_seen) AS last_seen
      FROM leases
      GROUP BY `hardware-ethernet`
    ) latest ON latest.mac=current.`hardware-ethernet` AND latest.last_seen=current.last_seen
    ORDER BY current.last_seen DESC, current.active DESC
  ");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mac = $this->ipamStoredMac($row['mac'] ?? '');
    if ($mac === '' || isset($observations[$mac]))
      continue;
    $observations[$mac] = array(
      'ip' => (string)($row['ip'] ?? ''),
      'name' => (string)($row['name'] ?? ''),
      'status' => '',
      'last_seen' => $row['last_seen'] ?? null,
      'lease_expires' => $row['ends'] ?? null,
      'lease_active' => (int)($row['lease_active'] ?? 0)
    );
  }

  $stmt = $this->db()->query("
    SELECT s.ip, s.mac, s.status, s.date_begin, s.date_end
    FROM stats s
    INNER JOIN (
      SELECT MAX(id) AS id
      FROM stats
      WHERE mac IS NOT NULL AND mac<>''
      GROUP BY LOWER(mac)
    ) latest ON latest.id=s.id
  ");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mac = $this->ipamStoredMac($row['mac'] ?? '');
    if ($mac === '')
      continue;
    $seen = $row['date_end'] ?: $row['date_begin'];
    $this->ipamMergeObservation($observations, $mac, array(
      'ip' => (string)($row['ip'] ?? ''),
      'status' => (string)($row['status'] ?? ''),
      'last_seen' => $seen
    ));
  }

  $pingMacs = array();
  $stmt = $this->db()->query("SELECT ip, mac, status, date FROM ping WHERE mac IS NOT NULL AND mac<>'' ORDER BY date DESC");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mac = $this->ipamStoredMac($row['mac'] ?? '');
    if ($mac === '' || isset($pingMacs[$mac]))
      continue;
    $pingMacs[$mac] = true;
    $this->ipamMergeObservation($observations, $mac, array(
      'ip' => (string)($row['ip'] ?? ''),
      'status' => (string)($row['status'] ?? ''),
      'last_seen' => $row['date'] ?? null
    ));
  }
  return $observations;
}

public function ipamMergeObservation(array &$observations, string $mac, array $candidate): void {
  if (!isset($observations[$mac])) {
    $observations[$mac] = array_merge(array(
      'ip' => '', 'name' => '', 'status' => '', 'last_seen' => null,
      'lease_expires' => null, 'lease_active' => 0
    ), $candidate);
    return;
  }

  $currentTime = strtotime((string)($observations[$mac]['last_seen'] ?? '')) ?: 0;
  $candidateTime = strtotime((string)($candidate['last_seen'] ?? '')) ?: 0;
  if ($candidateTime >= $currentTime) {
    if (($candidate['ip'] ?? '') !== '')
      $observations[$mac]['ip'] = $candidate['ip'];
    $observations[$mac]['last_seen'] = $candidate['last_seen'] ?? $observations[$mac]['last_seen'];
  }
  if (($candidate['status'] ?? '') !== '')
    $observations[$mac]['status'] = $candidate['status'];
}

public function ipamApprovals(): array {
  $approvals = array();
  $stmt = $this->db()->query("SELECT mac, approved_at FROM device_approvals ORDER BY approved_at DESC");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mac = $this->ipamStoredMac($row['mac'] ?? '');
    if ($mac !== '')
      $approvals[$mac] = (string)$row['approved_at'];
  }
  return $approvals;
}

public function ipamManagedMacs(): array {
  $managed = array();
  $stmt = $this->db()->query("SELECT mac, ip FROM ips WHERE mac IS NOT NULL AND mac<>''");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!$this->config->dhcpNetwork->contains((string)($row['ip'] ?? '')))
      continue;
    $mac = $this->ipamStoredMac($row['mac']);
    if ($mac !== '')
      $managed[$mac] = true;
  }
  return $managed;
}

public function ipamDeviceRow(string $mac, array $observation, ?string $approvedAt): array {
  return array(
    'mac' => $mac,
    'ip' => (string)($observation['ip'] ?? ''),
    'name' => (string)($observation['name'] ?? ''),
    'vendor' => $this->getVendor($mac),
    'status' => (string)($observation['status'] ?? ''),
    'last_seen' => $observation['last_seen'] ?? null,
    'lease_expires' => $observation['lease_expires'] ?? null,
    'lease_active' => (int)($observation['lease_active'] ?? 0),
    'approved_at' => $approvedAt
  );
}

public function approveDevice(string $mac): array {
  $stmt = $this->db()->prepare("
    INSERT INTO device_approvals (mac) VALUES (:mac)
    ON CONFLICT(mac) DO NOTHING
  ");
  $stmt->execute(array('mac' => $mac));
  $read = $this->db()->prepare("SELECT approved_at FROM device_approvals WHERE mac=:mac");
  $read->execute(array('mac' => $mac));
  return array('mac' => $mac, 'approved' => true, 'approved_at' => $read->fetchColumn());
}

public function unapproveDevice(string $mac): array {
  $stmt = $this->db()->prepare("DELETE FROM device_approvals WHERE mac=:mac");
  $stmt->execute(array('mac' => $mac));
  return array('mac' => $mac, 'approved' => false);
}

public function ipamStoredMac($value): string {
  try {
    return $this->normalizeDhcpMac($value, true);
  } catch (InvalidArgumentException $e) {
    return '';
  }
}
}
