<?php

const SCAN_DIR = __DIR__ . '/nmap';
const SCAN_XSL_FROM = 'file:///usr/bin/../share/nmap/';
const SCAN_XSL_LEGACY = '../res/xsl/';
const SCAN_XSL_TO = '/res/xsl/';

function scanXmlPath(string $ip): string {
  return SCAN_DIR . '/' . $ip . '.xml';
}

function scanXmlUrl(string $ip): string {
  return '/api/scans/' . rawurlencode($ip) . '.xml';
}

function scanReadXml(string $ip): ?string {
  $path = scanXmlPath($ip);
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
    'xml' => isset($metadata['ip']) ? scanXmlUrl($metadata['ip']) : null
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

function scanMetadataComplete(int $id, string $status, int $portsCount, ?int $duration, ?string $xml): void {
  $stmt = db()->prepare("
    UPDATE scans
    SET state='complete',
        status=:status,
        date_end=CURRENT_TIMESTAMP,
        duration=:duration,
        ports_count=:ports_count,
        xml=:xml,
        error=NULL
    WHERE id=:id
  ");
  $stmt->execute(array(
    'id' => $id,
    'status' => $status,
    'duration' => $duration,
    'ports_count' => $portsCount,
    'xml' => $xml
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

function scanMetadataLatest(string $ip): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, xml, error
    FROM scans
    WHERE ip=:ip
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataLatestByIp(): array {
  $stmt = db()->prepare("
    SELECT s.id, s.ip, s.mode, s.state, s.status, s.date_begin, s.date_end, s.duration, s.ports_count, s.xml, s.error
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
  return $metadata;
}
