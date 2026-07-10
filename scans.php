<?php

const SCAN_XSL_FROM = 'file:///usr/bin/../share/nmap/';
const SCAN_XSL_LEGACY = '../res/xsl/';
const SCAN_XSL_TO = '/res/xsl/';
const SCAN_HISTORY_DAYS = 7;

function scanProfiles(): array {
  return array(
    array(
      'id' => 'lightweight',
      'name' => 'Lightweight',
      'description' => 'Fast check of the 100 most common TCP ports with basic service names.',
      'timeout_seconds' => 300
    ),
    array(
      'id' => 'standard',
      'name' => 'Standard',
      'description' => 'Top 1,000 TCP ports with service, OS, script, and traceroute detection.',
      'timeout_seconds' => 1800
    ),
    array(
      'id' => 'deep',
      'name' => 'Deep',
      'description' => 'All 65,535 TCP ports with service, OS, script, and traceroute detection.',
      'timeout_seconds' => 7200
    )
  );
}

function scanProfileIds(bool $includeLegacy = true): array {
  $ids = array_column(scanProfiles(), 'id');
  if ($includeLegacy)
    array_unshift($ids, 'quick');
  return $ids;
}

function scanProfileIsValid(string $profile, bool $includeLegacy = true): bool {
  return in_array($profile, scanProfileIds($includeLegacy), true);
}

function scanProfileRank(string $profile): int {
  return match ($profile) {
    'quick', 'lightweight' => 1,
    'standard' => 2,
    'deep' => 3,
    default => 0
  };
}

function scanProfileIsPartial(string $profile): bool {
  return scanProfileRank($profile) > 0 && $profile !== 'deep';
}

function scanProfileTimeout(string $profile): int {
  if ($profile === 'quick')
    $profile = 'lightweight';
  foreach (scanProfiles() as $definition) {
    if ($definition['id'] === $profile)
      return (int)$definition['timeout_seconds'];
  }
  throw new InvalidArgumentException('invalid scan profile');
}

function scanXmlUrl(string $ip, ?int $id = null): string {
  if ($id !== null)
    return '/api/scans/' . rawurlencode($ip) . '/' . $id . '.xml';
  return '/api/scans/' . rawurlencode($ip) . '.xml';
}

function scanReadXml(string $ip, ?array $metadata = null): ?string {
  if ($metadata === null)
    $metadata = scanMetadataBestResult($ip);
  if ($metadata === null)
    return null;

  $snapshotId = (int)($metadata['snapshot_id'] ?? 0);
  if ($snapshotId > 0) {
    $stmt = db()->prepare("SELECT xml FROM scan_snapshots WHERE id=:id AND ip=:ip LIMIT 1");
    $stmt->execute(array('id' => $snapshotId, 'ip' => $ip));
    $xml = $stmt->fetchColumn();
    return $xml === false ? null : scanNormalizeXml((string)$xml);
  }
  return null;
}

function scanNormalizeXml(string $xml): string {
  return str_replace(
    array('href="' . SCAN_XSL_LEGACY, 'href="' . SCAN_XSL_FROM),
    array('href="' . SCAN_XSL_TO, 'href="' . SCAN_XSL_TO),
    $xml
  );
}

