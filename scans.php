<?php

define('SCAN_DIR', FENPING_DATA_DIR . '/nmap');
const SCAN_XSL_FROM = 'file:///usr/bin/../share/nmap/';
const SCAN_XSL_LEGACY = '../res/xsl/';
const SCAN_XSL_TO = '/res/xsl/';
const SCAN_HISTORY_DAYS = 7;

function scanXmlPath(string $ip, ?int $id = null): string {
  if ($id !== null)
    return SCAN_DIR . '/history/' . $ip . '/' . $id . '.xml';
  return SCAN_DIR . '/' . $ip . '.xml';
}

function scanXmlUrl(string $ip, ?int $id = null): string {
  if ($id !== null)
    return '/api/scans/' . rawurlencode($ip) . '/' . $id . '.xml';
  return '/api/scans/' . rawurlencode($ip) . '.xml';
}

function scanReadXml(string $ip, ?array $metadata = null): ?string {
  if ($metadata !== null && ($metadata['xml'] ?? '') !== '') {
    if (!scanMetadataXmlUsable($metadata))
      return null;
    $path = $metadata['xml'];
  } else {
    $path = scanXmlPath($ip);
  }

  if (!is_file($path) || !is_readable($path))
    return null;

  $xml = file_get_contents($path);
  if ($xml === false)
    return null;

  return scanNormalizeXml($xml);
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
    'ports' => $scan['ports'] ?? array()
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
  $ip = $metadata['ip'] ?? '';
  $path = $metadata['xml'] ?? '';
  if ($ip === '' || $path === '' || !is_file($path) || !is_readable($path))
    return false;

  if ($path !== scanXmlPath($ip))
    return true;

  $latest = scanMetadataLatest($ip);
  return $latest !== null && (int)$latest['id'] === (int)($metadata['id'] ?? 0);
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
    'os' => array_slice(scanParseTags($host, 'osmatch', function ($attributes) {
      return array(
        'name' => $attributes['name'] ?? '',
        'accuracy' => $attributes['accuracy'] ?? ''
      );
    }), 0, 5),
    'ports' => $ports,
    'metadata' => $metadata,
    'xml' => isset($metadata['ip']) ? scanXmlUrl($metadata['ip'], $metadata['id'] ?? null) : null
  );
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
    'details' => $details
  );
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

function scanMetadataStart(string $ip, string $mode): int {
  $stmt = db()->prepare("
    INSERT INTO scans (ip, mode, state, date_begin, ports_count)
    VALUES (:ip, :mode, 'running', CURRENT_TIMESTAMP, 0)
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode));
  return (int)db()->lastInsertId();
}

function scanMetadataComplete(int $id, string $status, int $portsCount, ?int $duration, ?string $xml, ?string $xmlHash): void {
  $stmt = db()->prepare("
    UPDATE scans
    SET state='complete',
        status=:status,
        date_end=CURRENT_TIMESTAMP,
        duration=:duration,
        ports_count=:ports_count,
        xml=:xml,
        xml_hash=:xml_hash,
        error=NULL
    WHERE id=:id
  ");
  $stmt->execute(array(
    'id' => $id,
    'status' => $status,
    'duration' => $duration,
    'ports_count' => $portsCount,
    'xml' => $xml,
    'xml_hash' => $xmlHash
  ));
}

function scanMetadataFailed(int $id, string $error): void {
  $stmt = db()->prepare("
    UPDATE scans
    SET state='failed',
        date_end=CURRENT_TIMESTAMP,
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
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, xml_hash, error
    FROM scans
    WHERE ip=:ip
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataById(string $ip, int $id): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, xml_hash, error
    FROM scans
    WHERE ip=:ip AND id=:id
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'id' => $id));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataHistory(string $ip, int $limit = 30): array {
  $limit = max(1, min(100, $limit));
  $days = SCAN_HISTORY_DAYS;
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, xml_hash, error
    FROM scans
    WHERE ip=:ip
      AND COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) >= DATE_SUB(NOW(), INTERVAL $days DAY)
    ORDER BY id DESC
    LIMIT $limit
  ");
  $stmt->execute(array('ip' => $ip));

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
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, xml_hash, error
    FROM scans
    WHERE ip=:ip
      AND COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) >= DATE_SUB(NOW(), INTERVAL $days DAY)
    ORDER BY id DESC
    LIMIT $limit
  ");
  $stmt->execute(array('ip' => $ip));

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
      s.xml,
      s.xml_hash,
      s.error,
      i.id AS host_id,
      COALESCE(i.name, '') AS name,
      COALESCE(i.mac, '') AS mac,
      COALESCE(i.important, 0) AS important
    FROM scans s
    LEFT JOIN ips i ON i.ip=s.ip
    ORDER BY
      CASE WHEN s.state='running' THEN 0 ELSE 1 END,
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
    SELECT s.id, s.ip, s.mode, s.state, s.status, s.date_begin, s.date_end, s.duration, s.ports_count, s.xml, s.xml_hash, s.error
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

