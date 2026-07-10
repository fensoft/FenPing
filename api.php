<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/hosts.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/scans.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/system.php';
require_once __DIR__ . '/routes/hosts.php';
require_once __DIR__ . '/routes/netboot.php';
require_once __DIR__ . '/routes/scans.php';

set_exception_handler(function (Throwable $e): void {
  jsonError(500, 'server error');
});

dispatchRequest();

function dispatchRequest(): void {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $segments = routeSegments();

  if (count($segments) === 0)
    jsonError(404, 'not found');

  $methodAllowed = false;
  $invalidError = null;

  foreach (apiRoutes() as $route) {
    $match = matchRoutePath($route['pattern'], $segments);

    if (!$match['matched']) {
      if ($match['invalid'] && $route['method'] === $method && $invalidError === null)
        $invalidError = $match['error'];
      continue;
    }

    if ($route['method'] !== $method) {
      $methodAllowed = true;
      continue;
    }

    authorizeRoute($route);
    $result = call_user_func($route['handler'], $match['params']);
    jsonResponse($result);
  }

  if ($methodAllowed)
    jsonError(405, 'method not allowed');

  if ($invalidError !== null)
    jsonError(400, $invalidError);

  jsonError(404, 'not found');
}

function apiRoutes(): array {
  return array_merge(
    authApiRoutes(),
    systemApiRoutes(),
    hostsApiRoutes(),
    netbootApiRoutes(),
    scansApiRoutes()
  );
}

function apiRoute(string $method, string $pattern, string $handler, $auth = false, array $options = array()): array {
  return array_merge(array(
    'method' => strtoupper($method),
    'pattern' => $pattern,
    'handler' => $handler,
    'auth' => $auth
  ), $options);
}

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

function matchRoutePath(string $pattern, array $segments): array {
  $pattern = trim($pattern, '/');
  $parts = $pattern === '' ? array() : explode('/', $pattern);

  if (count($parts) !== count($segments))
    return routeNoMatch();

  $params = array();
  foreach ($parts as $index => $part) {
    $segment = $segments[$index];
    if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([A-Za-z][A-Za-z0-9_]*))?\}$/', $part, $matches)) {
      $type = $matches[2] ?? 'string';
      $converted = convertRouteParam($type, $segment);
      if (!$converted['ok'])
        return routeInvalid($converted['error']);
      $params[$matches[1]] = $converted['value'];
      continue;
    }

    if ($part !== $segment)
      return routeNoMatch();
  }

  return array('matched' => true, 'invalid' => false, 'params' => $params, 'error' => null);
}

function routeNoMatch(): array {
  return array('matched' => false, 'invalid' => false, 'params' => array(), 'error' => null);
}

function routeInvalid(string $error): array {
  return array('matched' => false, 'invalid' => true, 'params' => array(), 'error' => $error);
}

function convertRouteParam(string $type, string $value): array {
  if ($type === 'int') {
    if (!ctype_digit($value))
      return routeParamError('invalid id');
    return routeParamValue((int)$value);
  }

  if ($type === 'ipv4') {
    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
      return routeParamError('invalid ip');
    return routeParamValue($value);
  }

  if ($type === 'scanXml') {
    if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\.xml$/', $value, $matches))
      return routeParamError('invalid scan file');
    if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
      return routeParamError('invalid ip');
    return routeParamValue($value);
  }

  if ($type === 'scanIdXml') {
    if (!preg_match('/^\d+\.xml$/', $value))
      return routeParamError('invalid scan id');
    return routeParamValue($value);
  }

  if ($value === '')
    return routeParamError('invalid value');

  return routeParamValue($value);
}

function routeParamValue($value): array {
  return array('ok' => true, 'value' => $value, 'error' => null);
}

function routeParamError(string $error): array {
  return array('ok' => false, 'value' => null, 'error' => $error);
}

function authorizeRoute(array $route): void {
  $auth = $route['auth'] ?? false;
  if ($auth === false)
    return;

  if ($auth === 'body') {
    requireAuth(requestBody());
    return;
  }

  requireAuth();
}

function requestBody(): array {
  static $body = null;

  if ($body !== null)
    return $body;

  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') {
    $body = $_POST;
    return $body;
  }

  $data = json_decode($raw, true);
  if (!is_array($data))
    jsonError(400, 'invalid json');

  $body = $data;
  return $body;
}

function requireAuth(?array $body = null): void {
  if (authIsAuthenticated())
    return;

  if ($body !== null && array_key_exists('password', $body) && authLogin($body['password']))
    return;

  jsonError(403, 'login required');
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

function normalizeNetbootImageId($value): ?int {
  if ($value === null || $value === '' || $value === false)
    return null;

  if (is_int($value))
    $id = $value;
  else {
    $value = trim((string)$value);
    if (!ctype_digit($value))
      jsonError(400, 'invalid netboot image');
    $id = (int)$value;
  }

  if ($id <= 0 || !netboot_image_exists($id))
    jsonError(400, 'invalid netboot image');

  return $id;
}

function reloadDhcpHosts(): string {
  try {
    return runDhcpHostsCli('');
  } catch (RuntimeException $e) {
    jsonError(500, $e->getMessage());
  }
}

function commitDhcpMutation(callable $mutation): array {
  $lock = acquireDnsmasqUpdateLock();
  $database = db();
  $candidateDir = dnsmasqPendingDir();
  $applied = false;

  try {
    if ($database->inTransaction())
      throw new RuntimeException('database transaction already active');

    clearDnsmasqCandidateDir($candidateDir);
    $database->beginTransaction();
    $result = $mutation();
    prepareDnsmasqCandidate(buildDnsmasqFiles(), $candidateDir);
    $log = runDhcpHostsCli('--apply-pending');
    $applied = true;
    if (!$database->commit())
      throw new RuntimeException('database commit failed');

    return array('result' => $result, 'log' => $log);
  } catch (Throwable $error) {
    $recoveryErrors = array();

    if ($database->inTransaction()) {
      try {
        $database->rollBack();
      } catch (Throwable $rollbackError) {
        $recoveryErrors[] = 'database rollback failed: ' . $rollbackError->getMessage();
      }
    }

    if ($applied) {
      try {
        runDhcpHostsCli('--sync-locked');
      } catch (Throwable $syncError) {
        $recoveryErrors[] = 'dnsmasq recovery failed: ' . $syncError->getMessage();
      }
    }

    if (count($recoveryErrors) !== 0) {
      throw new RuntimeException(
        'DHCP update failed: ' . $error->getMessage() . '; ' . implode('; ', $recoveryErrors),
        0,
        $error
      );
    }
    throw $error;
  } finally {
    clearDnsmasqCandidateDir($candidateDir);
    releaseDnsmasqUpdateLock($lock);
  }
}

function runDhcpHostsCli(string $mode): string {
  if (!in_array($mode, array('', '--apply-pending', '--sync-locked'), true))
    throw new InvalidArgumentException('invalid DHCP apply mode');

  $command = '/usr/bin/sudo /usr/bin/php ' . escapeshellarg(__DIR__ . '/cli.php') . ' hosts';
  if ($mode !== '')
    $command .= ' ' . escapeshellarg($mode);
  $output = array();
  $code = 0;
  exec($command . ' 2>&1', $output, $code);
  if ($code !== 0)
    throw new RuntimeException(trim(implode("\n", $output)) ?: 'DHCP apply failed');
  return implode("\n", $output);
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