function scanXmlHash(string $xml, string $ip = ''): string {
  $scan = scanParseXml($xml, array('ip' => $ip));
  $signature = array(
    'ip' => $scan['ip'] ?? $ip,
    'status' => $scan['status'] ?? '',
    'addresses' => $scan['addresses'] ?? array(),
    'hostnames' => $scan['hostnames'] ?? array(),
    'os' => $scan['os'] ?? array(),
    'ports' => array_map(fn($port) => array(
      'protocol' => $port['protocol'] ?? '',
      'port' => $port['port'] ?? '',
      'state' => $port['state'] ?? '',
      'service' => $port['service'] ?? '',
      'details' => $port['details'] ?? ''
    ), $scan['ports'] ?? array())
  );
  scanSortRecursive($signature);
  return hash('sha256', json_encode($signature, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function scanSortRecursive(&$value): void {
  if (!is_array($value))
    return;

  foreach ($value as &$item)
    scanSortRecursive($item);
  unset($item);

  if (scanArrayIsList($value)) {
    usort($value, function ($a, $b) {
      return strcmp(json_encode($a), json_encode($b));
    });
  } else {
    ksort($value);
  }
}

function scanArrayIsList(array $array): bool {
  $i = 0;
  foreach (array_keys($array) as $key) {
    if ($key !== $i++)
      return false;
  }
  return true;
}

function scanMetadataXmlUsable(array $metadata): bool {
  if (array_key_exists('xml_usable', $metadata))
    return (bool)$metadata['xml_usable'];
  return (int)($metadata['snapshot_id'] ?? 0) > 0;
}

function scanParseXml(string $xml, ?array $metadata = null): array {
  $nmap = scanFirstTagAttributes($xml, 'nmaprun');
  $host = scanFirstBlock($xml, 'host') ?? '';
  $status = scanFirstTagAttributes($host, 'status');
  $uptime = scanFirstTagAttributes($host, 'uptime');
  $finished = scanFirstTagAttributes($xml, 'finished');

  $ports = array_map('scanParsePortBlock', scanBlocks($host, 'port'));

  return array(
    'ip' => $metadata['ip'] ?? scanPrimaryIp($host),
    'args' => $nmap['args'] ?? '',
    'started' => $nmap['startstr'] ?? '',
    'status' => $status['state'] ?? '',
    'uptime' => $uptime['lastboot'] ?? '',
    'duration' => scanDuration($finished),
    'ports_count' => count($ports),
    'addresses' => scanParseTags($host, 'address', function ($attributes) {
      return array(
        'addr' => $attributes['addr'] ?? '',
        'type' => $attributes['addrtype'] ?? '',
        'vendor' => $attributes['vendor'] ?? ''
      );
    }),
    'hostnames' => scanParseTags($host, 'hostname', function ($attributes) {
      return array(
        'name' => $attributes['name'] ?? '',
        'type' => $attributes['type'] ?? ''
      );
    }),
    'os' => scanSelectOsMatches(scanParseTags($host, 'osmatch', function ($attributes) {
      return array(
        'name' => $attributes['name'] ?? '',
        'accuracy' => $attributes['accuracy'] ?? ''
      );
    })),
    'ports' => $ports,
    'metadata' => $metadata,
    'xml' => isset($metadata['ip'], $metadata['id']) ? scanXmlUrl($metadata['ip'], $metadata['id']) : null
  );
}

function scanSelectOsMatches(array $matches): array {
  $perfect = array_values(array_filter($matches, fn($match) => (int)($match['accuracy'] ?? 0) === 100));
  if (count($perfect) !== 0)
    return $perfect;
  if (count($matches) === 0)
    return array();

  usort($matches, function ($left, $right) {
    return (int)($right['accuracy'] ?? 0) <=> (int)($left['accuracy'] ?? 0);
  });
  return array($matches[0]);
}

function scanPrimaryIp(string $host): string {
  foreach (scanParseTags($host, 'address', fn($attributes) => $attributes) as $address) {
    if (($address['addrtype'] ?? '') === 'ipv4')
      return $address['addr'] ?? '';
  }
  return '';
}

function scanDuration(array $finished): ?int {
  if (!isset($finished['elapsed']) || !is_numeric($finished['elapsed']))
    return null;
  return (int)ceil((float)$finished['elapsed']);
}

function scanParsePortBlock(string $block): array {
  $port = scanFirstTagAttributes($block, 'port');
  $state = scanFirstTagAttributes($block, 'state');
  $service = scanFirstTagAttributes($block, 'service');
  $details = implode(' ', array_filter(array(
    $service['product'] ?? '',
    $service['version'] ?? '',
    $service['extrainfo'] ?? ''
  )));

  return array(
    'protocol' => $port['protocol'] ?? '',
    'port' => $port['portid'] ?? '',
    'state' => $state['state'] ?? '',
    'service' => $service['name'] ?? '',
    'details' => $details,
    'tunnel' => $service['tunnel'] ?? ''
  );
}

function scanParsePortScope(string $xml): array {
  $scope = array();
  $scanInfo = scanParseTags($xml, 'scaninfo', fn($attributes) => $attributes);

  foreach ($scanInfo as $info) {
    $protocol = strtolower(trim((string)($info['protocol'] ?? '')));
    $services = trim((string)($info['services'] ?? ''));
    if ($protocol === '' || $services === '')
      continue;

    foreach (explode(',', $services) as $item) {
      $item = trim($item);
      if ($item === '')
        continue;
      if (preg_match('/^(\d+)-(\d+)$/', $item, $matches)) {
        $from = max(0, (int)$matches[1]);
        $to = min(65535, (int)$matches[2]);
      } elseif (ctype_digit($item)) {
        $from = $to = min(65535, (int)$item);
      } else {
        continue;
      }
      if ($from <= $to)
        $scope[$protocol][] = array($from, $to);
    }
  }

  return $scope;
}

function scanParseTags(string $xml, string $tag, callable $map): array {
  $items = array();
  if (preg_match_all('/<' . preg_quote($tag, '/') . '\b([^>]*)\/?>/i', $xml, $matches)) {
    foreach ($matches[1] as $attributes)
      $items[] = $map(scanAttributes($attributes));
  }
  return $items;
}

function scanFirstTagAttributes(string $xml, string $tag): array {
  if (!preg_match('/<' . preg_quote($tag, '/') . '\b([^>]*)>/i', $xml, $matches))
    return array();
  return scanAttributes($matches[1]);
}

function scanFirstBlock(string $xml, string $tag): ?string {
  if (!preg_match('/<' . preg_quote($tag, '/') . '\b[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/is', $xml, $matches))
    return null;
  return $matches[0];
}

function scanBlocks(string $xml, string $tag): array {
  if (!preg_match_all('/<' . preg_quote($tag, '/') . '\b[^>]*>.*?<\/' . preg_quote($tag, '/') . '>/is', $xml, $matches))
    return array();
  return $matches[0];
}

function scanAttributes(string $attributes): array {
  $result = array();
  if (preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $attributes, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match)
      $result[$match[1]] = html_entity_decode($match[3], ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
  return $result;
}

function scanMetadataEnqueue(string $ip, string $mode): array {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new InvalidArgumentException('invalid scan ip');
  if (!scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $lockName = 'fenping-scan-' . hash('sha256', $ip);
  $lock = db()->prepare('SELECT GET_LOCK(:name, 5)');
  $lock->execute(array('name' => $lockName));
  if ((int)$lock->fetchColumn() !== 1)
    throw new RuntimeException("failed to lock scan queue for $ip");

  try {
    $stmt = db()->prepare("
      SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
      FROM scans
      WHERE ip=:ip AND state IN ('queued', 'running')
      ORDER BY CASE state WHEN 'running' THEN 0 ELSE 1 END, id DESC
    ");
    $stmt->execute(array('ip' => $ip));
    $activeJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requestedRank = scanProfileRank($mode);
    $covering = null;
    foreach ($activeJobs as $active) {
      if (scanProfileRank((string)$active['mode']) < $requestedRank)
        continue;
      if ($covering === null || scanProfileRank((string)$active['mode']) > scanProfileRank((string)$covering['mode']))
        $covering = $active;
    }
    if ($covering !== null)
      return array('metadata' => scanNormalizeMetadata($covering), 'created' => false);

    foreach ($activeJobs as $active) {
      if ($active['state'] !== 'queued' || scanProfileRank((string)$active['mode']) >= $requestedRank)
        continue;
      $update = db()->prepare("UPDATE scans SET mode=:mode WHERE id=:id AND state='queued'");
      $update->execute(array('mode' => $mode, 'id' => $active['id']));
      if ($update->rowCount() === 1) {
        $active['mode'] = $mode;
        return array('metadata' => scanNormalizeMetadata($active), 'created' => false);
      }
    }

    $insert = db()->prepare("
      INSERT INTO scans (ip, mode, state, date_begin, ports_count)
      VALUES (:ip, :mode, 'queued', NULL, 0)
    ");
    $insert->execute(array('ip' => $ip, 'mode' => $mode));
    $metadata = scanMetadataJobById((int)db()->lastInsertId());
    if ($metadata === null)
      throw new RuntimeException('failed to read queued scan');
    return array('metadata' => $metadata, 'created' => true);
  } finally {
    $release = db()->prepare('SELECT RELEASE_LOCK(:name)');
    $release->execute(array('name' => $lockName));
  }
}

function scanMetadataJobById(int $id): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE id=:id
    LIMIT 1
  ");
  $stmt->execute(array('id' => $id));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataClaimQueued(int $limit): array {
  $limit = max(0, min(20, $limit));
  if ($limit === 0)
    return array();

  $stmt = db()->prepare("
    SELECT queued.id
    FROM scans queued
    WHERE queued.state='queued'
      AND NOT EXISTS (
        SELECT 1
        FROM scans running
        WHERE running.ip=queued.ip AND running.state='running'
      )
    ORDER BY CASE queued.mode
      WHEN 'quick' THEN 0
      WHEN 'lightweight' THEN 0
      WHEN 'standard' THEN 1
      ELSE 2
    END, queued.id ASC
    LIMIT $limit
  ");
  $stmt->execute();

  $jobs = array();
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $update = db()->prepare("
      UPDATE scans
      SET state='running', date_begin=CURRENT_TIMESTAMP, date_end=NULL, duration=NULL, error=NULL
      WHERE id=:id AND state='queued'
    ");
    $update->execute(array('id' => $id));
    if ($update->rowCount() !== 1)
      continue;
    $job = scanMetadataJobById((int)$id);
    if ($job !== null)
      $jobs[] = $job;
  }
  return $jobs;
}

function scanMetadataQueuedCount(): int {
  return (int)db()->query("SELECT COUNT(*) FROM scans WHERE state='queued'")->fetchColumn();
}

function scanMetadataRunningCount(): int {
  return (int)db()->query("SELECT COUNT(*) FROM scans WHERE state='running'")->fetchColumn();
}

function scanMetadataExpireStaleRunning(int $maxSeconds): int {
  $maxSeconds = max(60, $maxSeconds);
  $stmt = db()->prepare("
    UPDATE scans
    SET state='timeout',
        date_end=CURRENT_TIMESTAMP,
        duration=IF(date_begin IS NULL, NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, date_begin, CURRENT_TIMESTAMP))),
        error=COALESCE(NULLIF(error, ''), 'scan worker stopped before completion')
    WHERE state='running'
      AND (date_begin IS NULL OR date_begin <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL $maxSeconds SECOND))
  ");
  $stmt->execute();
  return $stmt->rowCount();
}

function scanMetadataStart(string $ip, string $mode): int {
  $stmt = db()->prepare("
    INSERT INTO scans (ip, mode, state, date_begin, ports_count)
    VALUES (:ip, :mode, 'running', CURRENT_TIMESTAMP, 0)
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode));
  return (int)db()->lastInsertId();
}

function scanMetadataComplete(int $id, string $status, int $portsCount, ?int $duration, ?string $xml, ?string $xmlHash): bool {
  $database = db();
  $database->beginTransaction();

  try {
    $job = scanMetadataRawById($id, true);
    if ($job === null)
      throw new RuntimeException("scan job $id not found");

    $snapshotId = null;
    $changed = 0;
    if ($xml !== null && $xmlHash !== null) {
      $snapshot = scanEnsureSnapshot($job, $xml, $xmlHash);
      $snapshotId = $snapshot['id'];
      $changed = $snapshot['changed'] ? 1 : 0;
      scanRecordPortChanges($job, $xml);
    }

    $stmt = $database->prepare("
      UPDATE scans
      SET state='complete',
          status=:status,
          date_end=CURRENT_TIMESTAMP,
          duration=:duration,
          ports_count=:ports_count,
          snapshot_id=:snapshot_id,
          result_changed=:result_changed,
          port_changes_processed=1,
          error=NULL
      WHERE id=:id
    ");
    $stmt->execute(array(
      'id' => $id,
      'status' => $status,
      'duration' => $duration,
      'ports_count' => $portsCount,
      'snapshot_id' => $snapshotId,
      'result_changed' => $changed
    ));
    $database->commit();
    return $changed === 1;
  } catch (Throwable $e) {
    if ($database->inTransaction())
      $database->rollBack();
    throw $e;
  }
}

function scanMetadataRawById(int $id, bool $forUpdate = false): ?array {
  $suffix = $forUpdate ? ' FOR UPDATE' : '';
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE id=:id
    LIMIT 1$suffix
  ");
  $stmt->execute(array('id' => $id));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row === false ? null : $row;
}

function scanEnsureSnapshot(array $job, string $xml, string $hash): array {
  $previous = db()->prepare("
    SELECT s.snapshot_id, ss.result_hash
    FROM scans s
    INNER JOIN scan_snapshots ss ON ss.id=s.snapshot_id
    WHERE s.ip=:ip
      AND s.mode=:mode
      AND s.id<:id
      AND s.state='complete'
    ORDER BY s.id DESC
    LIMIT 1
  ");
  $previous->execute(array('ip' => $job['ip'], 'mode' => $job['mode'], 'id' => $job['id']));
  $previousRow = $previous->fetch(PDO::FETCH_ASSOC);

  $insert = db()->prepare("
    INSERT IGNORE INTO scan_snapshots (ip, mode, result_hash, xml)
    VALUES (:ip, :mode, :result_hash, :xml)
  ");
  $insert->execute(array(
    'ip' => $job['ip'],
    'mode' => $job['mode'],
    'result_hash' => $hash,
    'xml' => $xml
  ));

  $find = db()->prepare("
    SELECT id
    FROM scan_snapshots
    WHERE ip=:ip AND mode=:mode AND result_hash=:result_hash
    LIMIT 1
  ");
  $find->execute(array('ip' => $job['ip'], 'mode' => $job['mode'], 'result_hash' => $hash));
  $snapshotId = (int)$find->fetchColumn();
  if ($snapshotId <= 0)
    throw new RuntimeException('failed to persist scan snapshot');

  return array(
    'id' => $snapshotId,
    'changed' => $previousRow === false || !hash_equals((string)$previousRow['result_hash'], $hash)
  );
}

function scanRecordPortChanges(array $job, string $xml, ?string $createdAt = null): int {
  $previous = scanEffectivePortsBefore((string)$job['ip'], (int)$job['id']);
  $currentScan = scanParsePortObservation($xml, (string)$job['ip']);
  $current = scanApplyPortObservation($previous, $currentScan, (string)$job['mode']);
  $changes = scanComparePorts($previous, $current);
  if (count($changes) === 0)
    return 0;

  $insert = db()->prepare("
    INSERT IGNORE INTO scan_port_changes (
      scan_id, ip, mode, change_type, protocol, port,
      previous_service, previous_version, current_service, current_version, created_at
    ) VALUES (
      :scan_id, :ip, :mode, :change_type, :protocol, :port,
      :previous_service, :previous_version, :current_service, :current_version,
      COALESCE(:created_at, CURRENT_TIMESTAMP)
    )
  ");
  $inserted = 0;
  foreach ($changes as $change) {
    $insert->execute(array(
      'scan_id' => $job['id'],
      'ip' => $job['ip'],
      'mode' => $job['mode'],
      'change_type' => $change['change_type'],
      'protocol' => $change['protocol'],
      'port' => $change['port'],
      'previous_service' => scanNullIfEmpty($change['previous_service']),
      'previous_version' => scanNullIfEmpty($change['previous_version']),
      'current_service' => scanNullIfEmpty($change['current_service']),
      'current_version' => scanNullIfEmpty($change['current_version']),
      'created_at' => $createdAt
    ));
    $inserted += $insert->rowCount();
  }
  return $inserted;
}

function scanPortChangesBackfill(): int {
  $database = db();
  $stmt = $database->query("
    SELECT
      s.id,
      s.ip,
      s.mode,
      COALESCE(s.date_end, s.date_begin) AS change_date,
      ss.xml
    FROM scans s
    INNER JOIN scan_snapshots ss ON ss.id=s.snapshot_id
    WHERE s.state='complete'
      AND s.port_changes_processed=0
    ORDER BY s.id ASC
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (count($rows) === 0) {
    scanPrunePortChanges();
    return 0;
  }

  $database->beginTransaction();
  try {
    $inserted = 0;
    $mark = $database->prepare("UPDATE scans SET port_changes_processed=1 WHERE id=:id");
    foreach ($rows as $row) {
      $inserted += scanRecordPortChanges(
        array('id' => (int)$row['id'], 'ip' => $row['ip'], 'mode' => $row['mode']),
        (string)$row['xml'],
        ($row['change_date'] ?? '') === '' ? null : (string)$row['change_date']
      );
      $mark->execute(array('id' => $row['id']));
    }
    $database->commit();
    scanPrunePortChanges();
    return $inserted;
  } catch (Throwable $e) {
    if ($database->inTransaction())
      $database->rollBack();
    throw $e;
  }
}

function scanEffectivePortsBefore(string $ip, int $beforeId): array {
  $deep = scanPreviousSnapshotXml($ip, 'deep', $beforeId);
  $afterId = $deep === null ? 0 : (int)$deep['id'];
  $ports = $deep === null
    ? array()
    : scanOpenPortMap(scanParsePortObservation($deep['xml'], $ip));

  $stmt = db()->prepare("
    SELECT s.id, s.mode, ss.xml
    FROM scans s
    INNER JOIN scan_snapshots ss ON ss.id=s.snapshot_id
    WHERE s.ip=:ip
      AND s.id>:after_id
      AND s.id<:before_id
      AND s.mode<>'deep'
      AND s.state='complete'
    ORDER BY s.id ASC
  ");
  $stmt->execute(array('ip' => $ip, 'after_id' => $afterId, 'before_id' => $beforeId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $observation = scanParsePortObservation((string)$row['xml'], $ip);
    $ports = scanApplyPortObservation($ports, $observation, (string)$row['mode']);
  }

  return $ports;
}

function scanParsePortObservation(string $xml, string $ip): array {
  $scan = scanParseXml($xml, array('ip' => $ip));
  $scan['port_scope'] = scanParsePortScope($xml);
  return $scan;
}

function scanPreviousSnapshotXml(string $ip, string $mode, int $beforeId): ?array {
  $stmt = db()->prepare("
    SELECT s.id, ss.xml
    FROM scans s
    INNER JOIN scan_snapshots ss ON ss.id=s.snapshot_id
    WHERE s.ip=:ip
      AND s.mode=:mode
      AND s.id<:before_id
      AND s.state='complete'
    ORDER BY s.id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode, 'before_id' => $beforeId));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row === false)
    return null;
  return array('id' => (int)$row['id'], 'xml' => (string)$row['xml']);
}

function scanOpenPortMap(array $scan): array {
  $ports = array();
  foreach ($scan['ports'] ?? array() as $port) {
    if (strtolower((string)($port['state'] ?? '')) !== 'open')
      continue;
    $key = scanPortKey($port);
    if ($key !== null)
      $ports[$key] = $port;
  }
  return $ports;
}

function scanApplyPortObservation(array $base, array $scan, string $mode): array {
  $scope = $scan['port_scope'] ?? array();
  if (count($scope) === 0 && $mode === 'deep')
    $scope = array('tcp' => array(array(1, 65535)));
  $known = $base;

  foreach ($base as $key => $port) {
    if (scanPortIsInScope($port, $scope))
      unset($base[$key]);
  }

  foreach (scanOpenPortMap($scan) as $key => $observed)
    $base[$key] = scanMergePortKnowledge($known[$key] ?? null, $observed);
  ksort($base);
  return $base;
}

function scanPortIsInScope(array $port, array $scope): bool {
  $protocol = strtolower((string)($port['protocol'] ?? ''));
  $number = (int)($port['port'] ?? 0);
  foreach ($scope[$protocol] ?? array() as $range) {
    if ($number >= $range[0] && $number <= $range[1])
      return true;
  }
  return false;
}

function scanPortKey(array $port): ?string {
  $protocol = strtolower(trim((string)($port['protocol'] ?? '')));
  $number = (int)($port['port'] ?? 0);
  if ($protocol === '' || $number < 1 || $number > 65535)
    return null;
  return $protocol . '|' . $number;
}

function scanMergePortKnowledge(?array $known, array $observed): array {
  if ($known === null)
    return $observed;
  if (trim((string)($observed['service'] ?? '')) === '')
    $observed['service'] = $known['service'] ?? '';
  if (trim((string)($observed['details'] ?? '')) === '')
    $observed['details'] = $known['details'] ?? '';
  if (trim((string)($observed['tunnel'] ?? '')) === '')
    $observed['tunnel'] = $known['tunnel'] ?? '';
  return $observed;
}

function scanComparePorts(array $previous, array $current): array {
  $changes = array();
  foreach (array_unique(array_merge(array_keys($previous), array_keys($current))) as $key) {
    $before = $previous[$key] ?? null;
    $after = $current[$key] ?? null;
    if ($before === null) {
      $changes[] = scanPortChange('appeared', null, $after);
    } elseif ($after === null) {
      $changes[] = scanPortChange('disappeared', $before, null);
    } elseif (scanPortVersionChanged($before, $after)) {
      $changes[] = scanPortChange('changed', $before, $after);
    }
  }
  return $changes;
}

function scanPortVersionChanged(array $before, array $after): bool {
  $beforeService = trim((string)($before['service'] ?? ''));
  $afterService = trim((string)($after['service'] ?? ''));
  if ($beforeService !== '' && $afterService !== '' && $beforeService !== $afterService)
    return true;

  $beforeVersion = trim((string)($before['details'] ?? ''));
  $afterVersion = trim((string)($after['details'] ?? ''));
  return $beforeVersion !== '' && $afterVersion !== '' && $beforeVersion !== $afterVersion;
}

function scanPortChange(string $type, ?array $before, ?array $after): array {
  $port = $after ?? $before ?? array();
  return array(
    'change_type' => $type,
    'protocol' => strtolower((string)($port['protocol'] ?? '')),
    'port' => (int)($port['port'] ?? 0),
    'previous_service' => (string)($before['service'] ?? ''),
    'previous_version' => (string)($before['details'] ?? ''),
    'current_service' => (string)($after['service'] ?? ''),
    'current_version' => (string)($after['details'] ?? '')
  );
}

function scanNullIfEmpty(string $value): ?string {
  $value = trim($value);
  return $value === '' ? null : $value;
}

function scanMetadataFailed(int $id, string $error): void {
  $stmt = db()->prepare("
    UPDATE scans
    SET state='failed',
        date_end=CURRENT_TIMESTAMP,
        duration=IF(date_begin IS NULL, NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, date_begin, CURRENT_TIMESTAMP))),
        error=:error
    WHERE id=:id
  ");
  $stmt->execute(array('id' => $id, 'error' => $error));
}

function scanMetadataTimedOut(int $id, string $error): void {
  $stmt = db()->prepare("
    UPDATE scans
    SET state='timeout',
        date_end=CURRENT_TIMESTAMP,
        duration=IF(date_begin IS NULL, NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, date_begin, CURRENT_TIMESTAMP))),
        error=:error
    WHERE id=:id
  ");
  $stmt->execute(array('id' => $id, 'error' => $error));
}

function scanMetadataLatest(string $ip): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataBestResult(string $ip, ?string $mode = null): ?array {
  if ($mode !== null && !scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $modeWhere = $mode === null ? '' : ' AND mode=:mode';
  $order = $mode === null
    ? "CASE mode WHEN 'deep' THEN 0 ELSE 1 END, id DESC"
    : 'id DESC';
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND state='complete'
      AND snapshot_id IS NOT NULL
      AND result_changed=1
      $modeWhere
    ORDER BY $order
    LIMIT 1
  ");
  $params = array('ip' => $ip);
  if ($mode !== null)
    $params['mode'] = $mode;
  $stmt->execute($params);
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataPreviousResult(string $ip, string $mode, int $beforeId): ?array {
  if (!scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
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
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataById(string $ip, int $id): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip AND id=:id
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'id' => $id));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMergePartialWithDeep(array $partial, array $deep, array $deepMetadata): array {
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

  $merged['addresses'] = scanMergeResultItems(
    $deep['addresses'] ?? array(),
    $partial['addresses'] ?? array(),
    fn($item) => ($item['type'] ?? '') . '|' . ($item['addr'] ?? ''),
    $partialMode
  );
  $merged['hostnames'] = scanMergeResultItems(
    $deep['hostnames'] ?? array(),
    $partial['hostnames'] ?? array(),
    fn($item) => ($item['type'] ?? '') . '|' . ($item['name'] ?? ''),
    $partialMode
  );
  $merged['ports'] = scanMergePorts($deep['ports'] ?? array(), $partial['ports'] ?? array(), $partialMode);
  usort($merged['ports'], function ($left, $right) {
    $portOrder = (int)($left['port'] ?? 0) <=> (int)($right['port'] ?? 0);
    return $portOrder !== 0 ? $portOrder : strcmp((string)($left['protocol'] ?? ''), (string)($right['protocol'] ?? ''));
  });

  $partialOs = $partial['os'] ?? array();
  $merged['os'] = scanMarkResultSource(count($partialOs) !== 0 ? $partialOs : ($deep['os'] ?? array()), count($partialOs) !== 0 ? $partialMode : 'deep');
  $merged['ports_count'] = count($merged['ports']);
  $merged['metadata'] = $partial['metadata'] ?? null;
  $merged['xml'] = $partial['xml'] ?? null;
  $merged['merged'] = true;
  $merged['merged_with'] = $deepMetadata;
  return $merged;
}

function scanMergeResultItems(array $base, array $overlay, callable $key, string $overlaySource): array {
  $items = array();
  foreach (scanMarkResultSource($base, 'deep') as $item)
    $items[$key($item)] = $item;
  foreach (scanMarkResultSource($overlay, $overlaySource) as $item)
    $items[$key($item)] = $item;
  return array_values($items);
}

function scanMergePorts(array $deep, array $partial, string $partialSource): array {
  $items = array();
  foreach (scanMarkResultSource($deep, 'deep') as $item) {
    $key = ($item['protocol'] ?? '') . '|' . ($item['port'] ?? '');
    $items[$key] = $item;
  }
  foreach (scanMarkResultSource($partial, $partialSource) as $item) {
    $key = ($item['protocol'] ?? '') . '|' . ($item['port'] ?? '');
    $items[$key] = scanMergePortKnowledge($items[$key] ?? null, $item);
  }
  return array_values($items);
}

function scanMarkResultSource(array $items, string $source): array {
  return array_map(function ($item) use ($source) {
    $item['source'] = $source;
    return $item;
  }, $items);
}

function scanMetadataHistory(string $ip, int $limit = 30): array {
  $limit = max(1, min(100, $limit));
  $days = SCAN_HISTORY_DAYS;
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND snapshot_id IS NOT NULL
      AND result_changed=1
      AND (
        COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) >= DATE_SUB(NOW(), INTERVAL $days DAY)
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
    $metadata = scanNormalizeMetadata($row);
    if (scanMetadataXmlUsable($metadata))
      $history[] = $metadata;
  }
  return $history;
}

function scanMetadataForIp(string $ip, int $limit = 50): array {
  $limit = max(1, min(100, $limit));
  $days = SCAN_HISTORY_DAYS;
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND (state<>'complete' OR result_changed=1)
      AND (
        COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) >= DATE_SUB(NOW(), INTERVAL $days DAY)
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
    $metadata = scanNormalizeMetadata($row);
    $metadata['xml_usable'] = scanMetadataXmlUsable($metadata);
    $metadata['xml_url'] = $metadata['xml_usable'] ? scanXmlUrl($metadata['ip'], $metadata['id']) : null;
    $scans[] = $metadata;
  }
  return $scans;
}

function scanMetadataQueue(int $limit = 100): array {
  $limit = max(1, min(200, $limit));
  $stmt = db()->prepare("
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
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metadata = scanNormalizeMetadata($row);
    $metadata['host_id'] = $row['host_id'] === null ? null : (int)$row['host_id'];
    $metadata['name'] = $row['name'] ?? '';
    $metadata['mac'] = strtolower((string)($row['mac'] ?? ''));
    $metadata['important'] = (int)($row['important'] ?? 0);
    $metadata['xml_usable'] = scanMetadataXmlUsable($metadata);
    $metadata['xml_url'] = $metadata['xml_usable'] ? scanXmlUrl($metadata['ip'], $metadata['id']) : null;
    $queue[] = $metadata;
  }

  return $queue;
}

function scanMetadataLatestByIp(): array {
  $stmt = db()->prepare("
    SELECT s.id, s.ip, s.mode, s.state, s.status, s.date_begin, s.date_end, s.duration, s.ports_count, s.snapshot_id, s.result_changed, s.error
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
    $scans[$row['ip']] = scanNormalizeMetadata($row);
  return $scans;
}

function scanMetadataLatestUsableByIp(): array {
  $stmt = db()->query("
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
    ORDER BY INET_ATON(s.ip), s.ip
  ");

  $results = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metadata = scanNormalizeMetadata($row);
    $metadata['host_id'] = $row['host_id'] === null ? null : (int)$row['host_id'];
    $metadata['name'] = (string)($row['name'] ?? '');
    $metadata['mac'] = strtolower((string)($row['mac'] ?? ''));
    $results[] = $metadata;
  }
  return $results;
}

function scanNormalizeMetadata(array $metadata): array {
  $metadata['id'] = isset($metadata['id']) ? (int)$metadata['id'] : null;
  $metadata['duration'] = isset($metadata['duration']) && $metadata['duration'] !== null ? (int)$metadata['duration'] : null;
  $metadata['ports_count'] = isset($metadata['ports_count']) ? (int)$metadata['ports_count'] : 0;
  $metadata['snapshot_id'] = isset($metadata['snapshot_id']) && $metadata['snapshot_id'] !== null ? (int)$metadata['snapshot_id'] : null;
  $metadata['result_changed'] = (int)($metadata['result_changed'] ?? 0);
  $metadata['xml_usable'] = (int)($metadata['snapshot_id'] ?? 0) > 0;
  $metadata['xml_url'] = $metadata['xml_usable'] && isset($metadata['ip'], $metadata['id'])
    ? scanXmlUrl($metadata['ip'], $metadata['id'])
    : null;
  $metadata['xml'] = $metadata['xml_url'];
  return $metadata;
}

function scanPruneHistory(string $ip): void {
  scanPruneOldHistory($ip);
  scanPruneOrphanSnapshots();
  scanPrunePortChanges();
}

function scanPrunePortChanges(): void {
  $days = SCAN_HISTORY_DAYS;
  db()->exec("DELETE FROM scan_port_changes WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
}

function scanPruneOldHistory(string $ip): void {
  $days = SCAN_HISTORY_DAYS;
  $stmt = db()->prepare("
    SELECT id
    FROM scans
    WHERE ip=:ip
      AND COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) < DATE_SUB(NOW(), INTERVAL $days DAY)
      AND id NOT IN (
        SELECT keep_id
        FROM (
          SELECT MAX(id) AS keep_id
          FROM scans
          WHERE ip=:keep_ip AND state='complete' AND result_changed=1
          GROUP BY mode
        ) latest_results
      )
  ");
  $stmt->execute(array('ip' => $ip, 'keep_ip' => $ip));

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    scanDeleteMetadata($row);
}

function scanPruneOrphanSnapshots(): void {
  db()->exec("
    DELETE ss
    FROM scan_snapshots ss
    LEFT JOIN scans s ON s.snapshot_id=ss.id
    WHERE s.id IS NULL
  ");
}

function scanDeleteMetadata(array $metadata): void {
  if (isset($metadata['id']) && $metadata['id'] !== null) {
    $stmt = db()->prepare("DELETE FROM scans WHERE id=:id");
    $stmt->execute(array('id' => $metadata['id']));
  }
}
