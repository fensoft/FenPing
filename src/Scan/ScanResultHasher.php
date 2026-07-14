<?php

declare(strict_types=1);

namespace FenPing\Scan;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class ScanResultHasher
{
public function semanticHash(array $scan): string {
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

public function contentHash(array $scan): string {
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
