<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

trait ScanProfileAndHashBehavior
{
public const SCAN_XSL_FROM = 'file:///usr/bin/../share/nmap/';
public const SCAN_XSL_LEGACY = '../res/xsl/';
public const SCAN_XSL_TO = '/res/xsl/';
public const SCAN_HISTORY_DAYS = 7;
public const SCAN_MANAGED_DEFAULT_PROFILE = 'standard';
public const SCAN_MANAGED_DEFAULT_INTERVAL_HOURS = 24;
public const SCAN_UNMANAGED_DEFAULT_PROFILE = 'lightweight';
public const SCAN_UNMANAGED_DEFAULT_INTERVAL_HOURS = 24;

public function scanProfiles(): array {
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

public function scanProfileIds(bool $includeLegacy = true): array {
  $ids = array_column($this->scanProfiles(), 'id');
  if ($includeLegacy)
    array_unshift($ids, 'quick');
  return $ids;
}

public function scanProfileIsValid(string $profile, bool $includeLegacy = true): bool {
  return in_array($profile, $this->scanProfileIds($includeLegacy), true);
}

public function scanProfileRank(string $profile): int {
  return match ($profile) {
    'quick', 'lightweight' => 1,
    'standard' => 2,
    'deep' => 3,
    default => 0
  };
}

public function scanProfileIsPartial(string $profile): bool {
  return $this->scanProfileRank($profile) > 0 && $profile !== 'deep';
}

public function scanProfileTimeout(string $profile): int {
  if ($profile === 'quick')
    $profile = 'lightweight';
  foreach ($this->scanProfiles() as $definition) {
    if ($definition['id'] === $profile)
      return (int)$definition['timeout_seconds'];
  }
  throw new InvalidArgumentException('invalid scan profile');
}

public function normalizeScheduledScanProfile($value): string {
  if (!is_scalar($value))
    throw new InvalidArgumentException('invalid scan profile');
  $profile = strtolower(trim((string)$value));
  if (!$this->scanProfileIsValid($profile, false))
    throw new InvalidArgumentException('invalid scan profile');
  return $profile;
}

public function normalizeScanIntervalHours($value): int {
  if (is_int($value))
    $hours = $value;
  elseif (is_string($value) && ctype_digit(trim($value)))
    $hours = (int)trim($value);
  else
    throw new InvalidArgumentException('invalid scan cadence');
  if ($hours < 0 || $hours > 8760)
    throw new InvalidArgumentException('scan cadence must be between 0 and 8760 hours');
  return $hours;
}

public function scanXmlUrl(string $ip, ?int $id = null): string {
  if ($id !== null)
    return '/api/scans/' . rawurlencode($ip) . '/' . $id . '.xml';
  return '/api/scans/' . rawurlencode($ip) . '.xml';
}

public function scanNormalizeXml(string $xml): string {
  return str_replace(
    array('href="' . self::SCAN_XSL_LEGACY, 'href="' . self::SCAN_XSL_FROM),
    array('href="' . self::SCAN_XSL_TO, 'href="' . self::SCAN_XSL_TO),
    $xml
  );
}

public function scanResultHash(array $scan): string {
  $signature = array(
    'ip' => (string)($scan['ip'] ?? ''),
    'status' => (string)($scan['status'] ?? ''),
    'addresses' => array_map(fn($address) => array(
      'addr' => (string)($address['addr'] ?? ''),
      'type' => (string)($address['type'] ?? ''),
      'vendor' => (string)($address['vendor'] ?? '')
    ), $scan['addresses'] ?? array()),
    'hostnames' => array_map(fn($hostname) => array(
      'name' => (string)($hostname['name'] ?? ''),
      'type' => (string)($hostname['type'] ?? '')
    ), $scan['hostnames'] ?? array()),
    'os' => array_map(fn($match) => array(
      'name' => (string)($match['name'] ?? ''),
      'accuracy' => (int)($match['accuracy'] ?? 0)
    ), $scan['os'] ?? array()),
    'port_scope' => $this->scanPortScopeSignature($scan['port_scope'] ?? array()),
    'ports' => array_map(fn($port) => array(
      'protocol' => (string)($port['protocol'] ?? ''),
      'port' => (int)($port['port'] ?? 0),
      'state' => (string)($port['state'] ?? ''),
      'service' => (string)($port['service'] ?? ''),
      'product' => (string)($port['product'] ?? ''),
      'version' => (string)($port['version'] ?? ''),
      'extra_info' => (string)($port['extra_info'] ?? ''),
      'tunnel' => (string)($port['tunnel'] ?? '')
    ), $scan['ports'] ?? array())
  );
  $this->scanSortRecursive($signature);
  return hash('sha256', json_encode($signature, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

public function scanPortScopeSignature(array $scope): array {
  $normalized = array();
  foreach ($scope as $protocol => $ranges) {
    foreach ($ranges as $range)
      $normalized[(string)$protocol][] = array((int)($range[0] ?? 0), (int)($range[1] ?? 0));
  }
  return $normalized;
}

public function scanContentHash(array $scan): string {
  $content = array(
    'addresses' => $scan['addresses'] ?? array(),
    'hostnames' => $scan['hostnames'] ?? array(),
    'port_scope' => $scan['port_scope'] ?? array(),
    'ports' => $scan['ports'] ?? array(),
    'extra_ports' => $scan['extra_ports'] ?? array(),
    'os_matches' => $scan['os_matches'] ?? array(),
    'scripts' => $scan['scripts'] ?? array(),
    'trace' => $scan['trace'] ?? array()
  );
  return hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

public function scanSortRecursive(&$value): void {
  if (!is_array($value))
    return;

  foreach ($value as &$item)
    $this->scanSortRecursive($item);
  unset($item);

  if ($this->scanArrayIsList($value)) {
    usort($value, function ($a, $b) {
      return strcmp(json_encode($a), json_encode($b));
    });
  } else {
    ksort($value);
  }
}

public function scanArrayIsList(array $array): bool {
  $i = 0;
  foreach (array_keys($array) as $key) {
    if ($key !== $i++)
      return false;
  }
  return true;
}

public function scanMetadataResultUsable(array $metadata): bool {
  if (array_key_exists('result_available', $metadata))
    return (bool)$metadata['result_available'];
  return (int)($metadata['snapshot_id'] ?? 0) > 0;
}

public function scanMetadataXmlUsable(array $metadata): bool {
  return $this->scanMetadataResultUsable($metadata);
}
}
