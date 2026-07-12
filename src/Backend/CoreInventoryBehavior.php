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

trait CoreInventoryBehavior
{
public function getVendor($mac) {
  static $cache = array();
  static $databaseAvailable = true;

  $normalized = $this->ieeeOuiNormalizeMac((string)$mac);
  if ($normalized === '')
    return '';
  if (array_key_exists($normalized, $cache))
    return $cache[$normalized];

  $firstOctet = hexdec(substr($normalized, 0, 2));
  if (($firstOctet & 0x02) !== 0)
    return $cache[$normalized] = '';

  if ($databaseAvailable) {
    try {
      $vendor = $this->ieeeOuiDatabaseVendor($this->getDb(), $normalized);
      if ($vendor !== null)
        return $cache[$normalized] = $vendor;
    } catch (Throwable $e) {
      $databaseAvailable = false;
    }
  }
  return $cache[$normalized] = $this->ieeeOuiVendor($normalized);
}

public function getDb() {
  return $this->db();
}

public function normalizeCategoryIp($ip) {
  $ip = trim((string)$ip);
  if ($ip != "" && strpos($ip, ".") === false)
    return $this->config->network . "." . $ip;
  return $ip;
}

public function normalizeInventoryRow($data, $ip = null) {
  $defaults = array(
    "id" => null,
    "name" => "",
    "ip" => $ip,
    "mac" => "",
    "status" => "",
    "date" => null,
    "important" => 0,
    "web" => null,
    "repeater" => null
  );
  $row = array_merge($defaults, $data);
  $row["name"] = $row["name"] === null ? "" : $row["name"];
  $row["ip"] = $row["ip"] === null ? "" : $row["ip"];
  $row["mac"] = $row["mac"] === null ? "" : $row["mac"];
  $row["important"] = $row["important"] === null ? 0 : $row["important"];
  return $row;
}

public function getInventory() {
  $db = $this->getDb();
  $latestScans = $this->getLatestScans();
  $approvedDevices = array();
  $approvalStmt = $db->query("SELECT mac FROM device_approvals");
  while ($approvedMac = $approvalStmt->fetchColumn()) {
    $approvedMac = strtolower(trim((string)$approvedMac));
    if ($approvedMac !== '')
      $approvedDevices[$approvedMac] = true;
  }

  $repeater = array();
  $stmt = $db->prepare("select mac, ip, name from ips where repeater=1");
  $stmt->execute();
  while ($data = $stmt->fetch()) {
    $mac = strtolower((string)($data[0] ?? ""));
    if ($mac != "")
      $repeater[$mac] = array("ip" => $data[1], "name" => $data[2]);
  }

  $ips = array();
  #cas normal
  $stmt = $db->prepare("select id, name, i.ip, i.mac, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and i.ip=p.ip and (p.mac=i.mac or p.mac='')");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC))
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  #derri�re un autre routeur
  $stmt = $db->prepare("select id, name, i.ip, p.mac, i.mac as mac_i, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where i.ip!='' and p.mac!='' and i.ip=p.ip and p.mac!=i.mac");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $mac = (string)($data["mac"] ?? "");
    if (isset($repeater[$mac]))
      $data["via"] = "via " . $repeater[$mac]["name"];
    $data["mac"] = $data["mac_i"];
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #mauvaise ip
  $stmt = $db->prepare("select id, name, p.ip, i.ip as ip_should, p.mac, status, date, i.important, i.web, i.repeater from ips i left outer join ping p on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.ip != i.ip and status = 'Up' and repeater is null");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #en db mais ne pingent pas
  $stmt = $db->prepare("select id, name, p.ip, p.mac, status, date, i.important, i.web, i.repeater from ping p left outer join ips i on p.ip=i.ip or lower(i.mac)=lower(p.mac) where p.status != 'Down' and id is null");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (($data["ip"] ?? "") != "")
      $ips[$data["ip"]] = $data;
  }
  #dans les leases avec des new mac
  $macs = array();
  foreach ($ips as $key => $data) {
    $mac = strtoupper((string)($data["mac"] ?? ""));
    if ($mac != "")
      array_push($macs, $mac);
  }
  $stmt = $db->prepare("select NULL as id, `client-hostname` as name, ip, `hardware-ethernet` as mac, 'Down' as status, last_seen as date, 0 as important from leases where active=1 and ends > datetime('now', '-7 days')");
  $stmt->execute();
  while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $ip = $data["ip"] ?? "";
    if ($ip == "")
      continue;
    $mac = strtoupper((string)($data["mac"] ?? ""));
    if (!in_array($mac, $macs)) {
      if (!isset($ips[$ip]))
        $ips[$ip] = $data;
    }
    if (isset($ips[$ip]) && ($ips[$ip]["name"] ?? "") == "" && ($data["name"] ?? "") != "")
      $ips[$ip]["name"] = $data["name"];
  }
  #toujours up
  if ($this->config->applianceIp != "") {
    if (!isset($ips[$this->config->applianceIp]))
      $ips[$this->config->applianceIp] = array("ip" => $this->config->applianceIp);
    $ips[$this->config->applianceIp]["status"] = "Up";
  }
  $sorted_ips = array();
  foreach ($ips as $key => $data) {
    $data = $this->normalizeInventoryRow($data, $key);
    $ipLong = ip2long($data["ip"]);
    if ($ipLong === false)
      continue;
    $sorted_ips[$ipLong] = $data;
  }
  ksort($sorted_ips);
  $old_ip = null;
  $old_network = null;
  $res = array();
  $stats = $this->get_stats();
  foreach ($sorted_ips as $key => $data) {
    $ip = $data["ip"];
    $mac = trim((string)$data["mac"]);
    if ($mac == "")
      continue;
    $normalizedMac = strtolower($mac);
    $managed = isset($data['id']) && $data['id'] !== null;
    $data['approved'] = $managed || isset($approvedDevices[$normalizedMac]) ? 1 : 0;
    $data['is_new'] = !$managed && !isset($approvedDevices[$normalizedMac]) ? 1 : 0;
    $categoryNetwork = substr($ip, 0, strrpos($ip, '.'));
    if ($categoryNetwork !== $old_network) {
      $old_network = $categoryNetwork;
      $old_ip = $categoryNetwork . '.0';
    }
    $stmt2 = $db->prepare("
      select ip_begin, ip_begin_full, type from (
        select
          ip_begin,
          case when ip_begin like '%.%' then ip_begin else :category_network || '.' || ip_begin end as ip_begin_full,
          type
        from `range`
      ) ranges
      where ipv4_num(:ip_begin) < ipv4_num(ip_begin_full)
        and ipv4_num(:ip_end) >= ipv4_num(ip_begin_full)
      order by ipv4_num(ip_begin_full) desc
      limit 1
    ");
    $stmt2->execute(array("category_network" => $categoryNetwork, "ip_begin" => $old_ip, "ip_end" => $ip));
    $data2 = $stmt2->fetch();
    if ($data2 != false) {
      $old_ip = $ip;
      $data["category"] = $data2["type"];
      $data["category_ip"] = $data2["ip_begin"];
    }
    $data["vendor"] = "";
    $data["vendor"] = $this->getVendor($mac);
    if ($ip != "" && isset($stats[$ip])) {
      $data["stability"] = $stats[$ip];
      $data["stats"] = $stats[$ip]["label"];
      $data["stats2"] = $stats[$ip]["transitions"];
    }
    if ($ip != "" && !empty($latestScans[$ip]["result_available"])) {
      $data["xml"] = $ip;
    }
    if ($ip != "" && isset($latestScans[$ip])) {
      $data["scan"] = $latestScans[$ip];
    }
    array_push($res, $data);
  }
  return $res;
}

