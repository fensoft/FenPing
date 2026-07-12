<?php

const SCAN_XSL_FROM = 'file:///usr/bin/../share/nmap/';
const SCAN_XSL_LEGACY = '../res/xsl/';
const SCAN_XSL_TO = '/res/xsl/';
const SCAN_HISTORY_DAYS = 7;
const SCAN_MANAGED_DEFAULT_PROFILE = 'standard';
const SCAN_MANAGED_DEFAULT_INTERVAL_HOURS = 24;
const SCAN_UNMANAGED_DEFAULT_PROFILE = 'lightweight';
const SCAN_UNMANAGED_DEFAULT_INTERVAL_HOURS = 24;

function scanProfiles(): array {
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

function scanProfileIds(bool $includeLegacy = true): array {
  $ids = array_column(scanProfiles(), 'id');
  if ($includeLegacy)
    array_unshift($ids, 'quick');
  return $ids;
}

function scanProfileIsValid(string $profile, bool $includeLegacy = true): bool {
  return in_array($profile, scanProfileIds($includeLegacy), true);
}

function scanProfileRank(string $profile): int {
  return match ($profile) {
    'quick', 'lightweight' => 1,
    'standard' => 2,
    'deep' => 3,
    default => 0
  };
}

function scanProfileIsPartial(string $profile): bool {
  return scanProfileRank($profile) > 0 && $profile !== 'deep';
}

function scanProfileTimeout(string $profile): int {
  if ($profile === 'quick')
    $profile = 'lightweight';
  foreach (scanProfiles() as $definition) {
    if ($definition['id'] === $profile)
      return (int)$definition['timeout_seconds'];
  }
  throw new InvalidArgumentException('invalid scan profile');
}

function normalizeScheduledScanProfile($value): string {
  if (!is_scalar($value))
    throw new InvalidArgumentException('invalid scan profile');
  $profile = strtolower(trim((string)$value));
  if (!scanProfileIsValid($profile, false))
    throw new InvalidArgumentException('invalid scan profile');
  return $profile;
}

function normalizeScanIntervalHours($value): int {
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

function scanXmlUrl(string $ip, ?int $id = null): string {
  if ($id !== null)
    return '/api/scans/' . rawurlencode($ip) . '/' . $id . '.xml';
  return '/api/scans/' . rawurlencode($ip) . '.xml';
}

function scanNormalizeXml(string $xml): string {
  return str_replace(
    array('href="' . SCAN_XSL_LEGACY, 'href="' . SCAN_XSL_FROM),
    array('href="' . SCAN_XSL_TO, 'href="' . SCAN_XSL_TO),
    $xml
  );
}

function scanResultHash(array $scan): string {
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
    'port_scope' => scanPortScopeSignature($scan['port_scope'] ?? array()),
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
  scanSortRecursive($signature);
  return hash('sha256', json_encode($signature, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function scanPortScopeSignature(array $scope): array {
  $normalized = array();
  foreach ($scope as $protocol => $ranges) {
    foreach ($ranges as $range)
      $normalized[(string)$protocol][] = array((int)($range[0] ?? 0), (int)($range[1] ?? 0));
  }
  return $normalized;
}

function scanContentHash(array $scan): string {
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

function scanMetadataResultUsable(array $metadata): bool {
  if (array_key_exists('result_available', $metadata))
    return (bool)$metadata['result_available'];
  return (int)($metadata['snapshot_id'] ?? 0) > 0;
}

function scanMetadataXmlUsable(array $metadata): bool {
  return scanMetadataResultUsable($metadata);
}

function scanParseXml(string $xml, ?array $metadata = null): array {
  $nmap = scanFirstTagAttributes($xml, 'nmaprun');
  $host = scanFirstBlock($xml, 'host') ?? '';
  $status = scanFirstTagAttributes($host, 'status');
  $uptime = scanFirstTagAttributes($host, 'uptime');
  $distance = scanFirstTagAttributes($host, 'distance');
  $finished = scanFirstTagAttributes($xml, 'finished');
  $ports = array_map('scanParsePortBlock', scanBlocks($host, 'port'));
  $osMatches = scanParseOsMatches($host);
  $hostscript = scanFirstBlock($host, 'hostscript');
  $trace = scanFirstBlock($host, 'trace');

  return array(
    'ip' => $metadata['ip'] ?? scanPrimaryIp($host),
    'args' => $nmap['args'] ?? '',
    'scanner' => $nmap['scanner'] ?? '',
    'scanner_version' => $nmap['version'] ?? '',
    'started' => $nmap['startstr'] ?? '',
    'status' => $status['state'] ?? '',
    'status_reason' => $status['reason'] ?? '',
    'status_reason_ttl' => scanNullableInt($status['reason_ttl'] ?? null),
    'uptime' => $uptime['lastboot'] ?? '',
    'uptime_seconds' => scanNullableInt($uptime['seconds'] ?? null),
    'distance' => scanNullableInt($distance['value'] ?? null),
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
    'os' => scanSelectOsMatches($osMatches),
    'os_matches' => $osMatches,
    'ports' => $ports,
    'port_scope' => scanParsePortScope($xml),
    'extra_ports' => scanParseExtraPorts($host),
    'scripts' => $hostscript === null ? array() : scanParseScripts($hostscript),
    'trace' => $trace === null ? array() : scanParseTrace($trace),
    'metadata' => $metadata,
    'xml' => isset($metadata['ip'], $metadata['id']) ? scanXmlUrl($metadata['ip'], $metadata['id']) : null
  );
}

function scanNullableInt($value): ?int {
  return $value !== null && $value !== '' && is_numeric($value) ? (int)$value : null;
}

function scanSelectOsMatches(array $matches): array {
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
    'reason' => $state['reason'] ?? '',
    'reason_ttl' => scanNullableInt($state['reason_ttl'] ?? null),
    'service' => $service['name'] ?? '',
    'product' => $service['product'] ?? '',
    'version' => $service['version'] ?? '',
    'extra_info' => $service['extrainfo'] ?? '',
    'details' => $details,
    'tunnel' => $service['tunnel'] ?? '',
    'method' => $service['method'] ?? '',
    'confidence' => scanNullableInt($service['conf'] ?? null),
    'os_type' => $service['ostype'] ?? '',
    'cpes' => scanParseCpes($block),
    'scripts' => scanParseScripts($block)
  );
}

function scanParseCpes(string $xml): array {
  $cpes = array();
  if (preg_match_all('/<cpe\b[^>]*>(.*?)<\/cpe>/is', $xml, $matches)) {
    foreach ($matches[1] as $value)
      $cpes[] = html_entity_decode(trim(strip_tags($value)), ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
  return $cpes;
}

function scanParseOsMatches(string $host): array {
  $matches = array();
  foreach (scanTagBlocks($host, 'osmatch') as $block) {
    $attributes = scanFirstTagAttributes($block, 'osmatch');
    $classes = array();
    foreach (scanTagBlocks($block, 'osclass') as $classBlock) {
      $class = scanFirstTagAttributes($classBlock, 'osclass');
      $classes[] = array(
        'vendor' => $class['vendor'] ?? '',
        'family' => $class['osfamily'] ?? '',
        'generation' => $class['osgen'] ?? '',
        'type' => $class['type'] ?? '',
        'accuracy' => scanNullableInt($class['accuracy'] ?? null),
        'cpes' => scanParseCpes($classBlock)
      );
    }
    $matches[] = array(
      'name' => $attributes['name'] ?? '',
      'accuracy' => scanNullableInt($attributes['accuracy'] ?? null) ?? 0,
      'classes' => $classes
    );
  }
  return $matches;
}

function scanParseExtraPorts(string $host): array {
  $items = array();
  foreach (scanTagBlocks($host, 'extraports') as $block) {
    $attributes = scanFirstTagAttributes($block, 'extraports');
    $reasons = scanParseTags($block, 'extrareasons', function ($reason) {
      return array(
        'reason' => $reason['reason'] ?? '',
        'count' => scanNullableInt($reason['count'] ?? null) ?? 0,
        'protocol' => $reason['proto'] ?? '',
        'ports' => $reason['ports'] ?? ''
      );
    });
    $items[] = array(
      'state' => $attributes['state'] ?? '',
      'count' => scanNullableInt($attributes['count'] ?? null) ?? 0,
      'reasons' => $reasons
    );
  }
  return $items;
}

function scanParseScripts(string $xml): array {
  $scripts = array();
  foreach (scanTagBlocks($xml, 'script') as $block) {
    $attributes = scanFirstTagAttributes($block, 'script');
    $scripts[] = array(
      'id' => $attributes['id'] ?? '',
      'output' => $attributes['output'] ?? '',
      'nodes' => scanParseScriptNodes($block)
    );
  }
  return $scripts;
}

function scanParseScriptNodes(string $script): array {
  $nodes = array();
  $stack = array();
  if (!preg_match_all('/<(table|elem)\b([^>]*)>|<\/(table|elem)>|([^<]+)/is', $script, $tokens, PREG_SET_ORDER))
    return $nodes;

  foreach ($tokens as $token) {
    if (($token[1] ?? '') !== '') {
      $parent = count($stack) === 0 ? null : $stack[count($stack) - 1];
      $attributes = scanAttributes($token[2] ?? '');
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

function scanParseTrace(string $trace): array {
  $traceAttributes = scanFirstTagAttributes($trace, 'trace');
  return scanParseTags($trace, 'hop', function ($hop) use ($traceAttributes) {
    return array(
      'protocol' => $traceAttributes['proto'] ?? '',
      'port' => scanNullableInt($traceAttributes['port'] ?? null),
      'ttl' => scanNullableInt($hop['ttl'] ?? null) ?? 0,
      'ip' => $hop['ipaddr'] ?? '',
      'hostname' => $hop['host'] ?? '',
      'rtt' => isset($hop['rtt']) && is_numeric($hop['rtt']) ? (float)$hop['rtt'] : null
    );
  });
}

function scanParsePortScope(string $xml): array {
  $scope = array();
  $scanInfo = scanParseTags($xml, 'scaninfo', fn($attributes) => $attributes);

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

function scanTagBlocks(string $xml, string $tag): array {
  $blocks = scanBlocks($xml, $tag);
  $remaining = str_replace($blocks, '', $xml);
  if (preg_match_all('/<' . preg_quote($tag, '/') . '\b[^>]*\/\s*>/is', $remaining, $matches))
    $blocks = array_merge($blocks, $matches[0]);
  return $blocks;
}

function scanAttributes(string $attributes): array {
  $result = array();
  if (preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $attributes, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match)
      $result[$match[1]] = html_entity_decode($match[3], ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
  return $result;
}

function scanRenderXml(array $scan): string {
  $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  $xml .= '<?xml-stylesheet href="' . SCAN_XSL_TO . 'nmap.xsl" type="text/xsl"?>' . "\n";
  $xml .= '<nmaprun' . scanXmlAttributes(array(
    'scanner' => $scan['scanner'] ?? 'nmap',
    'args' => $scan['args'] ?? '',
    'version' => $scan['scanner_version'] ?? '',
    'startstr' => $scan['started'] ?? ''
  )) . ">\n";
  foreach ($scan['port_scope'] ?? array() as $protocol => $ranges) {
    $services = implode(',', array_map(fn($range) => $range[0] === $range[1] ? (string)$range[0] : $range[0] . '-' . $range[1], $ranges));
    $xml .= '  <scaninfo' . scanXmlAttributes(array('type' => 'syn', 'protocol' => $protocol, 'services' => $services)) . "/>\n";
  }
  $xml .= "  <host>\n";
  $xml .= '    <status' . scanXmlAttributes(array('state' => $scan['status'] ?? '', 'reason' => $scan['status_reason'] ?? '', 'reason_ttl' => $scan['status_reason_ttl'] ?? null)) . "/>\n";
  foreach ($scan['addresses'] ?? array() as $address)
    $xml .= '    <address' . scanXmlAttributes(array('addr' => $address['addr'] ?? '', 'addrtype' => $address['type'] ?? '', 'vendor' => $address['vendor'] ?? '')) . "/>\n";
  if (count($scan['hostnames'] ?? array()) !== 0) {
    $xml .= "    <hostnames>\n";
    foreach ($scan['hostnames'] as $hostname)
      $xml .= '      <hostname' . scanXmlAttributes(array('name' => $hostname['name'] ?? '', 'type' => $hostname['type'] ?? '')) . "/>\n";
    $xml .= "    </hostnames>\n";
  }
  $xml .= "    <ports>\n";
  foreach ($scan['extra_ports'] ?? array() as $extra) {
    $xml .= '      <extraports' . scanXmlAttributes(array('state' => $extra['state'] ?? '', 'count' => $extra['count'] ?? 0)) . ">\n";
    foreach ($extra['reasons'] ?? array() as $reason)
      $xml .= '        <extrareasons' . scanXmlAttributes(array('reason' => $reason['reason'] ?? '', 'count' => $reason['count'] ?? 0, 'proto' => $reason['protocol'] ?? '', 'ports' => $reason['ports'] ?? '')) . "/>\n";
    $xml .= "      </extraports>\n";
  }
  foreach ($scan['ports'] ?? array() as $port) {
    $xml .= '      <port' . scanXmlAttributes(array('protocol' => $port['protocol'] ?? '', 'portid' => $port['port'] ?? 0)) . ">\n";
    $xml .= '        <state' . scanXmlAttributes(array('state' => $port['state'] ?? '', 'reason' => $port['reason'] ?? '', 'reason_ttl' => $port['reason_ttl'] ?? null)) . "/>\n";
    $serviceAttributes = array(
      'name' => $port['service'] ?? '', 'product' => $port['product'] ?? '',
      'version' => $port['version'] ?? '', 'extrainfo' => $port['extra_info'] ?? '',
      'tunnel' => $port['tunnel'] ?? '', 'method' => $port['method'] ?? '',
      'conf' => $port['confidence'] ?? null, 'ostype' => $port['os_type'] ?? ''
    );
    if (count($port['cpes'] ?? array()) === 0) {
      $xml .= '        <service' . scanXmlAttributes($serviceAttributes) . "/>\n";
    } else {
      $xml .= '        <service' . scanXmlAttributes($serviceAttributes) . ">\n";
      foreach ($port['cpes'] as $cpe)
        $xml .= '          <cpe>' . scanXmlText($cpe) . "</cpe>\n";
      $xml .= "        </service>\n";
    }
    foreach ($port['scripts'] ?? array() as $script)
      $xml .= scanRenderScriptXml($script, '        ');
    $xml .= "      </port>\n";
  }
  $xml .= "    </ports>\n";
  if (count($scan['os_matches'] ?? array()) !== 0) {
    $xml .= "    <os>\n";
    foreach ($scan['os_matches'] as $match) {
      $xml .= '      <osmatch' . scanXmlAttributes(array('name' => $match['name'] ?? '', 'accuracy' => $match['accuracy'] ?? 0)) . ">\n";
      foreach ($match['classes'] ?? array() as $class) {
        $xml .= '        <osclass' . scanXmlAttributes(array(
          'vendor' => $class['vendor'] ?? '', 'osfamily' => $class['family'] ?? '',
          'osgen' => $class['generation'] ?? '', 'type' => $class['type'] ?? '',
          'accuracy' => $class['accuracy'] ?? null
        )) . ">\n";
        foreach ($class['cpes'] ?? array() as $cpe)
          $xml .= '          <cpe>' . scanXmlText($cpe) . "</cpe>\n";
        $xml .= "        </osclass>\n";
      }
      $xml .= "      </osmatch>\n";
    }
    $xml .= "    </os>\n";
  }
  if (($scan['uptime'] ?? '') !== '' || ($scan['uptime_seconds'] ?? null) !== null)
    $xml .= '    <uptime' . scanXmlAttributes(array('seconds' => $scan['uptime_seconds'] ?? null, 'lastboot' => $scan['uptime'] ?? '')) . "/>\n";
  if (($scan['distance'] ?? null) !== null)
    $xml .= '    <distance' . scanXmlAttributes(array('value' => $scan['distance'])) . "/>\n";
  if (count($scan['scripts'] ?? array()) !== 0) {
    $xml .= "    <hostscript>\n";
    foreach ($scan['scripts'] as $script)
      $xml .= scanRenderScriptXml($script, '      ');
    $xml .= "    </hostscript>\n";
  }
  if (count($scan['trace'] ?? array()) !== 0) {
    $first = $scan['trace'][0];
    $xml .= '    <trace' . scanXmlAttributes(array('proto' => $first['protocol'] ?? '', 'port' => $first['port'] ?? null)) . ">\n";
    foreach ($scan['trace'] as $hop)
      $xml .= '      <hop' . scanXmlAttributes(array('ttl' => $hop['ttl'] ?? 0, 'ipaddr' => $hop['ip'] ?? '', 'host' => $hop['hostname'] ?? '', 'rtt' => $hop['rtt'] ?? null)) . "/>\n";
    $xml .= "    </trace>\n";
  }
  $xml .= "  </host>\n";
  $xml .= '  <runstats><finished' . scanXmlAttributes(array('elapsed' => $scan['duration'] ?? null, 'exit' => 'success')) . "/></runstats>\n";
  return $xml . "</nmaprun>\n";
}

function scanRenderScriptXml(array $script, string $indent): string {
  $xml = $indent . '<script' . scanXmlAttributes(array('id' => $script['id'] ?? '', 'output' => $script['output'] ?? ''));
  if (count($script['nodes'] ?? array()) === 0)
    return $xml . "/>\n";
  $xml .= ">\n";
  $children = array();
  foreach ($script['nodes'] as $index => $node) {
    $parent = $node['parent'] ?? null;
    $children[$parent === null ? 'root' : (string)$parent][] = $index;
  }
  foreach ($children['root'] ?? array() as $index)
    $xml .= scanRenderScriptNodeXml($script['nodes'], $children, $index, $indent . '  ');
  return $xml . $indent . "</script>\n";
}

function scanRenderScriptNodeXml(array $nodes, array $children, int $index, string $indent): string {
  $node = $nodes[$index];
  $type = ($node['type'] ?? '') === 'table' ? 'table' : 'elem';
  $xml = $indent . '<' . $type . scanXmlAttributes(array('key' => $node['key'] ?? ''));
  $childIndexes = $children[(string)$index] ?? array();
  $value = (string)($node['value'] ?? '');
  if (count($childIndexes) === 0 && $value === '')
    return $xml . "/>\n";
  $xml .= '>';
  if ($value !== '')
    $xml .= scanXmlText($value);
  if (count($childIndexes) !== 0) {
    $xml .= "\n";
    foreach ($childIndexes as $child)
      $xml .= scanRenderScriptNodeXml($nodes, $children, $child, $indent . '  ');
    $xml .= $indent;
  }
  return $xml . '</' . $type . ">\n";
}

function scanXmlAttributes(array $attributes): string {
  $xml = '';
  foreach ($attributes as $name => $value) {
    if ($value === null || $value === '')
      continue;
    $xml .= ' ' . $name . '="' . scanXmlText((string)$value) . '"';
  }
  return $xml;
}

function scanXmlText(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function scanMetadataEnqueue(string $ip, string $mode): array {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new InvalidArgumentException('invalid scan ip');
  if (!scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $database = db();
  dbBeginImmediate($database);
  try {
    $stmt = $database->prepare("
      SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
      FROM scans
      WHERE ip=:ip AND state IN ('queued', 'running')
      ORDER BY CASE state WHEN 'running' THEN 0 ELSE 1 END, id DESC
    ");
    $stmt->execute(array('ip' => $ip));
    $activeJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requestedRank = scanProfileRank($mode);
    $covering = null;
    foreach ($activeJobs as $active) {
      if (scanProfileRank((string)$active['mode']) < $requestedRank)
        continue;
      if ($covering === null || scanProfileRank((string)$active['mode']) > scanProfileRank((string)$covering['mode']))
        $covering = $active;
    }
    if ($covering !== null) {
      dbCommit($database);
      return array('metadata' => scanNormalizeMetadata($covering), 'created' => false);
    }

    foreach ($activeJobs as $active) {
      if ($active['state'] !== 'queued' || scanProfileRank((string)$active['mode']) >= $requestedRank)
        continue;
      $update = $database->prepare("UPDATE scans SET mode=:mode WHERE id=:id AND state='queued'");
      $update->execute(array('mode' => $mode, 'id' => $active['id']));
      if ($update->rowCount() === 1) {
        $active['mode'] = $mode;
        dbCommit($database);
        return array('metadata' => scanNormalizeMetadata($active), 'created' => false);
      }
    }

    $insert = $database->prepare("
      INSERT INTO scans (ip, mode, state, date_begin, ports_count)
      VALUES (:ip, :mode, 'queued', NULL, 0)
    ");
    $insert->execute(array('ip' => $ip, 'mode' => $mode));
    $metadata = scanMetadataJobById((int)$database->lastInsertId());
    if ($metadata === null)
      throw new RuntimeException('failed to read queued scan');
    dbCommit($database);
    return array('metadata' => $metadata, 'created' => true);
  } catch (Throwable $error) {
    dbRollback($database);
    throw $error;
  }
}

function scanMetadataJobById(int $id): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE id=:id
    LIMIT 1
  ");
  $stmt->execute(array('id' => $id));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataClaimQueued(int $concurrency): array {
  $concurrency = max(1, min(20, $concurrency));
  $database = db();
  dbBeginImmediate($database);
  try {
    $running = (int)$database->query("SELECT COUNT(*) FROM scans WHERE state='running'")->fetchColumn();
    $limit = max(0, $concurrency - $running);
    if ($limit === 0) {
      dbCommit($database);
      return array();
    }

    $stmt = $database->prepare("
      SELECT queued.id
      FROM scans queued
      WHERE queued.state='queued'
        AND NOT EXISTS (
          SELECT 1
          FROM scans running
          WHERE running.ip=queued.ip AND running.state='running'
        )
      ORDER BY CASE queued.mode
        WHEN 'quick' THEN 0
        WHEN 'lightweight' THEN 0
        WHEN 'standard' THEN 1
        ELSE 2
      END, queued.id ASC
      LIMIT $limit
    ");
    $stmt->execute();

    $jobs = array();
    $update = $database->prepare("
      UPDATE scans
      SET state='running', date_begin=CURRENT_TIMESTAMP, date_end=NULL, duration=NULL, error=NULL
      WHERE id=:id AND state='queued'
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
      $update->execute(array('id' => $id));
      if ($update->rowCount() !== 1)
        continue;
      $job = scanMetadataJobById((int)$id);
      if ($job !== null)
        $jobs[] = $job;
    }
    dbCommit($database);
    return $jobs;
  } catch (Throwable $error) {
    dbRollback($database);
    throw $error;
  }
}

function scanMetadataQueuedCount(): int {
  return (int)db()->query("SELECT COUNT(*) FROM scans WHERE state='queued'")->fetchColumn();
}

function scanMetadataRunningCount(): int {
  return (int)db()->query("SELECT COUNT(*) FROM scans WHERE state='running'")->fetchColumn();
}

function scanMetadataExpireStaleRunning(int $maxSeconds): int {
  $maxSeconds = max(60, $maxSeconds);
  $stmt = db()->prepare("
    UPDATE scans
    SET state='timeout',
        date_end=CURRENT_TIMESTAMP,
        duration=CASE WHEN date_begin IS NULL THEN NULL ELSE MAX(0, unixepoch(CURRENT_TIMESTAMP)-unixepoch(date_begin)) END,
        error=COALESCE(NULLIF(error, ''), 'scan worker stopped before completion')
    WHERE state='running'
      AND (date_begin IS NULL OR date_begin <= datetime('now', '-$maxSeconds seconds'))
  ");
  $stmt->execute();
  return $stmt->rowCount();
}

function scanMetadataStart(string $ip, string $mode): int {
  $stmt = db()->prepare("
    INSERT INTO scans (ip, mode, state, date_begin, ports_count)
    VALUES (:ip, :mode, 'running', CURRENT_TIMESTAMP, 0)
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode));
  return (int)db()->lastInsertId();
}

function scanMetadataComplete(int $id, array $scan): bool {
  $database = db();
  dbBeginImmediate($database);

  try {
    $job = scanMetadataRawById($id, true);
    if ($job === null)
      throw new RuntimeException("scan job $id not found");

    $snapshotId = null;
    $changed = 0;
    if (($scan['status'] ?? '') === 'up') {
      $snapshot = scanEnsureSnapshot($job, $scan);
      $snapshotId = $snapshot['id'];
      $changed = $snapshot['changed'] ? 1 : 0;
      scanRecordPortChanges($job, $scan);
    }

    $stmt = $database->prepare("
      UPDATE scans
      SET state='complete',
          status=:status,
          date_end=CURRENT_TIMESTAMP,
          duration=:duration,
          ports_count=:ports_count,
          snapshot_id=:snapshot_id,
          result_changed=:result_changed,
          port_changes_processed=1,
          scanner=:scanner,
          scanner_version=:scanner_version,
          scan_args=:scan_args,
          host_reason=:host_reason,
          host_reason_ttl=:host_reason_ttl,
          last_boot=:last_boot,
          uptime_seconds=:uptime_seconds,
          distance=:distance,
          error=NULL
      WHERE id=:id
    ");
    $stmt->execute(array(
      'id' => $id,
      'status' => $scan['status'] ?: 'unknown',
      'duration' => $scan['duration'],
      'ports_count' => count($scan['ports'] ?? array()),
      'snapshot_id' => $snapshotId,
      'result_changed' => $changed,
      'scanner' => scanNullIfEmpty((string)($scan['scanner'] ?? '')),
      'scanner_version' => scanNullIfEmpty((string)($scan['scanner_version'] ?? '')),
      'scan_args' => scanNullIfEmpty((string)($scan['args'] ?? '')),
      'host_reason' => scanNullIfEmpty((string)($scan['status_reason'] ?? '')),
      'host_reason_ttl' => $scan['status_reason_ttl'] ?? null,
      'last_boot' => scanNormalizeDate((string)($scan['uptime'] ?? '')),
      'uptime_seconds' => $scan['uptime_seconds'] ?? null,
      'distance' => $scan['distance'] ?? null
    ));
    dbCommit($database);
    return $changed === 1;
  } catch (Throwable $e) {
    dbRollback($database);
    throw $e;
  }
}

function scanNormalizeDate(string $value): ?string {
  $value = trim($value);
  if ($value === '')
    return null;
  $timestamp = strtotime($value);
  return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

function scanMetadataRawById(int $id, bool $forUpdate = false): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE id=:id
    LIMIT 1
  ");
  $stmt->execute(array('id' => $id));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row === false ? null : $row;
}

function scanEnsureSnapshot(array $job, array $scan): array {
  $resultHash = scanResultHash($scan);
  $contentHash = scanContentHash($scan);
  $previous = db()->prepare("
    SELECT s.snapshot_id, ss.result_hash
    FROM scans s
    INNER JOIN scan_snapshots ss ON ss.id=s.snapshot_id
    WHERE s.ip=:ip
      AND s.mode=:mode
      AND s.id<:id
      AND s.state='complete'
    ORDER BY s.id DESC
    LIMIT 1
  ");
  $previous->execute(array('ip' => $job['ip'], 'mode' => $job['mode'], 'id' => $job['id']));
  $previousRow = $previous->fetch(PDO::FETCH_ASSOC);

  $insert = db()->prepare("
    INSERT OR IGNORE INTO scan_snapshots (ip, mode, result_hash, content_hash)
    VALUES (:ip, :mode, :result_hash, :content_hash)
  ");
  $insert->execute(array(
    'ip' => $job['ip'],
    'mode' => $job['mode'],
    'result_hash' => $resultHash,
    'content_hash' => $contentHash
  ));
  $inserted = $insert->rowCount() === 1;

  $find = db()->prepare("
    SELECT id
    FROM scan_snapshots
    WHERE ip=:ip AND mode=:mode AND content_hash=:content_hash
    LIMIT 1
  ");
  $find->execute(array('ip' => $job['ip'], 'mode' => $job['mode'], 'content_hash' => $contentHash));
  $snapshotId = (int)$find->fetchColumn();
  if ($snapshotId <= 0)
    throw new RuntimeException('failed to persist scan snapshot');
  if ($inserted)
    scanPersistSnapshot($snapshotId, $scan);

  return array(
    'id' => $snapshotId,
    'changed' => $previousRow === false || !hash_equals((string)$previousRow['result_hash'], $resultHash)
  );
}

function scanPersistSnapshot(int $snapshotId, array $scan): void {
  $scopeInsert = db()->prepare("INSERT INTO scan_snapshot_scopes (snapshot_id, protocol, port_begin, port_end) VALUES (:snapshot_id, :protocol, :port_begin, :port_end)");
  foreach ($scan['port_scope'] ?? array() as $protocol => $ranges) {
    foreach ($ranges as $range)
      $scopeInsert->execute(array('snapshot_id' => $snapshotId, 'protocol' => $protocol, 'port_begin' => $range[0], 'port_end' => $range[1]));
  }

  $addressInsert = db()->prepare("INSERT INTO scan_snapshot_addresses (snapshot_id, position, address, address_type, vendor) VALUES (:snapshot_id, :position, :address, :address_type, :vendor)");
  foreach ($scan['addresses'] ?? array() as $position => $address) {
    $addressInsert->execute(array(
      'snapshot_id' => $snapshotId, 'position' => $position,
      'address' => (string)($address['addr'] ?? ''), 'address_type' => (string)($address['type'] ?? ''),
      'vendor' => scanNullIfEmpty((string)($address['vendor'] ?? ''))
    ));
  }

  $hostnameInsert = db()->prepare("INSERT INTO scan_snapshot_hostnames (snapshot_id, position, hostname, hostname_type) VALUES (:snapshot_id, :position, :hostname, :hostname_type)");
  foreach ($scan['hostnames'] ?? array() as $position => $hostname) {
    $hostnameInsert->execute(array(
      'snapshot_id' => $snapshotId, 'position' => $position,
      'hostname' => (string)($hostname['name'] ?? ''), 'hostname_type' => (string)($hostname['type'] ?? '')
    ));
  }

  $portInsert = db()->prepare("
    INSERT INTO scan_snapshot_ports (
      snapshot_id, protocol, port, state, reason, reason_ttl, service, product, version,
      extra_info, tunnel, method, confidence, os_type
    ) VALUES (
      :snapshot_id, :protocol, :port, :state, :reason, :reason_ttl, :service, :product, :version,
      :extra_info, :tunnel, :method, :confidence, :os_type
    )
  ");
  $cpeInsert = db()->prepare("INSERT INTO scan_snapshot_port_cpes (port_id, position, cpe) VALUES (:port_id, :position, :cpe)");
  foreach ($scan['ports'] ?? array() as $port) {
    $portInsert->execute(array(
      'snapshot_id' => $snapshotId,
      'protocol' => strtolower((string)($port['protocol'] ?? '')),
      'port' => (int)($port['port'] ?? 0),
      'state' => (string)($port['state'] ?? ''),
      'reason' => scanNullIfEmpty((string)($port['reason'] ?? '')),
      'reason_ttl' => $port['reason_ttl'] ?? null,
      'service' => scanNullIfEmpty((string)($port['service'] ?? '')),
      'product' => scanNullIfEmpty((string)($port['product'] ?? '')),
      'version' => scanNullIfEmpty((string)($port['version'] ?? '')),
      'extra_info' => scanNullIfEmpty((string)($port['extra_info'] ?? '')),
      'tunnel' => scanNullIfEmpty((string)($port['tunnel'] ?? '')),
      'method' => scanNullIfEmpty((string)($port['method'] ?? '')),
      'confidence' => $port['confidence'] ?? null,
      'os_type' => scanNullIfEmpty((string)($port['os_type'] ?? ''))
    ));
    $portId = (int)db()->lastInsertId();
    foreach ($port['cpes'] ?? array() as $position => $cpe)
      $cpeInsert->execute(array('port_id' => $portId, 'position' => $position, 'cpe' => $cpe));
    scanPersistScripts($snapshotId, $portId, $port['scripts'] ?? array());
  }

  $extraInsert = db()->prepare("INSERT INTO scan_snapshot_extra_ports (snapshot_id, position, state, count) VALUES (:snapshot_id, :position, :state, :count)");
  $reasonInsert = db()->prepare("INSERT INTO scan_snapshot_extra_reasons (extra_port_id, position, reason, count, protocol, ports) VALUES (:extra_port_id, :position, :reason, :count, :protocol, :ports)");
  foreach ($scan['extra_ports'] ?? array() as $position => $extra) {
    $extraInsert->execute(array('snapshot_id' => $snapshotId, 'position' => $position, 'state' => (string)($extra['state'] ?? ''), 'count' => (int)($extra['count'] ?? 0)));
    $extraId = (int)db()->lastInsertId();
    foreach ($extra['reasons'] ?? array() as $reasonPosition => $reason) {
      $reasonInsert->execute(array(
        'extra_port_id' => $extraId, 'position' => $reasonPosition,
        'reason' => (string)($reason['reason'] ?? ''), 'count' => (int)($reason['count'] ?? 0),
        'protocol' => scanNullIfEmpty((string)($reason['protocol'] ?? '')),
        'ports' => scanNullIfEmpty((string)($reason['ports'] ?? ''))
      ));
    }
  }

  $matchInsert = db()->prepare("INSERT INTO scan_snapshot_os_matches (snapshot_id, position, name, accuracy) VALUES (:snapshot_id, :position, :name, :accuracy)");
  $classInsert = db()->prepare("INSERT INTO scan_snapshot_os_classes (os_match_id, position, vendor, os_family, os_generation, device_type, accuracy) VALUES (:os_match_id, :position, :vendor, :os_family, :os_generation, :device_type, :accuracy)");
  $osCpeInsert = db()->prepare("INSERT INTO scan_snapshot_os_cpes (os_class_id, position, cpe) VALUES (:os_class_id, :position, :cpe)");
  foreach ($scan['os_matches'] ?? array() as $position => $match) {
    $matchInsert->execute(array('snapshot_id' => $snapshotId, 'position' => $position, 'name' => (string)($match['name'] ?? ''), 'accuracy' => (int)($match['accuracy'] ?? 0)));
    $matchId = (int)db()->lastInsertId();
    foreach ($match['classes'] ?? array() as $classPosition => $class) {
      $classInsert->execute(array(
        'os_match_id' => $matchId, 'position' => $classPosition,
        'vendor' => scanNullIfEmpty((string)($class['vendor'] ?? '')),
        'os_family' => scanNullIfEmpty((string)($class['family'] ?? '')),
        'os_generation' => scanNullIfEmpty((string)($class['generation'] ?? '')),
        'device_type' => scanNullIfEmpty((string)($class['type'] ?? '')),
        'accuracy' => $class['accuracy'] ?? null
      ));
      $classId = (int)db()->lastInsertId();
      foreach ($class['cpes'] ?? array() as $cpePosition => $cpe)
        $osCpeInsert->execute(array('os_class_id' => $classId, 'position' => $cpePosition, 'cpe' => $cpe));
    }
  }

  scanPersistScripts($snapshotId, null, $scan['scripts'] ?? array());

  $hopInsert = db()->prepare("INSERT INTO scan_snapshot_trace_hops (snapshot_id, position, protocol, port, ttl, ip, hostname, rtt) VALUES (:snapshot_id, :position, :protocol, :port, :ttl, :ip, :hostname, :rtt)");
  foreach ($scan['trace'] ?? array() as $position => $hop) {
    $hopInsert->execute(array(
      'snapshot_id' => $snapshotId, 'position' => $position,
      'protocol' => scanNullIfEmpty((string)($hop['protocol'] ?? '')), 'port' => $hop['port'] ?? null,
      'ttl' => (int)($hop['ttl'] ?? 0), 'ip' => (string)($hop['ip'] ?? ''),
      'hostname' => scanNullIfEmpty((string)($hop['hostname'] ?? '')), 'rtt' => $hop['rtt'] ?? null
    ));
  }
}

function scanPersistScripts(int $snapshotId, ?int $portId, array $scripts): void {
  $scriptInsert = db()->prepare("INSERT INTO scan_snapshot_scripts (snapshot_id, port_id, position, script_id, output) VALUES (:snapshot_id, :port_id, :position, :script_id, :output)");
  $nodeInsert = db()->prepare("INSERT INTO scan_snapshot_script_nodes (script_id, parent_id, position, node_type, node_key, value) VALUES (:script_id, :parent_id, :position, :node_type, :node_key, :value)");
  foreach ($scripts as $position => $script) {
    $scriptInsert->execute(array(
      'snapshot_id' => $snapshotId, 'port_id' => $portId, 'position' => $position,
      'script_id' => (string)($script['id'] ?? ''), 'output' => scanNullIfEmpty((string)($script['output'] ?? ''))
    ));
    $scriptDbId = (int)db()->lastInsertId();
    $nodeIds = array();
    $siblingPositions = array();
    foreach ($script['nodes'] ?? array() as $index => $node) {
      $parentIndex = $node['parent'] ?? null;
      $parentId = $parentIndex === null ? null : ($nodeIds[$parentIndex] ?? null);
      $parentKey = $parentId === null ? 'root' : (string)$parentId;
      $nodePosition = $siblingPositions[$parentKey] ?? 0;
      $siblingPositions[$parentKey] = $nodePosition + 1;
      $nodeInsert->execute(array(
        'script_id' => $scriptDbId, 'parent_id' => $parentId, 'position' => $nodePosition,
        'node_type' => (string)($node['type'] ?? 'elem'),
        'node_key' => scanNullIfEmpty((string)($node['key'] ?? '')),
        'value' => scanNullIfEmpty((string)($node['value'] ?? ''))
      ));
      $nodeIds[$index] = (int)db()->lastInsertId();
    }
  }
}

function scanReadSnapshot(string $ip, ?array $metadata = null): ?array {
  if ($metadata === null)
    $metadata = scanMetadataBestResult($ip);
  if ($metadata === null || (int)($metadata['snapshot_id'] ?? 0) <= 0)
    return null;

  if (isset($metadata['id'])) {
    $execution = db()->prepare("
      SELECT scanner, scanner_version, scan_args, host_reason, host_reason_ttl, last_boot, uptime_seconds, distance
      FROM scans
      WHERE id=:id AND ip=:ip
      LIMIT 1
    ");
    $execution->execute(array('id' => $metadata['id'], 'ip' => $ip));
    $executionRow = $execution->fetch(PDO::FETCH_ASSOC);
    if ($executionRow !== false)
      $metadata = array_merge($metadata, $executionRow);
  }

  $snapshotId = (int)$metadata['snapshot_id'];
  $exists = db()->prepare("SELECT COUNT(*) FROM scan_snapshots WHERE id=:id AND ip=:ip");
  $exists->execute(array('id' => $snapshotId, 'ip' => $ip));
  if ((int)$exists->fetchColumn() !== 1)
    return null;

  $scan = array(
    'ip' => $ip,
    'args' => (string)($metadata['scan_args'] ?? ''),
    'scanner' => (string)($metadata['scanner'] ?? ''),
    'scanner_version' => (string)($metadata['scanner_version'] ?? ''),
    'started' => (string)($metadata['date_begin'] ?? ''),
    'status' => (string)($metadata['status'] ?? ''),
    'status_reason' => (string)($metadata['host_reason'] ?? ''),
    'status_reason_ttl' => isset($metadata['host_reason_ttl']) ? (int)$metadata['host_reason_ttl'] : null,
    'uptime' => (string)($metadata['last_boot'] ?? ''),
    'uptime_seconds' => isset($metadata['uptime_seconds']) ? (int)$metadata['uptime_seconds'] : null,
    'distance' => isset($metadata['distance']) ? (int)$metadata['distance'] : null,
    'duration' => $metadata['duration'] ?? null,
    'addresses' => array(),
    'hostnames' => array(),
    'ports' => array(),
    'port_scope' => array(),
    'extra_ports' => array(),
    'os' => array(),
    'os_matches' => array(),
    'scripts' => array(),
    'trace' => array(),
    'metadata' => $metadata,
    'xml' => isset($metadata['id']) ? scanXmlUrl($ip, (int)$metadata['id']) : null
  );

  $stmt = db()->prepare("SELECT protocol, port_begin, port_end FROM scan_snapshot_scopes WHERE snapshot_id=:id ORDER BY protocol, port_begin, port_end");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $scan['port_scope'][(string)$row['protocol']][] = array((int)$row['port_begin'], (int)$row['port_end']);

  $stmt = db()->prepare("SELECT address, address_type, vendor FROM scan_snapshot_addresses WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $scan['addresses'][] = array('addr' => $row['address'], 'type' => $row['address_type'], 'vendor' => $row['vendor'] ?? '');

  $stmt = db()->prepare("SELECT hostname, hostname_type FROM scan_snapshot_hostnames WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $scan['hostnames'][] = array('name' => $row['hostname'], 'type' => $row['hostname_type']);

  $portsById = array();
  $stmt = db()->prepare("SELECT * FROM scan_snapshot_ports WHERE snapshot_id=:id ORDER BY port, protocol, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $details = implode(' ', array_filter(array($row['product'] ?? '', $row['version'] ?? '', $row['extra_info'] ?? '')));
    $port = array(
      'protocol' => $row['protocol'], 'port' => (int)$row['port'], 'state' => $row['state'],
      'reason' => $row['reason'] ?? '', 'reason_ttl' => $row['reason_ttl'] === null ? null : (int)$row['reason_ttl'],
      'service' => $row['service'] ?? '', 'product' => $row['product'] ?? '', 'version' => $row['version'] ?? '',
      'extra_info' => $row['extra_info'] ?? '', 'details' => $details, 'tunnel' => $row['tunnel'] ?? '',
      'method' => $row['method'] ?? '', 'confidence' => $row['confidence'] === null ? null : (int)$row['confidence'],
      'os_type' => $row['os_type'] ?? '', 'cpes' => array(), 'scripts' => array()
    );
    $portsById[(int)$row['id']] = count($scan['ports']);
    $scan['ports'][] = $port;
  }
  $scan['ports_count'] = count($scan['ports']);

  $stmt = db()->prepare("SELECT c.port_id, c.cpe FROM scan_snapshot_port_cpes c INNER JOIN scan_snapshot_ports p ON p.id=c.port_id WHERE p.snapshot_id=:id ORDER BY c.port_id, c.position");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $portIndex = $portsById[(int)$row['port_id']] ?? null;
    if ($portIndex !== null)
      $scan['ports'][$portIndex]['cpes'][] = $row['cpe'];
  }

  $scriptRows = array();
  $stmt = db()->prepare("SELECT id, port_id, script_id, output FROM scan_snapshot_scripts WHERE snapshot_id=:id ORDER BY port_id IS NOT NULL, port_id, position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $scriptRows[(int)$row['id']] = array(
      'port_id' => $row['port_id'] === null ? null : (int)$row['port_id'],
      'script' => array('id' => $row['script_id'], 'output' => $row['output'] ?? '', 'nodes' => array()),
      'node_indexes' => array()
    );
  }
  if (count($scriptRows) !== 0) {
    $stmt = db()->prepare("SELECT n.id, n.script_id, n.parent_id, n.node_type, n.node_key, n.value FROM scan_snapshot_script_nodes n INNER JOIN scan_snapshot_scripts s ON s.id=n.script_id WHERE s.snapshot_id=:id ORDER BY n.script_id, n.id");
    $stmt->execute(array('id' => $snapshotId));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $scriptId = (int)$row['script_id'];
      if (!isset($scriptRows[$scriptId]))
        continue;
      $parentId = $row['parent_id'] === null ? null : (int)$row['parent_id'];
      $parentIndex = $parentId === null ? null : ($scriptRows[$scriptId]['node_indexes'][$parentId] ?? null);
      $index = count($scriptRows[$scriptId]['script']['nodes']);
      $scriptRows[$scriptId]['script']['nodes'][] = array(
        'parent' => $parentIndex, 'type' => $row['node_type'],
        'key' => $row['node_key'] ?? '', 'value' => $row['value'] ?? ''
      );
      $scriptRows[$scriptId]['node_indexes'][(int)$row['id']] = $index;
    }
  }
  foreach ($scriptRows as $scriptRow) {
    if ($scriptRow['port_id'] === null) {
      $scan['scripts'][] = $scriptRow['script'];
      continue;
    }
    $portIndex = $portsById[$scriptRow['port_id']] ?? null;
    if ($portIndex !== null)
      $scan['ports'][$portIndex]['scripts'][] = $scriptRow['script'];
  }

  $extraById = array();
  $stmt = db()->prepare("SELECT id, state, count FROM scan_snapshot_extra_ports WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $extraById[(int)$row['id']] = count($scan['extra_ports']);
    $scan['extra_ports'][] = array('state' => $row['state'], 'count' => (int)$row['count'], 'reasons' => array());
  }
  $stmt = db()->prepare("SELECT r.* FROM scan_snapshot_extra_reasons r INNER JOIN scan_snapshot_extra_ports e ON e.id=r.extra_port_id WHERE e.snapshot_id=:id ORDER BY r.extra_port_id, r.position, r.id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $extraIndex = $extraById[(int)$row['extra_port_id']] ?? null;
    if ($extraIndex !== null)
      $scan['extra_ports'][$extraIndex]['reasons'][] = array('reason' => $row['reason'], 'count' => (int)$row['count'], 'protocol' => $row['protocol'] ?? '', 'ports' => $row['ports'] ?? '');
  }

  $matchesById = array();
  $classesById = array();
  $stmt = db()->prepare("SELECT id, name, accuracy FROM scan_snapshot_os_matches WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $matchesById[(int)$row['id']] = count($scan['os_matches']);
    $scan['os_matches'][] = array('name' => $row['name'], 'accuracy' => (int)$row['accuracy'], 'classes' => array());
  }
  $stmt = db()->prepare("SELECT c.* FROM scan_snapshot_os_classes c INNER JOIN scan_snapshot_os_matches m ON m.id=c.os_match_id WHERE m.snapshot_id=:id ORDER BY c.os_match_id, c.position, c.id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $matchIndex = $matchesById[(int)$row['os_match_id']] ?? null;
    if ($matchIndex === null)
      continue;
    $classIndex = count($scan['os_matches'][$matchIndex]['classes']);
    $classesById[(int)$row['id']] = array($matchIndex, $classIndex);
    $scan['os_matches'][$matchIndex]['classes'][] = array(
      'vendor' => $row['vendor'] ?? '', 'family' => $row['os_family'] ?? '',
      'generation' => $row['os_generation'] ?? '', 'type' => $row['device_type'] ?? '',
      'accuracy' => $row['accuracy'] === null ? null : (int)$row['accuracy'], 'cpes' => array()
    );
  }
  $stmt = db()->prepare("SELECT c.os_class_id, c.cpe FROM scan_snapshot_os_cpes c INNER JOIN scan_snapshot_os_classes oc ON oc.id=c.os_class_id INNER JOIN scan_snapshot_os_matches m ON m.id=oc.os_match_id WHERE m.snapshot_id=:id ORDER BY c.os_class_id, c.position");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $indexes = $classesById[(int)$row['os_class_id']] ?? null;
    if ($indexes !== null)
      $scan['os_matches'][$indexes[0]]['classes'][$indexes[1]]['cpes'][] = $row['cpe'];
  }
  $scan['os'] = scanSelectOsMatches($scan['os_matches']);

  $stmt = db()->prepare("SELECT protocol, port, ttl, ip, hostname, rtt FROM scan_snapshot_trace_hops WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $scan['trace'][] = array(
      'protocol' => $row['protocol'] ?? '', 'port' => $row['port'] === null ? null : (int)$row['port'],
      'ttl' => (int)$row['ttl'], 'ip' => $row['ip'], 'hostname' => $row['hostname'] ?? '',
      'rtt' => $row['rtt'] === null ? null : (float)$row['rtt']
    );
  }
  return $scan;
}

function scanRecordPortChanges(array $job, array $scan, ?string $createdAt = null): int {
  $previous = scanEffectivePortsBefore((string)$job['ip'], (int)$job['id']);
  $current = scanApplyPortObservation($previous, $scan, (string)$job['mode']);
  $changes = scanComparePorts($previous, $current);
  if (count($changes) === 0)
    return 0;

  $insert = db()->prepare("
    INSERT OR IGNORE INTO scan_port_changes (
      scan_id, ip, mode, change_type, protocol, port,
      previous_service, previous_version, current_service, current_version, created_at
    ) VALUES (
      :scan_id, :ip, :mode, :change_type, :protocol, :port,
      :previous_service, :previous_version, :current_service, :current_version,
      COALESCE(:created_at, CURRENT_TIMESTAMP)
    )
  ");
  $inserted = 0;
  foreach ($changes as $change) {
    $insert->execute(array(
      'scan_id' => $job['id'],
      'ip' => $job['ip'],
      'mode' => $job['mode'],
      'change_type' => $change['change_type'],
      'protocol' => $change['protocol'],
      'port' => $change['port'],
      'previous_service' => scanNullIfEmpty($change['previous_service']),
      'previous_version' => scanNullIfEmpty($change['previous_version']),
      'current_service' => scanNullIfEmpty($change['current_service']),
      'current_version' => scanNullIfEmpty($change['current_version']),
      'created_at' => $createdAt
    ));
    $inserted += $insert->rowCount();
  }
  return $inserted;
}

function scanPortChangesBackfill(): int {
  $database = db();
  $stmt = $database->query("
    SELECT
      s.id,
      s.ip,
      s.mode,
      COALESCE(s.date_end, s.date_begin) AS change_date,
      s.snapshot_id
    FROM scans s
    INNER JOIN scan_snapshots ss ON ss.id=s.snapshot_id
    WHERE s.state='complete'
      AND s.port_changes_processed=0
    ORDER BY s.id ASC
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (count($rows) === 0) {
    scanPrunePortChanges();
    return 0;
  }

  dbBeginImmediate($database);
  try {
    $inserted = 0;
    $mark = $database->prepare("UPDATE scans SET port_changes_processed=1 WHERE id=:id");
    foreach ($rows as $row) {
      $inserted += scanRecordPortChanges(
        array('id' => (int)$row['id'], 'ip' => $row['ip'], 'mode' => $row['mode']),
        scanReadSnapshot((string)$row['ip'], scanNormalizeMetadata($row)) ?? array(),
        ($row['change_date'] ?? '') === '' ? null : (string)$row['change_date']
      );
      $mark->execute(array('id' => $row['id']));
    }
    dbCommit($database);
    scanPrunePortChanges();
    return $inserted;
  } catch (Throwable $e) {
    dbRollback($database);
    throw $e;
  }
}

function scanEffectivePortsBefore(string $ip, int $beforeId): array {
  $deep = scanPreviousSnapshotResult($ip, 'deep', $beforeId);
  $afterId = $deep === null ? 0 : (int)$deep['id'];
  $ports = $deep === null
    ? array()
    : scanOpenPortMap($deep['scan']);

  $stmt = db()->prepare("
    SELECT s.id, s.ip, s.mode, s.state, s.status, s.date_begin, s.date_end, s.duration, s.ports_count, s.snapshot_id, s.result_changed, s.error
    FROM scans s
    WHERE s.ip=:ip
      AND s.id>:after_id
      AND s.id<:before_id
      AND s.mode<>'deep'
      AND s.state='complete'
    ORDER BY s.id ASC
  ");
  $stmt->execute(array('ip' => $ip, 'after_id' => $afterId, 'before_id' => $beforeId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $observation = scanReadSnapshot($ip, scanNormalizeMetadata($row));
    if ($observation !== null)
      $ports = scanApplyPortObservation($ports, $observation, (string)$row['mode']);
  }

  return $ports;
}

function scanPreviousSnapshotResult(string $ip, string $mode, int $beforeId): ?array {
  $stmt = db()->prepare("
    SELECT s.id, s.ip, s.mode, s.state, s.status, s.date_begin, s.date_end, s.duration, s.ports_count, s.snapshot_id, s.result_changed, s.error
    FROM scans s
    WHERE s.ip=:ip
      AND s.mode=:mode
      AND s.id<:before_id
      AND s.state='complete'
      AND s.snapshot_id IS NOT NULL
    ORDER BY s.id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode, 'before_id' => $beforeId));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row === false)
    return null;
  $metadata = scanNormalizeMetadata($row);
  $scan = scanReadSnapshot($ip, $metadata);
  return $scan === null ? null : array('id' => (int)$row['id'], 'scan' => $scan);
}

function scanOpenPortMap(array $scan): array {
  $ports = array();
  foreach ($scan['ports'] ?? array() as $port) {
    if (strtolower((string)($port['state'] ?? '')) !== 'open')
      continue;
    $key = scanPortKey($port);
    if ($key !== null)
      $ports[$key] = $port;
  }
  return $ports;
}

function scanApplyPortObservation(array $base, array $scan, string $mode): array {
  $scope = $scan['port_scope'] ?? array();
  if (count($scope) === 0 && $mode === 'deep')
    $scope = array('tcp' => array(array(1, 65535)));
  $known = $base;

  foreach ($base as $key => $port) {
    if (scanPortIsInScope($port, $scope))
      unset($base[$key]);
  }

  foreach (scanOpenPortMap($scan) as $key => $observed)
    $base[$key] = scanMergePortKnowledge($known[$key] ?? null, $observed);
  ksort($base);
  return $base;
}

function scanPortIsInScope(array $port, array $scope): bool {
  $protocol = strtolower((string)($port['protocol'] ?? ''));
  $number = (int)($port['port'] ?? 0);
  foreach ($scope[$protocol] ?? array() as $range) {
    if ($number >= $range[0] && $number <= $range[1])
      return true;
  }
  return false;
}

function scanPortKey(array $port): ?string {
  $protocol = strtolower(trim((string)($port['protocol'] ?? '')));
  $number = (int)($port['port'] ?? 0);
  if ($protocol === '' || $number < 1 || $number > 65535)
    return null;
  return $protocol . '|' . $number;
}

function scanMergePortKnowledge(?array $known, array $observed): array {
  if ($known === null)
    return $observed;
  if (trim((string)($observed['service'] ?? '')) === '')
    $observed['service'] = $known['service'] ?? '';
  if (trim((string)($observed['details'] ?? '')) === '')
    $observed['details'] = $known['details'] ?? '';
  if (trim((string)($observed['tunnel'] ?? '')) === '')
    $observed['tunnel'] = $known['tunnel'] ?? '';
  foreach (array('product', 'version', 'extra_info', 'method', 'os_type') as $field) {
    if (trim((string)($observed[$field] ?? '')) === '')
      $observed[$field] = $known[$field] ?? '';
  }
  if (($observed['confidence'] ?? null) === null)
    $observed['confidence'] = $known['confidence'] ?? null;
  if (count($observed['cpes'] ?? array()) === 0)
    $observed['cpes'] = $known['cpes'] ?? array();
  return $observed;
}

function scanComparePorts(array $previous, array $current): array {
  $changes = array();
  foreach (array_unique(array_merge(array_keys($previous), array_keys($current))) as $key) {
    $before = $previous[$key] ?? null;
    $after = $current[$key] ?? null;
    if ($before === null) {
      $changes[] = scanPortChange('appeared', null, $after);
    } elseif ($after === null) {
      $changes[] = scanPortChange('disappeared', $before, null);
    } elseif (scanPortVersionChanged($before, $after)) {
      $changes[] = scanPortChange('changed', $before, $after);
    }
  }
  return $changes;
}

function scanPortVersionChanged(array $before, array $after): bool {
  $beforeService = trim((string)($before['service'] ?? ''));
  $afterService = trim((string)($after['service'] ?? ''));
  if ($beforeService !== '' && $afterService !== '' && $beforeService !== $afterService)
    return true;

  $beforeVersion = trim((string)($before['details'] ?? ''));
  $afterVersion = trim((string)($after['details'] ?? ''));
  return $beforeVersion !== '' && $afterVersion !== '' && $beforeVersion !== $afterVersion;
}

function scanPortChange(string $type, ?array $before, ?array $after): array {
  $port = $after ?? $before ?? array();
  return array(
    'change_type' => $type,
    'protocol' => strtolower((string)($port['protocol'] ?? '')),
    'port' => (int)($port['port'] ?? 0),
    'previous_service' => (string)($before['service'] ?? ''),
    'previous_version' => (string)($before['details'] ?? ''),
    'current_service' => (string)($after['service'] ?? ''),
    'current_version' => (string)($after['details'] ?? '')
  );
}

function scanNullIfEmpty(string $value): ?string {
  $value = trim($value);
  return $value === '' ? null : $value;
}

function scanMetadataFailed(int $id, string $error): void {
  $stmt = db()->prepare("
    UPDATE scans
    SET state='failed',
        date_end=CURRENT_TIMESTAMP,
        duration=CASE WHEN date_begin IS NULL THEN NULL ELSE MAX(0, unixepoch(CURRENT_TIMESTAMP)-unixepoch(date_begin)) END,
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
        duration=CASE WHEN date_begin IS NULL THEN NULL ELSE MAX(0, unixepoch(CURRENT_TIMESTAMP)-unixepoch(date_begin)) END,
        error=:error
    WHERE id=:id
  ");
  $stmt->execute(array('id' => $id, 'error' => $error));
}

function scanMetadataLatest(string $ip): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataBestResult(string $ip, ?string $mode = null): ?array {
  if ($mode !== null && !scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $modeWhere = $mode === null ? '' : ' AND mode=:mode';
  $order = $mode === null
    ? "CASE mode WHEN 'deep' THEN 0 ELSE 1 END, id DESC"
    : 'id DESC';
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND state='complete'
      AND snapshot_id IS NOT NULL
      $modeWhere
    ORDER BY $order
    LIMIT 1
  ");
  $params = array('ip' => $ip);
  if ($mode !== null)
    $params['mode'] = $mode;
  $stmt->execute($params);
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataPreviousResult(string $ip, string $mode, int $beforeId): ?array {
  if (!scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND mode=:mode
      AND id<:before_id
      AND state='complete'
      AND snapshot_id IS NOT NULL
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode, 'before_id' => $beforeId));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMetadataById(string $ip, int $id): ?array {
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip AND id=:id
    LIMIT 1
  ");
  $stmt->execute(array('ip' => $ip, 'id' => $id));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : scanNormalizeMetadata($metadata);
}

function scanMergePartialWithDeep(array $partial, array $deep, array $deepMetadata): array {
  $merged = $deep;
  $partialMode = (string)($partial['metadata']['mode'] ?? 'lightweight');

  foreach (array('ip', 'args', 'started', 'status') as $field) {
    if (($partial[$field] ?? '') !== '')
      $merged[$field] = $partial[$field];
  }
  if (($partial['duration'] ?? null) !== null)
    $merged['duration'] = $partial['duration'];
  if (($partial['uptime'] ?? '') !== '')
    $merged['uptime'] = $partial['uptime'];

  $merged['addresses'] = scanMergeResultItems(
    $deep['addresses'] ?? array(),
    $partial['addresses'] ?? array(),
    fn($item) => ($item['type'] ?? '') . '|' . ($item['addr'] ?? ''),
    $partialMode
  );
  $merged['hostnames'] = scanMergeResultItems(
    $deep['hostnames'] ?? array(),
    $partial['hostnames'] ?? array(),
    fn($item) => ($item['type'] ?? '') . '|' . ($item['name'] ?? ''),
    $partialMode
  );
  $merged['ports'] = scanMergePorts($deep['ports'] ?? array(), $partial['ports'] ?? array(), $partialMode);
  usort($merged['ports'], function ($left, $right) {
    $portOrder = (int)($left['port'] ?? 0) <=> (int)($right['port'] ?? 0);
    return $portOrder !== 0 ? $portOrder : strcmp((string)($left['protocol'] ?? ''), (string)($right['protocol'] ?? ''));
  });

  $partialOs = $partial['os'] ?? array();
  $merged['os'] = scanMarkResultSource(count($partialOs) !== 0 ? $partialOs : ($deep['os'] ?? array()), count($partialOs) !== 0 ? $partialMode : 'deep');
  $merged['os_matches'] = count($partial['os_matches'] ?? array()) !== 0 ? $partial['os_matches'] : ($deep['os_matches'] ?? array());
  $merged['port_scope'] = $partial['port_scope'] ?? array();
  $merged['extra_ports'] = $partial['extra_ports'] ?? array();
  $merged['scripts'] = count($partial['scripts'] ?? array()) !== 0 ? $partial['scripts'] : ($deep['scripts'] ?? array());
  $merged['trace'] = count($partial['trace'] ?? array()) !== 0 ? $partial['trace'] : ($deep['trace'] ?? array());
  $merged['ports_count'] = count($merged['ports']);
  $merged['metadata'] = $partial['metadata'] ?? null;
  $merged['xml'] = $partial['xml'] ?? null;
  $merged['merged'] = true;
  $merged['merged_with'] = $deepMetadata;
  return $merged;
}

function scanMergeResultItems(array $base, array $overlay, callable $key, string $overlaySource): array {
  $items = array();
  foreach (scanMarkResultSource($base, 'deep') as $item)
    $items[$key($item)] = $item;
  foreach (scanMarkResultSource($overlay, $overlaySource) as $item)
    $items[$key($item)] = $item;
  return array_values($items);
}

function scanMergePorts(array $deep, array $partial, string $partialSource): array {
  $items = array();
  foreach (scanMarkResultSource($deep, 'deep') as $item) {
    $key = ($item['protocol'] ?? '') . '|' . ($item['port'] ?? '');
    $items[$key] = $item;
  }
  foreach (scanMarkResultSource($partial, $partialSource) as $item) {
    $key = ($item['protocol'] ?? '') . '|' . ($item['port'] ?? '');
    $items[$key] = scanMergePortKnowledge($items[$key] ?? null, $item);
  }
  return array_values($items);
}

function scanMarkResultSource(array $items, string $source): array {
  return array_map(function ($item) use ($source) {
    $item['source'] = $source;
    return $item;
  }, $items);
}

function scanMetadataHistory(string $ip, int $limit = 30): array {
  $limit = max(1, min(100, $limit));
  $days = SCAN_HISTORY_DAYS;
  $stmt = db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND snapshot_id IS NOT NULL
      AND result_changed=1
      AND (
        COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) >= datetime('now', '-$days days')
        OR id IN (
          SELECT latest_id
          FROM (
            SELECT MAX(id) AS latest_id
            FROM scans
            WHERE ip=:latest_ip AND state='complete' AND result_changed=1
            GROUP BY mode
          ) latest_results
        )
      )
    ORDER BY id DESC
    LIMIT $limit
  ");
  $stmt->execute(array('ip' => $ip, 'latest_ip' => $ip));

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
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE ip=:ip
      AND (state<>'complete' OR result_changed=1)
      AND (
        COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) >= datetime('now', '-$days days')
        OR id IN (
          SELECT latest_id
          FROM (
            SELECT MAX(id) AS latest_id
            FROM scans
            WHERE ip=:latest_ip AND state='complete' AND result_changed=1
            GROUP BY mode
          ) latest_results
        )
      )
    ORDER BY id DESC
    LIMIT $limit
  ");
  $stmt->execute(array('ip' => $ip, 'latest_ip' => $ip));

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
      s.snapshot_id,
      s.result_changed,
      s.error,
      i.id AS host_id,
      COALESCE(i.name, '') AS name,
      COALESCE(i.mac, '') AS mac,
      COALESCE(i.important, 0) AS important
    FROM scans s
    LEFT JOIN ips i ON i.ip=s.ip
    ORDER BY
      CASE s.state WHEN 'running' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,
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
    SELECT s.id, s.ip, s.mode, s.state, s.status, s.date_begin, s.date_end, s.duration, s.ports_count, s.snapshot_id, s.result_changed, s.error
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

function scanMetadataLatestUsableByIp(): array {
  $stmt = db()->query("
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
      s.snapshot_id,
      s.result_changed,
      s.error,
      i.id AS host_id,
      COALESCE(NULLIF(i.name, ''), NULLIF((
        SELECT l.`client-hostname`
        FROM leases l
        WHERE l.ip=s.ip
        ORDER BY l.active DESC, l.last_seen DESC
        LIMIT 1
      ), ''), '') AS name,
      COALESCE(NULLIF(i.mac, ''), NULLIF((
        SELECT known.mac
        FROM stats known
        WHERE known.ip=s.ip AND known.mac IS NOT NULL AND known.mac<>''
        ORDER BY known.id DESC
        LIMIT 1
      ), ''), NULLIF((
        SELECT l.`hardware-ethernet`
        FROM leases l
        WHERE l.ip=s.ip
        ORDER BY l.active DESC, l.last_seen DESC
        LIMIT 1
      ), ''), '') AS mac
    FROM scans s
    INNER JOIN (
      SELECT ip, MAX(id) AS id
      FROM scans
      WHERE state='complete' AND snapshot_id IS NOT NULL
      GROUP BY ip
    ) latest ON latest.id=s.id
    LEFT JOIN ips i ON i.ip=s.ip
    ORDER BY ipv4_num(s.ip), s.ip
  ");

  $results = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metadata = scanNormalizeMetadata($row);
    $metadata['host_id'] = $row['host_id'] === null ? null : (int)$row['host_id'];
    $metadata['name'] = (string)($row['name'] ?? '');
    $metadata['mac'] = strtolower((string)($row['mac'] ?? ''));
    $results[] = $metadata;
  }
  return $results;
}

function scanNormalizeMetadata(array $metadata): array {
  $metadata['id'] = isset($metadata['id']) ? (int)$metadata['id'] : null;
  $metadata['duration'] = isset($metadata['duration']) && $metadata['duration'] !== null ? (int)$metadata['duration'] : null;
  $metadata['ports_count'] = isset($metadata['ports_count']) ? (int)$metadata['ports_count'] : 0;
  $metadata['snapshot_id'] = isset($metadata['snapshot_id']) && $metadata['snapshot_id'] !== null ? (int)$metadata['snapshot_id'] : null;
  $metadata['result_changed'] = (int)($metadata['result_changed'] ?? 0);
  $metadata['result_available'] = (int)($metadata['snapshot_id'] ?? 0) > 0;
  $metadata['xml_usable'] = $metadata['result_available'];
  $metadata['xml_url'] = $metadata['xml_usable'] && isset($metadata['ip'], $metadata['id'])
    ? scanXmlUrl($metadata['ip'], $metadata['id'])
    : null;
  $metadata['xml'] = $metadata['xml_url'];
  return $metadata;
}

function scanPruneHistory(string $ip): void {
  scanPruneOldHistory($ip);
  scanPruneOrphanSnapshots();
  scanPrunePortChanges();
}

function scanPrunePortChanges(): void {
  $days = SCAN_HISTORY_DAYS;
  db()->exec("DELETE FROM scan_port_changes WHERE created_at < datetime('now', '-$days days')");
}

function scanPruneOldHistory(string $ip): void {
  $days = SCAN_HISTORY_DAYS;
  $stmt = db()->prepare("
    SELECT id
    FROM scans
    WHERE ip=:ip
      AND COALESCE(date_end, date_begin, CURRENT_TIMESTAMP) < datetime('now', '-$days days')
      AND id NOT IN (
        SELECT keep_id
        FROM (
          SELECT MAX(id) AS keep_id
          FROM scans
          WHERE ip=:keep_ip AND state='complete' AND snapshot_id IS NOT NULL
          GROUP BY mode
        ) latest_results
      )
      AND id NOT IN (
        SELECT keep_id
        FROM (
          SELECT MAX(id) AS keep_id
          FROM scans
          WHERE ip=:changed_ip AND state='complete' AND result_changed=1
          GROUP BY mode
        ) latest_changes
      )
  ");
  $stmt->execute(array('ip' => $ip, 'keep_ip' => $ip, 'changed_ip' => $ip));

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    scanDeleteMetadata($row);
}

function scanPruneOrphanSnapshots(): void {
  db()->exec("
    DELETE FROM scan_snapshots
    WHERE NOT EXISTS (SELECT 1 FROM scans WHERE scans.snapshot_id=scan_snapshots.id)
  ");
}

function scanDeleteMetadata(array $metadata): void {
  if (isset($metadata['id']) && $metadata['id'] !== null) {
    $stmt = db()->prepare("DELETE FROM scans WHERE id=:id");
    $stmt->execute(array('id' => $metadata['id']));
  }
}
