<?php

function scansApiRoutes(): array {
  return array(
    apiRoute('GET', '/scans', 'handleScansQueue'),
    apiRoute('POST', '/scans/{ip:ipv4}/quick', 'handleScanQuick', 'session'),
    apiRoute('GET', '/scans/{ip:ipv4}/status', 'handleScanStatus'),
    apiRoute('GET', '/scans/{ip:ipv4}/history', 'handleScanHistory'),
    apiRoute('GET', '/scans/{ip:ipv4}/history/{id:int}', 'handleScanHistoryJson'),
    apiRoute('GET', '/scans/{ip:ipv4}/history/{id:int}/xml', 'handleScanHistoryXml'),
    apiRoute('GET', '/scans/{ip:ipv4}/xml', 'handleScanXml'),
    apiRoute('GET', '/scans/{ip:ipv4}', 'handleScanJson'),

    apiRoute('GET', '/scans/{file:scanXml}', 'handleLegacyScanXml'),
    apiRoute('GET', '/scans/{ip:ipv4}/{id:int}', 'handleScanHistoryJson'),
    apiRoute('GET', '/scans/{ip:ipv4}/{file:scanIdXml}', 'handleLegacyScanHistoryXml')
  );
}

function handleScansQueue(array $params): array {
  return array('scans' => scanMetadataQueue());
}

function handleScanQuick(array $params): array {
  $ip = $params['ip'];
  $lock = '/tmp/inv-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $ip) . '.lck';
  $scan = '/usr/bin/sudo /usr/bin/php ' . escapeshellarg(dirname(__DIR__) . '/cli.php') . ' inventory --quick ' . escapeshellarg($ip);
  $command = 'flock -n ' . escapeshellarg($lock) . ' -c ' . escapeshellarg($scan);
  $previousMetadata = scanMetadataLatest($ip);
  $output = array();
  $code = 0;
  exec($command . ' 2>&1', $output, $code);

  if ($code !== 0) {
    $message = trim(implode("\n", $output));
    $metadata = scanMetadataLatest($ip);
    $isNewScan = $metadata !== null && (
      $previousMetadata === null || $metadata['id'] !== $previousMetadata['id']
    );
    if ($isNewScan && ($metadata['state'] ?? '') === 'timeout')
      jsonError(504, $message ?: ($metadata['error'] ?? 'scan timed out'));
    jsonError($message === '' ? 409 : 500, $message ?: 'scan already running');
  }

  $log = implode("\n", $output);
  return array(
    'saved' => strpos("\n" . $log . "\n", "\n" . $ip . " saved\n") !== false,
    'log' => $log,
    'metadata' => scanMetadataLatest($ip),
    'xml' => '/api/scans/' . rawurlencode($ip) . '/xml'
  );
}

function handleScanStatus(array $params): array {
  $ip = $params['ip'];
  return scanMetadataLatest($ip) ?: array(
    'ip' => $ip,
    'state' => 'none',
    'ports_count' => 0
  );
}

function handleScanHistory(array $params): array {
  return scanMetadataHistory($params['ip']);
}

function handleScanJson(array $params): array {
  return scanJsonResponse($params['ip']);
}

function handleScanHistoryJson(array $params): array {
  return scanJsonResponse($params['ip'], $params['id']);
}

function handleScanXml(array $params): void {
  streamScanXml($params['ip']);
}

function handleScanHistoryXml(array $params): void {
  streamScanXml($params['ip'], $params['id']);
}

function handleLegacyScanXml(array $params): void {
  streamScanXml(scanIpFromXmlFile($params['file']));
}

function handleLegacyScanHistoryXml(array $params): void {
  streamScanXml($params['ip'], scanIdFromXmlFile($params['file']));
}

function scanJsonResponse(string $ip, ?int $id = null): array {
  $metadata = $id === null ? scanMetadataLatest($ip) : scanMetadataById($ip, $id);
  if ($id !== null && $metadata === null)
    jsonError(404, 'scan not found');

  $xml = scanReadXml($ip, $metadata);
  if ($xml === null)
    jsonError(404, 'scan not found');

  $scan = scanParseXml($xml, $metadata ?: array('ip' => $ip));
  if ($metadata === null)
    $scan['metadata'] = null;
  return $scan;
}

function streamScanXml(string $ip, ?int $id = null): void {
  $metadata = $id === null ? null : scanMetadataById($ip, $id);
  if ($id !== null && $metadata === null)
    jsonError(404, 'scan not found');

  $xml = scanReadXml($ip, $metadata);
  if ($xml === null)
    jsonError(404, 'scan not found');

  header('Content-Type: application/xml; charset=utf-8');
  echo $xml;
  exit;
}

function scanIpFromXmlFile(string $file): string {
  if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\.xml$/', $file, $matches))
    jsonError(404, 'scan not found');
  return $matches[1];
}

function scanIdFromXmlFile(string $file): int {
  if (!preg_match('/^(\d+)\.xml$/', $file, $matches))
    jsonError(404, 'scan not found');
  return (int)$matches[1];
}
