<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;

trait RoutesScansBehavior
{
public function scansApiRoutes(): array {
  return array(
    $this->apiRoute('GET', '/scans', 'handleScansQueue'),
    $this->apiRoute('GET', '/scans/profiles', 'handleScanProfiles'),
    $this->apiRoute('GET', '/services', 'handleServices'),
    $this->apiRoute('POST', '/scans/{ip:ipv4}', 'handleScanCreate', 'session'),
    $this->apiRoute('POST', '/scans/{ip:ipv4}/quick', 'handleScanQuick', 'session'),
    $this->apiRoute('GET', '/scans/{ip:ipv4}/status', 'handleScanStatus'),
    $this->apiRoute('GET', '/scans/{ip:ipv4}/history', 'handleScanHistory'),
    $this->apiRoute('GET', '/scans/{ip:ipv4}/history/{id:int}', 'handleScanHistoryJson'),
    $this->apiRoute('GET', '/scans/{ip:ipv4}/history/{id:int}/xml', 'handleScanHistoryXml'),
    $this->apiRoute('GET', '/scans/{ip:ipv4}/xml', 'handleScanXml'),
    $this->apiRoute('GET', '/scans/{ip:ipv4}', 'handleScanJson'),

    $this->apiRoute('GET', '/scans/{file:scanXml}', 'handleLegacyScanXml'),
    $this->apiRoute('GET', '/scans/{ip:ipv4}/{id:int}', 'handleScanHistoryJson'),
    $this->apiRoute('GET', '/scans/{ip:ipv4}/{file:scanIdXml}', 'handleLegacyScanHistoryXml')
  );
}

public function handleScansQueue(array $params): array {
  return array('scans' => $this->scanMetadataQueue());
}

public function handleScanProfiles(array $params): array {
  return array('profiles' => $this->scanProfiles());
}

public function handleServices(array $params): array {
  $services = array();
  $hosts = array();

  foreach ($this->scanMetadataLatestUsableByIp() as $metadata) {
    $scan = $this->scanJsonResponse($metadata['ip'], $metadata['id']);
    $hostServices = 0;
    $vendor = $this->getVendor($metadata['mac']);
    foreach ($scan['ports'] ?? array() as $port) {
      if (strtolower((string)($port['state'] ?? '')) !== 'open')
        continue;
      $source = (string)($port['source'] ?? $metadata['mode']);
      $sourceMetadata = $source === 'deep' && !empty($scan['merged_with'])
        ? $scan['merged_with']
        : $metadata;
      $services[] = array(
        'host_id' => $metadata['host_id'],
        'name' => $metadata['name'],
        'ip' => $metadata['ip'],
        'mac' => $metadata['mac'],
        'vendor' => $vendor,
        'scan_id' => $metadata['id'],
        'scan_mode' => $metadata['mode'],
        'scan_date' => $sourceMetadata['date_end'] ?? $sourceMetadata['date_begin'],
        'merged' => !empty($scan['merged']),
        'protocol' => strtolower((string)($port['protocol'] ?? '')),
        'port' => (int)($port['port'] ?? 0),
        'service' => (string)($port['service'] ?? ''),
        'version' => (string)($port['details'] ?? ''),
        'tunnel' => (string)($port['tunnel'] ?? ''),
        'source' => $source
      );
      $hostServices++;
    }
    $hosts[] = array(
      'host_id' => $metadata['host_id'],
      'name' => $metadata['name'],
      'ip' => $metadata['ip'],
      'services' => $hostServices
    );
  }

  return array(
    'network' => $this->config->network,
    'summary' => array('hosts' => count($hosts), 'services' => count($services)),
    'hosts' => $hosts,
    'services' => $services
  );
}

public function handleScanCreate(array $params): void {
  $body = $this->requestBody();
  $profile = $body['profile'] ?? '';
  if (!is_string($profile) || !$this->scanProfileIsValid($profile, false))
    $this->jsonError(400, 'invalid scan profile');
  $this->queueScanResponse($params['ip'], $profile);
}

public function handleScanQuick(array $params): void {
  $this->queueScanResponse($params['ip'], 'lightweight');
}

public function queueScanResponse(string $ip, string $profile): void {
  $queued = $this->scanMetadataEnqueue($ip, $profile);
  $this->startScanWorkerAsync();

  $this->jsonResponse(array(
    'queued' => true,
    'created' => $queued['created'],
    'profile' => $profile,
    'metadata' => $queued['metadata'],
    'xml' => '/api/scans/' . rawurlencode($ip) . '/xml'
  ), 202);
}

public function startScanWorkerAsync(): void {
  $cli = $this->config->projectDir . '/cli.php';
  $command = '/usr/bin/doas /usr/bin/php ' . escapeshellarg($cli) . ' inventory --work';
  exec($command . ' </dev/null >/dev/null 2>&1 &');
}

public function handleScanStatus(array $params): array {
  $ip = $params['ip'];
  $requestedId = $_GET['id'] ?? null;
  if ($requestedId !== null) {
    if (!is_scalar($requestedId) || !ctype_digit((string)$requestedId))
      $this->jsonError(400, 'invalid scan id');
    $metadata = $this->scanMetadataById($ip, (int)$requestedId);
    if ($metadata === null)
      $this->jsonError(404, 'scan not found');
    return $metadata;
  }

  return $this->scanMetadataLatest($ip) ?: array(
    'ip' => $ip,
    'state' => 'none',
    'ports_count' => 0
  );
}

public function handleScanHistory(array $params): array {
  return $this->scanMetadataHistory($params['ip']);
}

public function handleScanJson(array $params): array {
  return $this->scanJsonResponse($params['ip']);
}

public function handleScanHistoryJson(array $params): array {
  return $this->scanJsonResponse($params['ip'], $params['id']);
}

public function handleScanXml(array $params): void {
  $this->streamScanXml($params['ip']);
}

public function handleScanHistoryXml(array $params): void {
  $this->streamScanXml($params['ip'], $params['id']);
}

public function handleLegacyScanXml(array $params): void {
  $this->streamScanXml($this->scanIpFromXmlFile($params['file']));
}

public function handleLegacyScanHistoryXml(array $params): void {
  $this->streamScanXml($params['ip'], $this->scanIdFromXmlFile($params['file']));
}

public function scanJsonResponse(string $ip, ?int $id = null): array {
  $metadata = $id === null ? $this->scanMetadataBestResult($ip) : $this->scanMetadataById($ip, $id);
  if ($id !== null && $metadata === null)
    $this->jsonError(404, 'scan not found');

  $scan = $this->scanReadSnapshot($ip, $metadata);
  $deepMetadata = $metadata !== null && $this->scanProfileIsPartial((string)($metadata['mode'] ?? ''))
    ? $this->scanMetadataPreviousResult($ip, 'deep', (int)$metadata['id'])
    : null;
  if ($scan === null && $deepMetadata === null)
    $this->jsonError(404, 'scan not found');

  $scan = $scan === null
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
    : $scan;

  if ($deepMetadata !== null) {
    $deepScan = $this->scanReadSnapshot($ip, $deepMetadata);
    if ($deepScan !== null)
      $scan = $this->scanMergePartialWithDeep($scan, $deepScan, $deepMetadata);
  }

  if ($metadata === null)
    $scan['metadata'] = null;
  return $scan;
}

public function streamScanXml(string $ip, ?int $id = null): void {
  $metadata = $id === null ? $this->scanMetadataBestResult($ip) : $this->scanMetadataById($ip, $id);
  if ($id !== null && $metadata === null)
    $this->jsonError(404, 'scan not found');

  $scan = $this->scanReadSnapshot($ip, $metadata);
  if ($scan === null)
    $this->jsonError(404, 'scan not found');

  throw new \FenPing\Api\ResponseException(new \FenPing\Api\Response(
    200,
    array('Content-Type' => 'application/xml; charset=utf-8'),
    $this->scanRenderXml($scan)
  ));
}

public function scanIpFromXmlFile(string $file): string {
  if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\.xml$/', $file, $matches))
    $this->jsonError(404, 'scan not found');
  return $matches[1];
}

public function scanIdFromXmlFile(string $file): int {
  if (!preg_match('/^(\d+)\.xml$/', $file, $matches))
    $this->jsonError(404, 'scan not found');
  return (int)$matches[1];
}
}
