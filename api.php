<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/scans.php';

set_exception_handler(function (Throwable $e): void {
  jsonError(500, 'server error');
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$segments = routeSegments();

if (count($segments) === 0) {
  jsonError(404, 'not found');
}

if ($method === 'GET' && $segments === array('inventory')) {
  jsonResponse(array(
    'network' => $GLOBALS['network'] ?? '',
    'hosts' => getInventory()
  ));
}

if ($method === 'POST' && $segments === array('ping', 'refresh')) {
  refreshPing();
}

if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'history') {
  $ip = validateIp($segments[1]);
  jsonResponse(get_history_response($ip));
}

if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'hosts') {
  $id = validateId($segments[1]);
  $host = getId($id);
  if ($host === false)
    jsonError(404, 'host not found');
  jsonResponse($host);
}

if ($method === 'POST' && $segments === array('hosts')) {
  $body = requestBody();
  requirePassword($body);
  $id = create(normalizeHostIp($body['ip'] ?? null), $body['mac'] ?? '');
  jsonResponse(array('id' => (int)$id));
}

if ($method === 'PUT' && count($segments) === 2 && $segments[0] === 'hosts') {
  $id = validateId($segments[1]);
  $body = requestBody();
  requirePassword($body);

  edit(
    $id,
    normalizeHostIp($body['ip'] ?? null),
    $body['mac'] ?? '',
    $body['name'] ?? '',
    toDbFlag($body['repeater'] ?? null),
    toDbFlag($body['important'] ?? null),
    toDbFlag($body['web'] ?? null),
    normalizeEmpty($body['router'] ?? null),
    normalizeEmpty($body['dns'] ?? null)
  );

  $log = reloadDhcpHosts();
  jsonResponse(array('log' => $log));
}

if ($method === 'DELETE' && count($segments) === 2 && $segments[0] === 'hosts') {
  $id = validateId($segments[1]);
  $body = requestBody();
  requirePassword($body);
  del($id);
  jsonResponse(array('deleted' => true));
}

if ($method === 'POST' && $segments === array('categories')) {
  $body = requestBody();
  requirePassword($body);
  addCategory($body['ip'] ?? '', $body['name'] ?? '');
  jsonResponse(array('created' => true));
}

if ($method === 'DELETE' && $segments === array('categories')) {
  $body = requestBody();
  requirePassword($body);
  delCategory($body['ip'] ?? '');
  jsonResponse(array('deleted' => true));
}

if ($method === 'POST' && count($segments) === 3 && $segments[0] === 'scans' && $segments[2] === 'quick') {
  $ip = validateIp($segments[1]);
  quickScan($ip);
}

if ($method === 'GET' && count($segments) === 3 && $segments[0] === 'scans' && $segments[2] === 'status') {
  $ip = validateIp($segments[1]);
  scanStatus($ip);
}

if ($method === 'GET' && count($segments) === 3 && $segments[0] === 'scans' && $segments[2] === 'history') {
  $ip = validateIp($segments[1]);
  scanHistory($ip);
}

if ($method === 'GET' && count($segments) === 3 && $segments[0] === 'scans') {
  $ip = validateIp($segments[1]);
  if (preg_match('/^(\d+)\.xml$/', $segments[2], $matches))
    streamScanById($ip, validateId($matches[1]));
  elseif (ctype_digit($segments[2]))
    scanJson($ip, validateId($segments[2]));
}

if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'scans') {
  if (str_ends_with($segments[1], '.xml'))
    streamScan($segments[1]);
  else
    scanJson(validateIp($segments[1]));
}

jsonError(404, 'not found');

function routeSegments(): array {
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $path = rawurldecode($path === false ? '/' : $path);

  if (str_starts_with($path, '/api.php')) {
    $path = substr($path, strlen('/api.php'));
  } elseif (str_starts_with($path, '/api')) {
    $path = substr($path, strlen('/api'));
  }

  $path = trim($path, '/');
  if ($path === '')
    return array();

  return array_values(array_filter(explode('/', $path), fn($part) => $part !== ''));
}

function requestBody(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '')
    return $_POST;

  $data = json_decode($raw, true);
  if (!is_array($data))
    jsonError(400, 'invalid json');

  return $data;
}

function requirePassword(array $body): void {
  $expected = (string)($GLOBALS['password'] ?? '');
  $given = (string)($body['password'] ?? '');
  if ($given !== $expected)
    jsonError(403, 'wrong password');
}

