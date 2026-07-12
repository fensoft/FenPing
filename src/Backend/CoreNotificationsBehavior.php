<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use RuntimeException;
use Throwable;

trait CoreNotificationsBehavior
{
public function get_stats() {
  $stmt = $this->getDb()->prepare("select distinct ip from stats where ip is not null and ip<>'' and date_end > datetime('now', '-7 days')");
  $stmt->execute();
  $arr = array();
  while ($i = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $history = $this->get_history($i["ip"]);
    $summary = $this->get_history_summary($history);
    if (!$summary["stable"])
      $arr[$i["ip"]] = $summary;
  }
  return $arr;
}

public function temps($val) {
  if ($val < 60)
    return $val . " s";
  if ($val < 60 * 60)
    return intval($val / 60) . " m";
  if ($val < 60 * 60 * 24)
    return intval($val / (60 * 60)) . " h";
  return intval($val / (60 * 60 * 24)) . " d";
}

public function get_history_response($ip) {
  $rows = $this->get_history($ip);
  return array(
    "summary" => $this->get_history_summary($rows),
    "rows" => $rows
  );
}

public function get_port_notify($hours = 24) {
  $hours = max(1, min(168, (int)$hours));
  $stmt = $this->getDb()->prepare("
    SELECT
      c.id,
      c.scan_id,
      c.ip,
      c.mode,
      c.change_type,
      c.protocol,
      c.port,
      c.previous_service,
      c.previous_version,
      c.current_service,
      c.current_version,
      c.created_at,
      unixepoch(c.created_at) AS created,
      COALESCE(NULLIF((
        SELECT i.mac FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1
      ), ''), NULLIF((
        SELECT s.mac FROM stats s WHERE s.ip=c.ip AND s.mac IS NOT NULL AND s.mac<>'' ORDER BY s.id DESC LIMIT 1
      ), ''), '') AS mac,
      COALESCE(NULLIF((
        SELECT i.name FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1
      ), ''), NULLIF((
        SELECT l.`client-hostname` FROM leases l WHERE l.ip=c.ip ORDER BY l.active DESC, l.last_seen DESC LIMIT 1
      ), ''), '') AS name,
      COALESCE((
        SELECT i.important FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1
      ), 0) AS important
    FROM scan_port_changes c
    WHERE c.created_at >= datetime('now', '-$hours hours')
    ORDER BY c.created_at DESC, c.id DESC
  ");
  $stmt->execute();

  $changes = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['id'] = (int)$row['id'];
    $row['scan_id'] = (int)$row['scan_id'];
    $row['port'] = (int)$row['port'];
    $row['created'] = (int)$row['created'];
    $row['important'] = (int)$row['important'];
    $row['vendor'] = $this->getVendor((string)($row['mac'] ?? ''));
    $changes[] = $row;
  }
  return $changes;
}

public function get_notify($hours = 24) {
  $hours = max(1, min(168, (int)$hours));
  $stmt = $this->getDb()->prepare("
    SELECT
      s.id,
      s.ip,
      COALESCE(NULLIF(s.mac, ''), (
        SELECT known.mac
        FROM stats known
        WHERE known.ip=s.ip AND known.mac IS NOT NULL AND known.mac<>''
        ORDER BY known.id DESC
        LIMIT 1
      ), (
        SELECT i.mac
        FROM ips i
        WHERE i.ip=s.ip AND i.mac IS NOT NULL AND i.mac<>''
        ORDER BY i.id DESC
        LIMIT 1
      ), '') AS mac,
      s.status,
      s.date_begin,
      s.date_end,
      unixepoch(s.date_begin) AS `begin`,
      unixepoch(CASE
        WHEN s.id=(SELECT MAX(latest.id) FROM stats latest WHERE latest.ip=s.ip) THEN CURRENT_TIMESTAMP
        ELSE COALESCE(s.date_end, CURRENT_TIMESTAMP)
      END) AS `end`,
      MAX(0, unixepoch(CASE
        WHEN s.id=(SELECT MAX(latest.id) FROM stats latest WHERE latest.ip=s.ip) THEN CURRENT_TIMESTAMP
        ELSE COALESCE(s.date_end, CURRENT_TIMESTAMP)
      END) - unixepoch(s.date_begin)) AS duration,
      CASE WHEN s.id=(SELECT MAX(latest.id) FROM stats latest WHERE latest.ip=s.ip) THEN 1 ELSE 0 END AS current,
      (SELECT prev.status FROM stats prev WHERE prev.ip=s.ip AND prev.id<s.id ORDER BY prev.id DESC LIMIT 1) AS previous_status,
      COALESCE(NULLIF((
        SELECT i.name
        FROM ips i
        WHERE i.ip=s.ip
        ORDER BY i.id DESC
        LIMIT 1
      ), ''), NULLIF((
        SELECT i.name
        FROM ips i
        WHERE LOWER(i.mac)=LOWER(s.mac)
        ORDER BY i.id DESC
        LIMIT 1
      ), ''), NULLIF((
        SELECT l.`client-hostname`
        FROM leases l
        WHERE l.ip=s.ip
        ORDER BY l.active DESC, l.last_seen DESC
        LIMIT 1
      ), ''), NULLIF((
        SELECT l.`client-hostname`
        FROM leases l
        WHERE LOWER(l.`hardware-ethernet`)=LOWER(s.mac)
        ORDER BY l.active DESC, l.last_seen DESC
        LIMIT 1
      ), ''), '') AS name,
      '' AS vendor,
      COALESCE((
        SELECT i.important
        FROM ips i
        WHERE i.ip=s.ip
        ORDER BY i.id DESC
        LIMIT 1
      ), (
        SELECT i.important
        FROM ips i
        WHERE LOWER(i.mac)=LOWER(s.mac)
        ORDER BY i.id DESC
        LIMIT 1
      ), 0) AS important
    FROM stats s
    WHERE s.ip IS NOT NULL
      AND s.ip<>''
      AND s.date_begin >= datetime('now', '-$hours hours')
      AND EXISTS (SELECT 1 FROM stats prev_exists WHERE prev_exists.ip=s.ip AND prev_exists.id<s.id)
    ORDER BY s.date_begin DESC, s.id DESC
  ");
  $stmt->execute();

  $changes = array();
  $statusCounts = array();
  $hosts = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = (string)($row["status"] ?? "");
    $ip = (string)($row["ip"] ?? "");
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    if ($ip !== "")
      $hosts[$ip] = true;

    $row["id"] = (int)$row["id"];
    $row["duration"] = (int)$row["duration"];
    $row["current"] = (int)$row["current"];
    $row["important"] = (int)$row["important"];
    $row["vendor"] = $this->getVendor((string)($row["mac"] ?? ""));
    $row["previous_status"] = ($row["previous_status"] ?? "") === "" ? null : $row["previous_status"];
    $changes[] = $row;
  }

  $portChanges = $this->get_port_notify($hours);
  $portChangeCounts = array();
  foreach ($portChanges as $change) {
    $type = (string)($change['change_type'] ?? '');
    $portChangeCounts[$type] = ($portChangeCounts[$type] ?? 0) + 1;
    $ip = (string)($change['ip'] ?? '');
    if ($ip !== '')
      $hosts[$ip] = true;
  }

  return array(
    "network" => $this->config->network,
    "since" => date("Y-m-d H:i:s", time() - $hours * 60 * 60),
    "hours" => $hours,
    "summary" => array(
      "total" => count($changes) + count($portChanges),
      "status_total" => count($changes),
      "port_total" => count($portChanges),
      "hosts" => count($hosts),
      "status_counts" => $statusCounts,
      "port_change_counts" => $portChangeCounts
    ),
    "changes" => $changes,
    "port_changes" => $portChanges
  );
}
}
