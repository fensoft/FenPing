<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

trait ScanXmlRendererBehavior
{
public function scanRenderXml(array $scan): string {
  $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  $xml .= '<?xml-stylesheet href="' . self::SCAN_XSL_TO . 'nmap.xsl" type="text/xsl"?>' . "\n";
  $xml .= '<nmaprun' . $this->scanXmlAttributes(array(
    'scanner' => $scan['scanner'] ?? 'nmap',
    'args' => $scan['args'] ?? '',
    'version' => $scan['scanner_version'] ?? '',
    'startstr' => $scan['started'] ?? ''
  )) . ">\n";
  foreach ($scan['port_scope'] ?? array() as $protocol => $ranges) {
    $services = implode(',', array_map(fn($range) => $range[0] === $range[1] ? (string)$range[0] : $range[0] . '-' . $range[1], $ranges));
    $xml .= '  <scaninfo' . $this->scanXmlAttributes(array('type' => 'syn', 'protocol' => $protocol, 'services' => $services)) . "/>\n";
  }
  $xml .= "  <host>\n";
  $xml .= '    <status' . $this->scanXmlAttributes(array('state' => $scan['status'] ?? '', 'reason' => $scan['status_reason'] ?? '', 'reason_ttl' => $scan['status_reason_ttl'] ?? null)) . "/>\n";
  foreach ($scan['addresses'] ?? array() as $address)
    $xml .= '    <address' . $this->scanXmlAttributes(array('addr' => $address['addr'] ?? '', 'addrtype' => $address['type'] ?? '', 'vendor' => $address['vendor'] ?? '')) . "/>\n";
  if (count($scan['hostnames'] ?? array()) !== 0) {
    $xml .= "    <hostnames>\n";
    foreach ($scan['hostnames'] as $hostname)
      $xml .= '      <hostname' . $this->scanXmlAttributes(array('name' => $hostname['name'] ?? '', 'type' => $hostname['type'] ?? '')) . "/>\n";
    $xml .= "    </hostnames>\n";
  }
  $xml .= "    <ports>\n";
  foreach ($scan['extra_ports'] ?? array() as $extra) {
    $xml .= '      <extraports' . $this->scanXmlAttributes(array('state' => $extra['state'] ?? '', 'count' => $extra['count'] ?? 0)) . ">\n";
    foreach ($extra['reasons'] ?? array() as $reason)
      $xml .= '        <extrareasons' . $this->scanXmlAttributes(array('reason' => $reason['reason'] ?? '', 'count' => $reason['count'] ?? 0, 'proto' => $reason['protocol'] ?? '', 'ports' => $reason['ports'] ?? '')) . "/>\n";
    $xml .= "      </extraports>\n";
  }
  foreach ($scan['ports'] ?? array() as $port) {
    $xml .= '      <port' . $this->scanXmlAttributes(array('protocol' => $port['protocol'] ?? '', 'portid' => $port['port'] ?? 0)) . ">\n";
    $xml .= '        <state' . $this->scanXmlAttributes(array('state' => $port['state'] ?? '', 'reason' => $port['reason'] ?? '', 'reason_ttl' => $port['reason_ttl'] ?? null)) . "/>\n";
    $serviceAttributes = array(
      'name' => $port['service'] ?? '', 'product' => $port['product'] ?? '',
      'version' => $port['version'] ?? '', 'extrainfo' => $port['extra_info'] ?? '',
      'tunnel' => $port['tunnel'] ?? '', 'method' => $port['method'] ?? '',
      'conf' => $port['confidence'] ?? null, 'ostype' => $port['os_type'] ?? ''
    );
    if (count($port['cpes'] ?? array()) === 0) {
      $xml .= '        <service' . $this->scanXmlAttributes($serviceAttributes) . "/>\n";
    } else {
      $xml .= '        <service' . $this->scanXmlAttributes($serviceAttributes) . ">\n";
      foreach ($port['cpes'] as $cpe)
        $xml .= '          <cpe>' . $this->scanXmlText($cpe) . "</cpe>\n";
      $xml .= "        </service>\n";
    }
    foreach ($port['scripts'] ?? array() as $script)
      $xml .= $this->scanRenderScriptXml($script, '        ');
    $xml .= "      </port>\n";
  }
  $xml .= "    </ports>\n";
  if (count($scan['os_matches'] ?? array()) !== 0) {
    $xml .= "    <os>\n";
    foreach ($scan['os_matches'] as $match) {
      $xml .= '      <osmatch' . $this->scanXmlAttributes(array('name' => $match['name'] ?? '', 'accuracy' => $match['accuracy'] ?? 0)) . ">\n";
      foreach ($match['classes'] ?? array() as $class) {
        $xml .= '        <osclass' . $this->scanXmlAttributes(array(
          'vendor' => $class['vendor'] ?? '', 'osfamily' => $class['family'] ?? '',
          'osgen' => $class['generation'] ?? '', 'type' => $class['type'] ?? '',
          'accuracy' => $class['accuracy'] ?? null
        )) . ">\n";
        foreach ($class['cpes'] ?? array() as $cpe)
          $xml .= '          <cpe>' . $this->scanXmlText($cpe) . "</cpe>\n";
        $xml .= "        </osclass>\n";
      }
      $xml .= "      </osmatch>\n";
    }
    $xml .= "    </os>\n";
  }
  if (($scan['uptime'] ?? '') !== '' || ($scan['uptime_seconds'] ?? null) !== null)
    $xml .= '    <uptime' . $this->scanXmlAttributes(array('seconds' => $scan['uptime_seconds'] ?? null, 'lastboot' => $scan['uptime'] ?? '')) . "/>\n";
  if (($scan['distance'] ?? null) !== null)
    $xml .= '    <distance' . $this->scanXmlAttributes(array('value' => $scan['distance'])) . "/>\n";
  if (count($scan['scripts'] ?? array()) !== 0) {
    $xml .= "    <hostscript>\n";
    foreach ($scan['scripts'] as $script)
      $xml .= $this->scanRenderScriptXml($script, '      ');
    $xml .= "    </hostscript>\n";
  }
  if (count($scan['trace'] ?? array()) !== 0) {
    $first = $scan['trace'][0];
    $xml .= '    <trace' . $this->scanXmlAttributes(array('proto' => $first['protocol'] ?? '', 'port' => $first['port'] ?? null)) . ">\n";
    foreach ($scan['trace'] as $hop)
      $xml .= '      <hop' . $this->scanXmlAttributes(array('ttl' => $hop['ttl'] ?? 0, 'ipaddr' => $hop['ip'] ?? '', 'host' => $hop['hostname'] ?? '', 'rtt' => $hop['rtt'] ?? null)) . "/>\n";
    $xml .= "    </trace>\n";
  }
  $xml .= "  </host>\n";
  $xml .= '  <runstats><finished' . $this->scanXmlAttributes(array('elapsed' => $scan['duration'] ?? null, 'exit' => 'success')) . "/></runstats>\n";
  return $xml . "</nmaprun>\n";
}

public function scanRenderScriptXml(array $script, string $indent): string {
  $xml = $indent . '<script' . $this->scanXmlAttributes(array('id' => $script['id'] ?? '', 'output' => $script['output'] ?? ''));
  if (count($script['nodes'] ?? array()) === 0)
    return $xml . "/>\n";
  $xml .= ">\n";
  $children = array();
  foreach ($script['nodes'] as $index => $node) {
    $parent = $node['parent'] ?? null;
    $children[$parent === null ? 'root' : (string)$parent][] = $index;
  }
  foreach ($children['root'] ?? array() as $index)
    $xml .= $this->scanRenderScriptNodeXml($script['nodes'], $children, $index, $indent . '  ');
  return $xml . $indent . "</script>\n";
}

public function scanRenderScriptNodeXml(array $nodes, array $children, int $index, string $indent): string {
  $node = $nodes[$index];
  $type = ($node['type'] ?? '') === 'table' ? 'table' : 'elem';
  $xml = $indent . '<' . $type . $this->scanXmlAttributes(array('key' => $node['key'] ?? ''));
  $childIndexes = $children[(string)$index] ?? array();
  $value = (string)($node['value'] ?? '');
  if (count($childIndexes) === 0 && $value === '')
    return $xml . "/>\n";
  $xml .= '>';
  if ($value !== '')
    $xml .= $this->scanXmlText($value);
  if (count($childIndexes) !== 0) {
    $xml .= "\n";
    foreach ($childIndexes as $child)
      $xml .= $this->scanRenderScriptNodeXml($nodes, $children, $child, $indent . '  ');
    $xml .= $indent;
  }
  return $xml . '</' . $type . ">\n";
}

public function scanXmlAttributes(array $attributes): string {
  $xml = '';
  foreach ($attributes as $name => $value) {
    if ($value === null || $value === '')
      continue;
    $xml .= ' ' . $name . '="' . $this->scanXmlText((string)$value) . '"';
  }
  return $xml;
}

public function scanXmlText(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
}
