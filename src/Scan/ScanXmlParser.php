<?php

declare(strict_types=1);

namespace FenPing\Scan;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ScanXmlParser
{
public function parse(string $xml, ?array $metadata = null): array {
  $nmap = $this->scanFirstTagAttributes($xml, 'nmaprun');
  $host = $this->scanFirstBlock($xml, 'host') ?? '';
  $status = $this->scanFirstTagAttributes($host, 'status');
  $uptime = $this->scanFirstTagAttributes($host, 'uptime');
  $distance = $this->scanFirstTagAttributes($host, 'distance');
  $finished = $this->scanFirstTagAttributes($xml, 'finished');
  $ports = array_map([$this, 'scanParsePortBlock'], $this->scanBlocks($host, 'port'));
  $osMatches = $this->scanParseOsMatches($host);
  $hostscript = $this->scanFirstBlock($host, 'hostscript');
  $trace = $this->scanFirstBlock($host, 'trace');

  return array(
    'ip' => $metadata['ip'] ?? $this->scanPrimaryIp($host),
    'args' => $nmap['args'] ?? '',
    'scanner' => $nmap['scanner'] ?? '',
    'scanner_version' => $nmap['version'] ?? '',
    'started' => $nmap['startstr'] ?? '',
    'status' => $status['state'] ?? '',
    'status_reason' => $status['reason'] ?? '',
    'status_reason_ttl' => $this->scanNullableInt($status['reason_ttl'] ?? null),
    'uptime' => $uptime['lastboot'] ?? '',
    'uptime_seconds' => $this->scanNullableInt($uptime['seconds'] ?? null),
    'distance' => $this->scanNullableInt($distance['value'] ?? null),
    'duration' => $this->scanDuration($finished),
    'ports_count' => count($ports),
    'addresses' => $this->scanParseTags($host, 'address', function ($attributes) {
      return array(
        'addr' => $attributes['addr'] ?? '',
        'type' => $attributes['addrtype'] ?? '',
        'vendor' => $attributes['vendor'] ?? ''
      );
    }),
    'hostnames' => $this->scanParseTags($host, 'hostname', function ($attributes) {
      return array(
        'name' => $attributes['name'] ?? '',
        'type' => $attributes['type'] ?? ''
      );
    }),
    'os' => $this->scanSelectOsMatches($osMatches),
    'os_matches' => $osMatches,
    'ports' => $ports,
    'port_scope' => $this->scanParsePortScope($xml),
    'extra_ports' => $this->scanParseExtraPorts($host),
    'scripts' => $hostscript === null ? array() : $this->scanParseScripts($hostscript),
    'trace' => $trace === null ? array() : $this->scanParseTrace($trace),
    'metadata' => $metadata,
    'xml' => isset($metadata['ip'], $metadata['id']) ? $this->scanXmlUrl($metadata['ip'], $metadata['id']) : null
  );
}

public function scanNullableInt($value): ?int {
  return $value !== null && $value !== '' && is_numeric($value) ? (int)$value : null;
}

public function scanSelectOsMatches(array $matches): array {
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

public function scanPrimaryIp(string $host): string {
  foreach ($this->scanParseTags($host, 'address', fn($attributes) => $attributes) as $address) {
    if (($address['addrtype'] ?? '') === 'ipv4')
      return $address['addr'] ?? '';
  }
  return '';
}

public function scanDuration(array $finished): ?int {
  if (!isset($finished['elapsed']) || !is_numeric($finished['elapsed']))
    return null;
  return (int)ceil((float)$finished['elapsed']);
}

public function scanParsePortBlock(string $block): array {
  $port = $this->scanFirstTagAttributes($block, 'port');
  $state = $this->scanFirstTagAttributes($block, 'state');
  $service = $this->scanFirstTagAttributes($block, 'service');
  $details = implode(' ', array_filter(array(
    $service['product'] ?? '',
    $service['version'] ?? '',
    $service['extrainfo'] ?? ''
  )));

  return array(
    'protocol' => $port['protocol'] ?? '',
    'port' => $port['portid'] ?? '',
    'state' => $state['state'] ?? '',
    'reason' => $state['reason'] ?? '',
    'reason_ttl' => $this->scanNullableInt($state['reason_ttl'] ?? null),
    'service' => $service['name'] ?? '',
    'product' => $service['product'] ?? '',
    'version' => $service['version'] ?? '',
    'extra_info' => $service['extrainfo'] ?? '',
    'details' => $details,
    'tunnel' => $service['tunnel'] ?? '',
    'method' => $service['method'] ?? '',
    'confidence' => $this->scanNullableInt($service['conf'] ?? null),
    'os_type' => $service['ostype'] ?? '',
    'cpes' => $this->scanParseCpes($block),
    'scripts' => $this->scanParseScripts($block)
  );
}

public function scanParseCpes(string $xml): array {
  $cpes = array();
  if (preg_match_all('/<cpe\b[^>]*>(.*?)<\/cpe>/is', $xml, $matches)) {
    foreach ($matches[1] as $value)
      $cpes[] = html_entity_decode(trim(strip_tags($value)), ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
  return $cpes;
}

public function scanParseOsMatches(string $host): array {
  $matches = array();
  foreach ($this->scanTagBlocks($host, 'osmatch') as $block) {
    $attributes = $this->scanFirstTagAttributes($block, 'osmatch');
    $classes = array();
    foreach ($this->scanTagBlocks($block, 'osclass') as $classBlock) {
      $class = $this->scanFirstTagAttributes($classBlock, 'osclass');
      $classes[] = array(
        'vendor' => $class['vendor'] ?? '',
        'family' => $class['osfamily'] ?? '',
        'generation' => $class['osgen'] ?? '',
        'type' => $class['type'] ?? '',
        'accuracy' => $this->scanNullableInt($class['accuracy'] ?? null),
        'cpes' => $this->scanParseCpes($classBlock)
      );
    }
    $matches[] = array(
      'name' => $attributes['name'] ?? '',
      'accuracy' => $this->scanNullableInt($attributes['accuracy'] ?? null) ?? 0,
      'classes' => $classes
    );
  }
  return $matches;
}

public function scanParseExtraPorts(string $host): array {
  $items = array();
  foreach ($this->scanTagBlocks($host, 'extraports') as $block) {
    $attributes = $this->scanFirstTagAttributes($block, 'extraports');
    $reasons = $this->scanParseTags($block, 'extrareasons', function ($reason) {
      return array(
        'reason' => $reason['reason'] ?? '',
        'count' => $this->scanNullableInt($reason['count'] ?? null) ?? 0,
        'protocol' => $reason['proto'] ?? '',
        'ports' => $reason['ports'] ?? ''
      );
    });
    $items[] = array(
      'state' => $attributes['state'] ?? '',
      'count' => $this->scanNullableInt($attributes['count'] ?? null) ?? 0,
      'reasons' => $reasons
    );
  }
  return $items;
}

public function scanParseScripts(string $xml): array {
  $scripts = array();
  foreach ($this->scanTagBlocks($xml, 'script') as $block) {
    $attributes = $this->scanFirstTagAttributes($block, 'script');
    $scripts[] = array(
      'id' => $attributes['id'] ?? '',
      'output' => $attributes['output'] ?? '',
      'nodes' => $this->parseScriptNodes($block)
    );
  }
  return $scripts;
}

public function parseScriptNodes(string $script): array {
  $nodes = array();
  $stack = array();
  if (!preg_match_all('/<(table|elem)\b([^>]*)>|<\/(table|elem)>|([^<]+)/is', $script, $tokens, PREG_SET_ORDER))
    return $nodes;

  foreach ($tokens as $token) {
    if (($token[1] ?? '') !== '') {
      $parent = count($stack) === 0 ? null : $stack[count($stack) - 1];
      $attributes = $this->scanAttributes($token[2] ?? '');
      $index = count($nodes);
      $nodes[] = array(
        'parent' => $parent,
        'type' => strtolower($token[1]),
        'key' => $attributes['key'] ?? '',
        'value' => ''
      );
      if (!preg_match('/\/\s*$/', $token[2] ?? ''))
        $stack[] = $index;
    } elseif (($token[3] ?? '') !== '') {
      if (count($stack) !== 0)
        array_pop($stack);
    } elseif (count($stack) !== 0 && ($token[4] ?? '') !== '') {
      $index = $stack[count($stack) - 1];
      $nodes[$index]['value'] .= html_entity_decode($token[4], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
  }
  foreach ($nodes as &$node)
    $node['value'] = trim($node['value']);
  unset($node);
  return $nodes;
}

public function scanParseTrace(string $trace): array {
  $traceAttributes = $this->scanFirstTagAttributes($trace, 'trace');
  return $this->scanParseTags($trace, 'hop', function ($hop) use ($traceAttributes) {
    return array(
      'protocol' => $traceAttributes['proto'] ?? '',
      'port' => $this->scanNullableInt($traceAttributes['port'] ?? null),
      'ttl' => $this->scanNullableInt($hop['ttl'] ?? null) ?? 0,
      'ip' => $hop['ipaddr'] ?? '',
      'hostname' => $hop['host'] ?? '',
      'rtt' => isset($hop['rtt']) && is_numeric($hop['rtt']) ? (float)$hop['rtt'] : null
    );
  });
}

public function scanParsePortScope(string $xml): array {
  $scope = array();
  $scanInfo = $this->scanParseTags($xml, 'scaninfo', fn($attributes) => $attributes);

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

public function scanParseTags(string $xml, string $tag, callable $map): array {
  $items = array();
  if (preg_match_all('/<' . preg_quote($tag, '/') . '\b([^>]*)\/?>/i', $xml, $matches)) {
    foreach ($matches[1] as $attributes)
      $items[] = $map($this->scanAttributes($attributes));
  }
  return $items;
}

public function scanFirstTagAttributes(string $xml, string $tag): array {
  if (!preg_match('/<' . preg_quote($tag, '/') . '\b([^>]*)>/i', $xml, $matches))
    return array();
  return $this->scanAttributes($matches[1]);
}

public function scanFirstBlock(string $xml, string $tag): ?string {
  if (!preg_match('/<' . preg_quote($tag, '/') . '\b[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/is', $xml, $matches))
    return null;
  return $matches[0];
}

public function scanBlocks(string $xml, string $tag): array {
  if (!preg_match_all('/<' . preg_quote($tag, '/') . '\b[^>]*>.*?<\/' . preg_quote($tag, '/') . '>/is', $xml, $matches))
    return array();
  return $matches[0];
}

public function scanTagBlocks(string $xml, string $tag): array {
  $blocks = $this->scanBlocks($xml, $tag);
  $remaining = str_replace($blocks, '', $xml);
  if (preg_match_all('/<' . preg_quote($tag, '/') . '\b[^>]*\/\s*>/is', $remaining, $matches))
    $blocks = array_merge($blocks, $matches[0]);
  return $blocks;
}

public function scanAttributes(string $attributes): array {
  $result = array();
  if (preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $attributes, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match)
      $result[$match[1]] = html_entity_decode($match[3], ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
  return $result;
}
private function scanXmlUrl(string $ip, ?int $id = null): string {
  return $id === null
    ? '/api/scans/' . rawurlencode($ip) . '.xml'
    : '/api/scans/' . rawurlencode($ip) . '/' . $id . '.xml';
}
}
