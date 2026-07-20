<?php

declare(strict_types=1);

namespace FenPing\Status;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Ipam\IpConflictService;
use FenPing\Vendor\VendorLookup;
use PDO;

final readonly class NotificationQueryRepository
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private StatusHistoryService $history,
        private VendorLookup $vendors,
        private IpConflictService $conflicts,
    ) {
    }

public function get_stats() {
  $stmt = $this->database->connection()->prepare("select distinct ip from stats where ip is not null and ip<>'' and date_end > datetime('now', '-7 days')");
  $stmt->execute();
  $arr = array();
  while ($i = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $history = $this->history->history($i["ip"]);
    $summary = $this->history->summary($history);
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
  $rows = $this->history->history($ip);
  return array(
    "summary" => $this->history->summary($rows),
    "rows" => $rows
  );
}

public function get_port_notify($hours = 24) {
  $hours = max(1, min(720, (int)$hours));
  $stmt = $this->database->connection()->prepare("
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
    $row['vendor'] = $this->vendors->forMac((string)($row['mac'] ?? ''));
    $changes[] = $row;
  }
  return $changes;
}

public function get_anomaly_notify($hours = 24, ?array $portChanges = null) {
  $hours = max(1, min(720, (int)$hours));
  $stmt = $this->database->connection()->prepare("
    SELECT
      e.*,
      unixepoch(e.occurred_at) AS occurred,
      COALESCE(NULLIF((SELECT i.name FROM ips i
        WHERE (e.mac IS NOT NULL AND LOWER(i.mac)=LOWER(e.mac)) OR i.ip=e.ip
        ORDER BY i.id DESC LIMIT 1), ''), '') AS name
    FROM network_anomaly_events e
    WHERE e.occurred_at >= datetime('now', '-$hours hours')
    ORDER BY e.occurred_at DESC, e.id DESC
  ");
  $stmt->execute();
  $changes = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $details = json_decode((string)($row['details_json'] ?? '{}'), true);
    $changes[] = array(
      'event_id' => 'anomaly:' . (int)$row['id'],
      'source' => 'network_anomaly',
      'source_id' => (int)$row['id'],
      'type' => (string)$row['anomaly_type'],
      'subtype' => (string)($row['subtype'] ?? ''),
      'event' => (string)$row['event_type'],
      'occurred_at' => (string)$row['occurred_at'],
      'occurred' => (int)$row['occurred'],
      'network' => (string)$row['network'],
      'ip' => (string)($row['ip'] ?? ''),
      'previous_ip' => (string)($row['previous_ip'] ?? ''),
      'mac' => (string)($row['mac'] ?? ''),
      'hostname' => (string)($row['hostname'] ?? ''),
      'name' => (string)($row['name'] ?? ''),
      'vendor' => (string)($row['vendor'] ?? ''),
      'important' => (int)$row['important'],
      'details' => is_array($details) ? $details : array()
    );
  }

  foreach ($portChanges ?? $this->get_port_notify($hours) as $change) {
    if ((string)($change['change_type'] ?? '') !== 'appeared') continue;
    $changes[] = array(
      'event_id' => 'port:' . (int)$change['id'],
      'source' => 'scan_port_change',
      'source_id' => (int)$change['id'],
      'type' => 'open_port',
      'subtype' => 'port_appeared',
      'event' => 'detected',
      'occurred_at' => (string)$change['created_at'],
      'occurred' => (int)$change['created'],
      'network' => '',
      'ip' => (string)$change['ip'],
      'previous_ip' => '',
      'mac' => (string)($change['mac'] ?? ''),
      'hostname' => '',
      'name' => (string)($change['name'] ?? ''),
      'vendor' => (string)($change['vendor'] ?? ''),
      'important' => (int)($change['important'] ?? 0),
      'details' => array(
        'scan_id' => (int)$change['scan_id'], 'mode' => (string)$change['mode'],
        'protocol' => (string)$change['protocol'], 'port' => (int)$change['port'],
        'service' => (string)($change['current_service'] ?? ''),
        'version' => (string)($change['current_version'] ?? '')
      )
    );
  }
  usort($changes, static fn(array $a, array $b): int =>
    ($b['occurred'] <=> $a['occurred']) ?: strcmp($b['event_id'], $a['event_id'])
  );
  return $changes;
}

public function get_notify($hours = 24, array $delivery = array()) {
  $hours = max(1, min(720, (int)$hours));
  $stmt = $this->database->connection()->prepare("
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
    $row["vendor"] = $this->vendors->forMac((string)($row["mac"] ?? ""));
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

  $conflictChanges = $this->conflicts->recent($hours);
  $conflictCounts = array();
  foreach ($conflictChanges as $change) {
    $type = (string)($change['type'] ?? '');
    $conflictCounts[$type] = ($conflictCounts[$type] ?? 0) + 1;
    $ip = (string)($change['ip'] ?? '');
    if ($ip !== '')
      $hosts[$ip] = true;
  }

  $anomalyChanges = $this->get_anomaly_notify($hours, $portChanges);
  $anomalyCounts = array();
  $storedAnomalyCount = 0;
  foreach ($anomalyChanges as $change) {
    $type = (string)($change['type'] ?? '');
    $anomalyCounts[$type] = ($anomalyCounts[$type] ?? 0) + 1;
    $ip = (string)($change['ip'] ?? '');
    if ($ip !== '') $hosts[$ip] = true;
    if (($change['source'] ?? '') === 'network_anomaly') $storedAnomalyCount++;
  }

  return array(
    "network" => $this->config->network,
    "since" => date("Y-m-d H:i:s", time() - $hours * 60 * 60),
    "hours" => $hours,
    "summary" => array(
      "total" => count($changes) + count($portChanges) + count($conflictChanges) + $storedAnomalyCount,
      "status_total" => count($changes),
      "port_total" => count($portChanges),
      "conflict_total" => count($conflictChanges),
      "hosts" => count($hosts),
      "status_counts" => $statusCounts,
      "port_change_counts" => $portChangeCounts,
      "conflict_counts" => $conflictCounts,
      "anomaly_total" => count($anomalyChanges),
      "anomaly_counts" => $anomalyCounts
    ),
    "changes" => $changes,
    "port_changes" => $portChanges,
    "conflict_changes" => $conflictChanges,
    "anomaly_changes" => $anomalyChanges,
    "delivery" => $delivery
  );
}
}