function normalizeHostIp($ip): ?string {
  $ip = trim((string)$ip);
  if ($ip === '')
    return null;
  if (strpos($ip, '.') === false)
    $ip = ($GLOBALS['network'] ?? '') . '.' . $ip;
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    jsonError(400, 'invalid ip');
  return $ip;
}

function normalizeEmpty($value): ?string {
  $value = trim((string)$value);
  return $value === '' ? null : $value;
}

function toDbFlag($value): ?string {
  if ($value === true || $value === 1 || $value === '1')
    return '1';
  return null;
}

function validateId(string $value): int {
  if (!ctype_digit($value))
    jsonError(400, 'invalid id');
  return (int)$value;
}

function validateIp(string $value): string {
  if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    jsonError(400, 'invalid ip');
  return $value;
}

function refreshPing(): void {
  $command = 'flock -n /tmp/ping.lck -c ' . escapeshellarg('/usr/bin/sudo /usr/bin/php ' . __DIR__ . '/cli.php ping');
  $output = array();
  $code = 0;
  exec($command . ' 2>&1', $output, $code);

  if ($code !== 0)
    jsonError(409, trim(implode("\n", $output)) ?: 'scan already running');

  jsonResponse(array('status' => 'complete'));
}

function reloadDhcpHosts(): string {
  $command = '/usr/bin/sudo /usr/bin/php ' . escapeshellarg(__DIR__ . '/cli.php') . ' hosts';
  $output = array();
  $code = 0;
  exec($command . ' 2>&1', $output, $code);
  if ($code !== 0)
    jsonError(500, trim(implode("\n", $output)) ?: 'dhcp reload failed');
  return implode("\n", $output);
}

function quickScan(string $ip): void {
  $lock = '/tmp/inv-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $ip) . '.lck';
  $scan = '/usr/bin/sudo /usr/bin/php ' . escapeshellarg(__DIR__ . '/cli.php') . ' inventory --quick ' . escapeshellarg($ip);
  $command = 'flock -n ' . escapeshellarg($lock) . ' -c ' . escapeshellarg($scan);
  $output = array();
  $code = 0;
  exec($command . ' 2>&1', $output, $code);

  if ($code !== 0) {
    $message = trim(implode("\n", $output));
    jsonError($message === '' ? 409 : 500, $message ?: 'scan already running');
  }

  $log = implode("\n", $output);
  jsonResponse(array(
    'saved' => strpos("\n" . $log . "\n", "\n" . $ip . " saved\n") !== false,
    'log' => $log,
    'metadata' => scanMetadataLatest($ip),
    'xml' => '/api/scans/' . rawurlencode($ip) . '.xml'
  ));
}

function scanStatus(string $ip): void {
  jsonResponse(scanMetadataLatest($ip) ?: array(
    'ip' => $ip,
    'state' => 'none',
    'ports_count' => 0
  ));
}

function scanHistory(string $ip): void {
  jsonResponse(scanMetadataHistory($ip));
}

function scanJson(string $ip, ?int $id = null): void {
  $metadata = $id === null ? scanMetadataLatest($ip) : scanMetadataById($ip, $id);
  if ($id !== null && $metadata === null)
    jsonError(404, 'scan not found');

  $xml = scanReadXml($ip, $metadata);
  if ($xml === null)
    jsonError(404, 'scan not found');

  $scan = scanParseXml($xml, $metadata ?: array('ip' => $ip));
  if ($metadata === null)
    $scan['metadata'] = null;
  jsonResponse($scan);
}

function streamScan(string $file): void {
  if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\.xml$/', $file, $matches))
    jsonError(404, 'scan not found');

  $ip = validateIp($matches[1]);
  $xml = scanReadXml($ip);
  if ($xml === null)
    jsonError(404, 'scan not found');

  header('Content-Type: application/xml; charset=utf-8');
  echo $xml;
  exit;
}

function streamScanById(string $ip, int $id): void {
  $metadata = scanMetadataById($ip, $id);
  if ($metadata === null)
    jsonError(404, 'scan not found');

  $xml = scanReadXml($ip, $metadata);
  if ($xml === null)
    jsonError(404, 'scan not found');

  header('Content-Type: application/xml; charset=utf-8');
  echo $xml;
  exit;
}

function jsonResponse($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

function jsonError(int $status, string $message): void {
  jsonResponse(array('error' => $message), $status);
}