public function getLatestScans() {
  $stmt = $this->getDb()->prepare("
    SELECT
      s.id,
      s.ip,
      s.mode,
      s.state,
      s.status,
      s.date_begin,
      s.date_end,
      s.duration,
      s.ports_count,
      s.snapshot_id,
      s.result_changed,
      s.error,
      EXISTS(
        SELECT 1
        FROM scans result
        WHERE result.ip=s.ip
          AND result.state='complete'
          AND result.snapshot_id IS NOT NULL
      ) AS result_available
    FROM scans s
    INNER JOIN (
      SELECT ip, MAX(id) id
      FROM scans
      GROUP BY ip
    ) latest ON latest.id=s.id
  ");
  $stmt->execute();

  $scans = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row["id"] = (int)$row["id"];
    $row["duration"] = $row["duration"] === null ? null : (int)$row["duration"];
    $row["ports_count"] = (int)$row["ports_count"];
    $row["snapshot_id"] = $row["snapshot_id"] === null ? null : (int)$row["snapshot_id"];
    $row["result_changed"] = (int)$row["result_changed"];
    $row["result_available"] = (int)$row["result_available"];
    $row["xml"] = $row["result_available"] ? $this->scanXmlUrl($row["ip"]) : null;
    $scans[$row["ip"]] = $row;
  }
  return $scans;
}
}