function scanNormalizeMetadata(array $metadata): array {
  $metadata['id'] = isset($metadata['id']) ? (int)$metadata['id'] : null;
  $metadata['duration'] = isset($metadata['duration']) && $metadata['duration'] !== null ? (int)$metadata['duration'] : null;
  $metadata['ports_count'] = isset($metadata['ports_count']) ? (int)$metadata['ports_count'] : 0;
  $metadata['xml_hash'] = $metadata['xml_hash'] ?? null;
  return $metadata;
}

function scanPruneHistory(string $ip): void {
  scanPruneOldHistory($ip);
  scanPruneUnusableLegacyHistory($ip);
  scanPruneDuplicateHistory($ip);
  scanRemoveEmptyHistoryDir($ip);
}

function scanPruneOldHistory(string $ip): void {
  $days = SCAN_HISTORY_DAYS;
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, xml_hash, error
    FROM scans
    WHERE ip=:ip
      AND COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) < DATE_SUB(NOW(), INTERVAL $days DAY)
  ");
  $stmt->execute(array('ip' => $ip));

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    scanDeleteMetadata(scanNormalizeMetadata($row));
}

function scanPruneDuplicateHistory(string $ip): void {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, xml_hash, error
    FROM scans
    WHERE ip=:ip
    ORDER BY id DESC
  ");
  $stmt->execute(array('ip' => $ip));

  $seen = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metadata = scanNormalizeMetadata($row);
    $hash = scanMetadataHash($metadata);
    if ($hash === null || $hash === '')
      continue;

    if (isset($seen[$hash])) {
      scanDeleteMetadata($metadata);
      continue;
    }

    $seen[$hash] = $metadata['id'];
  }
}

function scanPruneUnusableLegacyHistory(string $ip): void {
  $latest = scanMetadataLatest($ip);
  if ($latest === null)
    return;

  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, xml_hash, error
    FROM scans
    WHERE ip=:ip
      AND id<>:latest_id
      AND xml=:latest_xml
  ");
  $stmt->execute(array(
    'ip' => $ip,
    'latest_id' => $latest['id'],
    'latest_xml' => scanXmlPath($ip)
  ));

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    scanDeleteMetadata(scanNormalizeMetadata($row));
}

function scanMetadataHash(array $metadata): ?string {
  if (($metadata['xml_hash'] ?? '') !== '')
    return $metadata['xml_hash'];

  if ($metadata['id'] === null)
    return null;

  if (($metadata['xml'] ?? '') === '' && ($metadata['status'] ?? '') !== '') {
    $hash = hash('sha256', json_encode(array(
      'ip' => $metadata['ip'] ?? '',
      'status' => $metadata['status'] ?? ''
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    scanUpdateMetadataHash($metadata['id'], $hash);
    return $hash;
  }

  $xml = scanReadXml($metadata['ip'], $metadata);
  if ($xml === null)
    return null;

  $hash = scanXmlHash($xml, $metadata['ip']);
  scanUpdateMetadataHash($metadata['id'], $hash);
  return $hash;
}

function scanUpdateMetadataHash(int $id, string $hash): void {
  $stmt = db()->prepare("UPDATE scans SET xml_hash=:xml_hash WHERE id=:id");
  $stmt->execute(array('id' => $id, 'xml_hash' => $hash));
}

function scanDeleteMetadata(array $metadata): void {
  $path = scanMetadataHistoryPath($metadata);
  if ($path !== null && is_file($path))
    @unlink($path);

  if ($metadata['id'] !== null) {
    $stmt = db()->prepare("DELETE FROM scans WHERE id=:id");
    $stmt->execute(array('id' => $metadata['id']));
  }
}

function scanMetadataHistoryPath(array $metadata): ?string {
  if (($metadata['ip'] ?? '') === '' || ($metadata['id'] ?? null) === null)
    return null;

  $expected = scanXmlPath($metadata['ip'], (int)$metadata['id']);
  return ($metadata['xml'] ?? '') === $expected ? $expected : null;
}

function scanRemoveEmptyHistoryDir(string $ip): void {
  $dir = SCAN_DIR . '/history/' . $ip;
  if (!is_dir($dir))
    return;

  $items = scandir($dir);
  if ($items !== false && count(array_diff($items, array('.', '..'))) === 0)
    @rmdir($dir);
}
