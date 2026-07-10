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

function handleScanQuick(array $params): void {
  $ip = $params['ip'];
  $queued = scanMetadataEnqueue($ip, 'quick');
  startScanWorkerAsync();

  jsonResponse(array(
    'queued' => true,
    'created' => $queued['created'],
    'metadata' => $queued['metadata'],
    'xml' => '/api/scans/' . rawurlencode($ip) . '/xml'
  ), 202);
}

function startScanWorkerAsync(): void {
  $cli = dirname(__DIR__) . '/cli.php';
  $command = '/usr/bin/sudo /usr/bin/php ' . escapeshellarg($cli) . ' inventory --work';
  exec($command . ' </dev/null >/dev/null 2>&1 &');
}

function handleScanStatus(array $params): array {
  $ip = $params['ip'];
  $requestedId = $_GET['id'] ?? null;
  if ($requestedId !== null) {
    if (!is_scalar($requestedId) || !ctype_digit((string)$requestedId))
      jsonError(400, 'invalid scan id');
    $metadata = scanMetadataById($ip, (int)$requestedId);
    if ($metadata === null)
      jsonError(404, 'scan not found');
    return $metadata;
  }

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
  $metadata = $id === null ? scanMetadataBestResult($ip) : scanMetadataById($ip, $id);
  if ($id !== null && $metadata === null)
    jsonError(404, 'scan not found');

  $xml = scanReadXml($ip, $metadata);
  $deepMetadata = $metadata !== null && ($metadata['mode'] ?? '') === 'quick'
    ? scanMetadataPreviousResult($ip, 'deep', (int)$metadata['id'])
    : null;
  if ($xml === null && $deepMetadata === null)
    jsonError(404, 'scan not found');

  $scan = $xml === null
    ? array(
      'ip' => $ip,
      'args' => '',
      'started' => '',
      'status' => $metadata['status'] ?? '',
      'uptime' => '',
      'duration' => $metadata['duration'] ?? null,
      'ports_count' => 0,
      'addresses' => array(),
      'hostnames' => array(),
      'os' => array(),
      'ports' => array(),
      'metadata' => $metadata,
      'xml' => null
    )
    : scanParseXml($xml, $metadata ?: array('ip' => $ip));

  if ($deepMetadata !== null) {
    $deepXml = scanReadXml($ip, $deepMetadata);
    if ($deepXml !== null)
      $scan = scanMergeQuickWithDeep($scan, scanParseXml($deepXml, $deepMetadata), $deepMetadata);
  }

  if ($metadata === null)
    $scan['metadata'] = null;
  return $scan;
}

function streamScanXml(string $ip, ?int $id = null): void {
  $metadata = $id === null ? scanMetadataBestResult($ip) : scanMetadataById($ip, $id);
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
