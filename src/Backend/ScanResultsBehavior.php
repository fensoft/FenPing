<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use PDO;

trait ScanResultsBehavior
{
public function scanMetadataLatest(string $ip): ?array {
  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
           progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count,
           snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : $this->scanNormalizeMetadata($metadata);
}

public function scanMetadataBestResult(string $ip, ?string $mode = null): ?array {
  if ($mode !== null && !$this->scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $modeWhere = $mode === null ? '' : ' AND mode=:mode';
  $order = $mode === null
    ? "CASE mode WHEN 'deep' THEN 0 ELSE 1 END, id DESC"
    : 'id DESC';
  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
           progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count,
           snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND state='complete'
      AND snapshot_id IS NOT NULL
      $modeWhere
    ORDER BY $order
    LIMIT 1
  ");
  $params = array('ip' => $ip);
  if ($mode !== null)
    $params['mode'] = $mode;
  $stmt->execute($params);
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : $this->scanNormalizeMetadata($metadata);
}

public function scanMetadataPreviousResult(string $ip, string $mode, int $beforeId): ?array {
  if (!$this->scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
           progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count,
           snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND mode=:mode
      AND id<:before_id
      AND state='complete'
      AND snapshot_id IS NOT NULL
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode, 'before_id' => $beforeId));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : $this->scanNormalizeMetadata($metadata);
}

public function scanMetadataById(string $ip, int $id): ?array {
  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
           progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count,
           snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip AND id=:id
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'id' => $id));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : $this->scanNormalizeMetadata($metadata);
}

public function scanMergePartialWithDeep(array $partial, array $deep, array $deepMetadata): array {
  $merged = $deep;
  $partialMode = (string)($partial['metadata']['mode'] ?? 'lightweight');

  foreach (array('ip', 'args', 'started', 'status') as $field) {
    if (($partial[$field] ?? '') !== '')
      $merged[$field] = $partial[$field];
  }
  if (($partial['duration'] ?? null) !== null)
    $merged['duration'] = $partial['duration'];
  if (($partial['uptime'] ?? '') !== '')
    $merged['uptime'] = $partial['uptime'];

  $merged['addresses'] = $this->scanMergeResultItems(
    $deep['addresses'] ?? array(),
    $partial['addresses'] ?? array(),
    fn($item) => ($item['type'] ?? '') . '|' . ($item['addr'] ?? ''),
    $partialMode
  );
  $merged['hostnames'] = $this->scanMergeResultItems(
    $deep['hostnames'] ?? array(),
    $partial['hostnames'] ?? array(),
    fn($item) => ($item['type'] ?? '') . '|' . ($item['name'] ?? ''),
    $partialMode
  );
  $merged['ports'] = $this->scanMergePorts($deep['ports'] ?? array(), $partial['ports'] ?? array(), $partialMode);
  usort($merged['ports'], function ($left, $right) {
    $portOrder = (int)($left['port'] ?? 0) <=> (int)($right['port'] ?? 0);
    return $portOrder !== 0 ? $portOrder : strcmp((string)($left['protocol'] ?? ''), (string)($right['protocol'] ?? ''));
  });

  $partialOs = $partial['os'] ?? array();
  $merged['os'] = $this->scanMarkResultSource(count($partialOs) !== 0 ? $partialOs : ($deep['os'] ?? array()), count($partialOs) !== 0 ? $partialMode : 'deep');
  $merged['os_matches'] = count($partial['os_matches'] ?? array()) !== 0 ? $partial['os_matches'] : ($deep['os_matches'] ?? array());
  $merged['port_scope'] = $partial['port_scope'] ?? array();
  $merged['extra_ports'] = $partial['extra_ports'] ?? array();
  $merged['scripts'] = count($partial['scripts'] ?? array()) !== 0 ? $partial['scripts'] : ($deep['scripts'] ?? array());
  $merged['trace'] = count($partial['trace'] ?? array()) !== 0 ? $partial['trace'] : ($deep['trace'] ?? array());
  $merged['ports_count'] = count($merged['ports']);
  $merged['metadata'] = $partial['metadata'] ?? null;
  $merged['xml'] = $partial['xml'] ?? null;
  $merged['merged'] = true;
  $merged['merged_with'] = $deepMetadata;
  return $merged;
}

