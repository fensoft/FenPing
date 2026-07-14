<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Database\DatabaseManager;
use PDO;
use RuntimeException;
use Throwable;

final readonly class SnapshotRepository
{
    public function __construct(
        private DatabaseManager $database,
        private XmlCodec $codec,
        private ScanResultStore $results,
    ) {
    }

    public function read(string $ip, ?array $metadata = null): ?array
    {
        return $this->scanReadSnapshot($ip, $metadata);
    }

public function scanEnsureSnapshot(array $job, array $scan): array {
  $resultHash = $this->codec->semanticHash($scan);
  $contentHash = $this->codec->contentHash($scan);
  $previous = $this->database->connection()->prepare("
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

  $insert = $this->database->connection()->prepare("
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

  $find = $this->database->connection()->prepare("
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
    $this->scanPersistSnapshot($snapshotId, $scan);

  return array(
    'id' => $snapshotId,
    'changed' => $previousRow === false || !hash_equals((string)$previousRow['result_hash'], $resultHash)
  );
}

public function scanPersistSnapshot(int $snapshotId, array $scan): void {
  $scopeInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_scopes (snapshot_id, protocol, port_begin, port_end) VALUES (:snapshot_id, :protocol, :port_begin, :port_end)");
  foreach ($scan['port_scope'] ?? array() as $protocol => $ranges) {
    foreach ($ranges as $range)
      $scopeInsert->execute(array('snapshot_id' => $snapshotId, 'protocol' => $protocol, 'port_begin' => $range[0], 'port_end' => $range[1]));
  }

  $addressInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_addresses (snapshot_id, position, address, address_type, vendor) VALUES (:snapshot_id, :position, :address, :address_type, :vendor)");
  foreach ($scan['addresses'] ?? array() as $position => $address) {
    $addressInsert->execute(array(
      'snapshot_id' => $snapshotId, 'position' => $position,
      'address' => (string)($address['addr'] ?? ''), 'address_type' => (string)($address['type'] ?? ''),
      'vendor' => $this->scanNullIfEmpty((string)($address['vendor'] ?? ''))
    ));
  }

  $hostnameInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_hostnames (snapshot_id, position, hostname, hostname_type) VALUES (:snapshot_id, :position, :hostname, :hostname_type)");
  foreach ($scan['hostnames'] ?? array() as $position => $hostname) {
    $hostnameInsert->execute(array(
      'snapshot_id' => $snapshotId, 'position' => $position,
      'hostname' => (string)($hostname['name'] ?? ''), 'hostname_type' => (string)($hostname['type'] ?? '')
    ));
  }

  $portInsert = $this->database->connection()->prepare("
    INSERT INTO scan_snapshot_ports (
      snapshot_id, protocol, port, state, reason, reason_ttl, service, product, version,
      extra_info, tunnel, method, confidence, os_type
    ) VALUES (
      :snapshot_id, :protocol, :port, :state, :reason, :reason_ttl, :service, :product, :version,
      :extra_info, :tunnel, :method, :confidence, :os_type
    )
  ");
  $cpeInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_port_cpes (port_id, position, cpe) VALUES (:port_id, :position, :cpe)");
  foreach ($scan['ports'] ?? array() as $port) {
    $portInsert->execute(array(
      'snapshot_id' => $snapshotId,
      'protocol' => strtolower((string)($port['protocol'] ?? '')),
      'port' => (int)($port['port'] ?? 0),
      'state' => (string)($port['state'] ?? ''),
      'reason' => $this->scanNullIfEmpty((string)($port['reason'] ?? '')),
      'reason_ttl' => $port['reason_ttl'] ?? null,
      'service' => $this->scanNullIfEmpty((string)($port['service'] ?? '')),
      'product' => $this->scanNullIfEmpty((string)($port['product'] ?? '')),
      'version' => $this->scanNullIfEmpty((string)($port['version'] ?? '')),
      'extra_info' => $this->scanNullIfEmpty((string)($port['extra_info'] ?? '')),
      'tunnel' => $this->scanNullIfEmpty((string)($port['tunnel'] ?? '')),
      'method' => $this->scanNullIfEmpty((string)($port['method'] ?? '')),
      'confidence' => $port['confidence'] ?? null,
      'os_type' => $this->scanNullIfEmpty((string)($port['os_type'] ?? ''))
    ));
    $portId = (int)$this->database->connection()->lastInsertId();
    foreach ($port['cpes'] ?? array() as $position => $cpe)
      $cpeInsert->execute(array('port_id' => $portId, 'position' => $position, 'cpe' => $cpe));
    $this->scanPersistScripts($snapshotId, $portId, $port['scripts'] ?? array());
  }

  $extraInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_extra_ports (snapshot_id, position, state, count) VALUES (:snapshot_id, :position, :state, :count)");
  $reasonInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_extra_reasons (extra_port_id, position, reason, count, protocol, ports) VALUES (:extra_port_id, :position, :reason, :count, :protocol, :ports)");
  foreach ($scan['extra_ports'] ?? array() as $position => $extra) {
    $extraInsert->execute(array('snapshot_id' => $snapshotId, 'position' => $position, 'state' => (string)($extra['state'] ?? ''), 'count' => (int)($extra['count'] ?? 0)));
    $extraId = (int)$this->database->connection()->lastInsertId();
    foreach ($extra['reasons'] ?? array() as $reasonPosition => $reason) {
      $reasonInsert->execute(array(
        'extra_port_id' => $extraId, 'position' => $reasonPosition,
        'reason' => (string)($reason['reason'] ?? ''), 'count' => (int)($reason['count'] ?? 0),
        'protocol' => $this->scanNullIfEmpty((string)($reason['protocol'] ?? '')),
        'ports' => $this->scanNullIfEmpty((string)($reason['ports'] ?? ''))
      ));
    }
  }

  $matchInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_os_matches (snapshot_id, position, name, accuracy) VALUES (:snapshot_id, :position, :name, :accuracy)");
  $classInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_os_classes (os_match_id, position, vendor, os_family, os_generation, device_type, accuracy) VALUES (:os_match_id, :position, :vendor, :os_family, :os_generation, :device_type, :accuracy)");
  $osCpeInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_os_cpes (os_class_id, position, cpe) VALUES (:os_class_id, :position, :cpe)");
  foreach ($scan['os_matches'] ?? array() as $position => $match) {
    $matchInsert->execute(array('snapshot_id' => $snapshotId, 'position' => $position, 'name' => (string)($match['name'] ?? ''), 'accuracy' => (int)($match['accuracy'] ?? 0)));
    $matchId = (int)$this->database->connection()->lastInsertId();
    foreach ($match['classes'] ?? array() as $classPosition => $class) {
      $classInsert->execute(array(
        'os_match_id' => $matchId, 'position' => $classPosition,
        'vendor' => $this->scanNullIfEmpty((string)($class['vendor'] ?? '')),
        'os_family' => $this->scanNullIfEmpty((string)($class['family'] ?? '')),
        'os_generation' => $this->scanNullIfEmpty((string)($class['generation'] ?? '')),
        'device_type' => $this->scanNullIfEmpty((string)($class['type'] ?? '')),
        'accuracy' => $class['accuracy'] ?? null
      ));
      $classId = (int)$this->database->connection()->lastInsertId();
      foreach ($class['cpes'] ?? array() as $cpePosition => $cpe)
        $osCpeInsert->execute(array('os_class_id' => $classId, 'position' => $cpePosition, 'cpe' => $cpe));
    }
  }

  $this->scanPersistScripts($snapshotId, null, $scan['scripts'] ?? array());

  $hopInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_trace_hops (snapshot_id, position, protocol, port, ttl, ip, hostname, rtt) VALUES (:snapshot_id, :position, :protocol, :port, :ttl, :ip, :hostname, :rtt)");
  foreach ($scan['trace'] ?? array() as $position => $hop) {
    $hopInsert->execute(array(
      'snapshot_id' => $snapshotId, 'position' => $position,
      'protocol' => $this->scanNullIfEmpty((string)($hop['protocol'] ?? '')), 'port' => $hop['port'] ?? null,
      'ttl' => (int)($hop['ttl'] ?? 0), 'ip' => (string)($hop['ip'] ?? ''),
      'hostname' => $this->scanNullIfEmpty((string)($hop['hostname'] ?? '')), 'rtt' => $hop['rtt'] ?? null
    ));
  }
}

public function scanPersistScripts(int $snapshotId, ?int $portId, array $scripts): void {
  $scriptInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_scripts (snapshot_id, port_id, position, script_id, output) VALUES (:snapshot_id, :port_id, :position, :script_id, :output)");
  $nodeInsert = $this->database->connection()->prepare("INSERT INTO scan_snapshot_script_nodes (script_id, parent_id, position, node_type, node_key, value) VALUES (:script_id, :parent_id, :position, :node_type, :node_key, :value)");
  foreach ($scripts as $position => $script) {
    $scriptInsert->execute(array(
      'snapshot_id' => $snapshotId, 'port_id' => $portId, 'position' => $position,
      'script_id' => (string)($script['id'] ?? ''), 'output' => $this->scanNullIfEmpty((string)($script['output'] ?? ''))
    ));
    $scriptDbId = (int)$this->database->connection()->lastInsertId();
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
        'node_key' => $this->scanNullIfEmpty((string)($node['key'] ?? '')),
        'value' => $this->scanNullIfEmpty((string)($node['value'] ?? ''))
      ));
      $nodeIds[$index] = (int)$this->database->connection()->lastInsertId();
    }
  }
}

public function scanReadSnapshot(string $ip, ?array $metadata = null): ?array {
  if ($metadata === null)
    $metadata = $this->results->scanMetadataBestResult($ip);
  if ($metadata === null || (int)($metadata['snapshot_id'] ?? 0) <= 0)
    return null;

  if (isset($metadata['id'])) {
    $execution = $this->database->connection()->prepare("
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
  $exists = $this->database->connection()->prepare("SELECT COUNT(*) FROM scan_snapshots WHERE id=:id AND ip=:ip");
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
    'xml' => isset($metadata['id']) ? $this->codec->url($ip, (int)$metadata['id']) : null
  );

  $stmt = $this->database->connection()->prepare("SELECT protocol, port_begin, port_end FROM scan_snapshot_scopes WHERE snapshot_id=:id ORDER BY protocol, port_begin, port_end");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $scan['port_scope'][(string)$row['protocol']][] = array((int)$row['port_begin'], (int)$row['port_end']);

  $stmt = $this->database->connection()->prepare("SELECT address, address_type, vendor FROM scan_snapshot_addresses WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $scan['addresses'][] = array('addr' => $row['address'], 'type' => $row['address_type'], 'vendor' => $row['vendor'] ?? '');

  $stmt = $this->database->connection()->prepare("SELECT hostname, hostname_type FROM scan_snapshot_hostnames WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $scan['hostnames'][] = array('name' => $row['hostname'], 'type' => $row['hostname_type']);

  $portsById = array();
  $stmt = $this->database->connection()->prepare("SELECT * FROM scan_snapshot_ports WHERE snapshot_id=:id ORDER BY port, protocol, id");
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

  $stmt = $this->database->connection()->prepare("SELECT c.port_id, c.cpe FROM scan_snapshot_port_cpes c INNER JOIN scan_snapshot_ports p ON p.id=c.port_id WHERE p.snapshot_id=:id ORDER BY c.port_id, c.position");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $portIndex = $portsById[(int)$row['port_id']] ?? null;
    if ($portIndex !== null)
      $scan['ports'][$portIndex]['cpes'][] = $row['cpe'];
  }

  $scriptRows = array();
  $stmt = $this->database->connection()->prepare("SELECT id, port_id, script_id, output FROM scan_snapshot_scripts WHERE snapshot_id=:id ORDER BY port_id IS NOT NULL, port_id, position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $scriptRows[(int)$row['id']] = array(
      'port_id' => $row['port_id'] === null ? null : (int)$row['port_id'],
      'script' => array('id' => $row['script_id'], 'output' => $row['output'] ?? '', 'nodes' => array()),
      'node_indexes' => array()
    );
  }
  if (count($scriptRows) !== 0) {
    $stmt = $this->database->connection()->prepare("SELECT n.id, n.script_id, n.parent_id, n.node_type, n.node_key, n.value FROM scan_snapshot_script_nodes n INNER JOIN scan_snapshot_scripts s ON s.id=n.script_id WHERE s.snapshot_id=:id ORDER BY n.script_id, n.id");
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
  $stmt = $this->database->connection()->prepare("SELECT id, state, count FROM scan_snapshot_extra_ports WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $extraById[(int)$row['id']] = count($scan['extra_ports']);
    $scan['extra_ports'][] = array('state' => $row['state'], 'count' => (int)$row['count'], 'reasons' => array());
  }
  $stmt = $this->database->connection()->prepare("SELECT r.* FROM scan_snapshot_extra_reasons r INNER JOIN scan_snapshot_extra_ports e ON e.id=r.extra_port_id WHERE e.snapshot_id=:id ORDER BY r.extra_port_id, r.position, r.id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $extraIndex = $extraById[(int)$row['extra_port_id']] ?? null;
    if ($extraIndex !== null)
      $scan['extra_ports'][$extraIndex]['reasons'][] = array('reason' => $row['reason'], 'count' => (int)$row['count'], 'protocol' => $row['protocol'] ?? '', 'ports' => $row['ports'] ?? '');
  }

  $matchesById = array();
  $classesById = array();
  $stmt = $this->database->connection()->prepare("SELECT id, name, accuracy FROM scan_snapshot_os_matches WHERE snapshot_id=:id ORDER BY position, id");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $matchesById[(int)$row['id']] = count($scan['os_matches']);
    $scan['os_matches'][] = array('name' => $row['name'], 'accuracy' => (int)$row['accuracy'], 'classes' => array());
  }
  $stmt = $this->database->connection()->prepare("SELECT c.* FROM scan_snapshot_os_classes c INNER JOIN scan_snapshot_os_matches m ON m.id=c.os_match_id WHERE m.snapshot_id=:id ORDER BY c.os_match_id, c.position, c.id");
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
  $stmt = $this->database->connection()->prepare("SELECT c.os_class_id, c.cpe FROM scan_snapshot_os_cpes c INNER JOIN scan_snapshot_os_classes oc ON oc.id=c.os_class_id INNER JOIN scan_snapshot_os_matches m ON m.id=oc.os_match_id WHERE m.snapshot_id=:id ORDER BY c.os_class_id, c.position");
  $stmt->execute(array('id' => $snapshotId));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $indexes = $classesById[(int)$row['os_class_id']] ?? null;
    if ($indexes !== null)
      $scan['os_matches'][$indexes[0]]['classes'][$indexes[1]]['cpes'][] = $row['cpe'];
  }
  $scan['os'] = $this->codec->selectOsMatches($scan['os_matches']);

  $stmt = $this->database->connection()->prepare("SELECT protocol, port, ttl, ip, hostname, rtt FROM scan_snapshot_trace_hops WHERE snapshot_id=:id ORDER BY position, id");
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
private function scanNullIfEmpty(string $value): ?string {
  return trim($value) === '' ? null : $value;
}
}