public function scanMergeResultItems(array $base, array $overlay, callable $key, string $overlaySource): array {
  $items = array();
  foreach ($this->scanMarkResultSource($base, 'deep') as $item)
    $items[$key($item)] = $item;
  foreach ($this->scanMarkResultSource($overlay, $overlaySource) as $item)
    $items[$key($item)] = $item;
  return array_values($items);
}

public function scanMergePorts(array $deep, array $partial, string $partialSource): array {
  $items = array();
  foreach ($this->scanMarkResultSource($deep, 'deep') as $item) {
    $key = ($item['protocol'] ?? '') . '|' . ($item['port'] ?? '');
    $items[$key] = $item;
  }
  foreach ($this->scanMarkResultSource($partial, $partialSource) as $item) {
    $key = ($item['protocol'] ?? '') . '|' . ($item['port'] ?? '');
    $items[$key] = $this->scanMergePortKnowledge($items[$key] ?? null, $item);
  }
  return array_values($items);
}

public function scanMarkResultSource(array $items, string $source): array {
  return array_map(function ($item) use ($source) {
    $item['source'] = $source;
    return $item;
  }, $items);
}

public function scanMetadataHistory(string $ip, int $limit = 30): array {
  $limit = max(1, min(100, $limit));
  $days = self::SCAN_HISTORY_DAYS;
  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
           progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count,
           snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND snapshot_id IS NOT NULL
      AND result_changed=1
      AND (
        COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) >= datetime('now', '-$days days')
        OR id IN (
          SELECT latest_id
          FROM (
            SELECT MAX(id) AS latest_id
            FROM scans
            WHERE ip=:latest_ip AND state='complete' AND result_changed=1
            GROUP BY mode
          ) latest_results
        )
      )
    ORDER BY id DESC
    LIMIT $limit
  ");
  $stmt->execute(array('ip' => $ip, 'latest_ip' => $ip));

  $history = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metadata = $this->scanNormalizeMetadata($row);
    if ($this->scanMetadataXmlUsable($metadata))
      $history[] = $metadata;
  }
  return $history;
}

public function scanMetadataForIp(string $ip, int $limit = 50): array {
  $limit = max(1, min(100, $limit));
  $days = self::SCAN_HISTORY_DAYS;
  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
           progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count,
           snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND (state<>'complete' OR result_changed=1)
      AND (
        COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) >= datetime('now', '-$days days')
        OR id IN (
          SELECT latest_id
          FROM (
            SELECT MAX(id) AS latest_id
            FROM scans
            WHERE ip=:latest_ip AND state='complete' AND result_changed=1
            GROUP BY mode
          ) latest_results
        )
      )
    ORDER BY id DESC
    LIMIT $limit
  ");
  $stmt->execute(array('ip' => $ip, 'latest_ip' => $ip));

  $scans = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metadata = $this->scanNormalizeMetadata($row);
    $metadata['xml_usable'] = $this->scanMetadataXmlUsable($metadata);
    $metadata['xml_url'] = $metadata['xml_usable'] ? $this->scanXmlUrl($metadata['ip'], $metadata['id']) : null;
    $scans[] = $metadata;
  }
  return $scans;
}

public function scanMetadataQueue(int $limit = 100): array {
  $limit = max(1, min(200, $limit));
  $stmt = $this->db()->prepare("
    SELECT
      s.id,
      s.ip,
      s.mode,
      s.state,
      s.network,
      s.request_source,
      s.queued_at,
      s.progress_percent,
      s.progress_phase,
      s.progress_updated_at,
      s.cancel_requested_at,
      s.status,
      s.date_begin,
      s.date_end,
      s.duration,
      s.ports_count,
      s.snapshot_id,
      s.result_changed,
      s.error,
      i.id AS host_id,
      COALESCE(i.name, '') AS name,
      COALESCE(i.mac, '') AS mac,
      COALESCE(i.important, 0) AS important
    FROM scans s
    LEFT JOIN ips i ON i.ip=s.ip
    ORDER BY
      CASE s.state WHEN 'running' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,
      COALESCE(s.date_end, s.date_begin) DESC,
      s.id DESC
    LIMIT $limit
  ");
  $stmt->execute();

  $queue = array();
  $policyState = $this->scanQueuePolicyState();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metadata = $this->scanNormalizeMetadata($row, $policyState['annotations']);
    $metadata['host_id'] = $row['host_id'] === null ? null : (int)$row['host_id'];
    $metadata['name'] = $row['name'] ?? '';
    $metadata['mac'] = strtolower((string)($row['mac'] ?? ''));
    $metadata['important'] = (int)($row['important'] ?? 0);
    $metadata['xml_usable'] = $this->scanMetadataXmlUsable($metadata);
    $metadata['xml_url'] = $metadata['xml_usable'] ? $this->scanXmlUrl($metadata['ip'], $metadata['id']) : null;
    $queue[] = $metadata;
  }

  return $queue;
}

public function scanMetadataLatestByIp(): array {
  $stmt = $this->db()->prepare("
    SELECT s.id, s.ip, s.mode, s.state, s.network, s.request_source, s.queued_at,
           s.progress_percent, s.progress_phase, s.progress_updated_at, s.cancel_requested_at,
           s.status, s.date_begin, s.date_end, s.duration, s.ports_count, s.snapshot_id, s.result_changed, s.error
    FROM scans s
    INNER JOIN (
      SELECT ip, MAX(id) id
      FROM scans
      GROUP BY ip
    ) latest ON latest.id=s.id
  ");
  $stmt->execute();

  $scans = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $scans[$row['ip']] = $this->scanNormalizeMetadata($row);
  return $scans;
}

public function scanMetadataLatestUsableByIp(): array {
  $stmt = $this->db()->query("
    SELECT
      s.id,
      s.ip,
      s.mode,
      s.state,
      s.network,
      s.request_source,
      s.queued_at,
      s.progress_percent,
      s.progress_phase,
      s.progress_updated_at,
      s.cancel_requested_at,
      s.status,
      s.date_begin,
      s.date_end,
      s.duration,
      s.ports_count,
      s.snapshot_id,
      s.result_changed,
      s.error,
      i.id AS host_id,
      COALESCE(NULLIF(i.name, ''), NULLIF((
        SELECT l.`client-hostname`
        FROM leases l
        WHERE l.ip=s.ip
        ORDER BY l.active DESC, l.last_seen DESC
        LIMIT 1
      ), ''), '') AS name,
      COALESCE(NULLIF(i.mac, ''), NULLIF((
        SELECT known.mac
        FROM stats known
        WHERE known.ip=s.ip AND known.mac IS NOT NULL AND known.mac<>''
        ORDER BY known.id DESC
        LIMIT 1
      ), ''), NULLIF((
        SELECT l.`hardware-ethernet`
        FROM leases l
        WHERE l.ip=s.ip
        ORDER BY l.active DESC, l.last_seen DESC
        LIMIT 1
      ), ''), '') AS mac
    FROM scans s
    INNER JOIN (
      SELECT ip, MAX(id) AS id
      FROM scans
      WHERE state='complete' AND snapshot_id IS NOT NULL
      GROUP BY ip
    ) latest ON latest.id=s.id
    LEFT JOIN ips i ON i.ip=s.ip
    ORDER BY ipv4_num(s.ip), s.ip
  ");

  $results = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metadata = $this->scanNormalizeMetadata($row);
    $metadata['host_id'] = $row['host_id'] === null ? null : (int)$row['host_id'];
    $metadata['name'] = (string)($row['name'] ?? '');
    $metadata['mac'] = strtolower((string)($row['mac'] ?? ''));
    $results[] = $metadata;
  }
  return $results;
}

}
