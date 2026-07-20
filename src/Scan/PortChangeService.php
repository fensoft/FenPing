<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Database\DatabaseManager;
use PDO;
use Throwable;

final readonly class PortChangeService
{
    public function __construct(
        private DatabaseManager $database,
        private ScanPolicyService $policy,
        private SnapshotRepository $snapshots,
        private PortKnowledgeMerger $portKnowledge,
    ) {
    }

    public function backfill(): int { return $this->scanPortChangesBackfill(); }
    public function compare(array $previous, array $current): array { return $this->scanComparePorts($previous, $current); }
    public function prune(): void
    {
        $statement = $this->database->connection()->prepare(
            "DELETE FROM scan_port_changes WHERE created_at < datetime(CURRENT_TIMESTAMP, :retention)"
        );
        $statement->execute(["retention" => "-30 days"]);
    }

public function scanRecordPortChanges(array $job, array $scan, ?string $createdAt = null): int {
  $previous = $this->scanEffectivePortsBefore((string)$job['ip'], (int)$job['id']);
  $current = $this->scanApplyPortObservation($previous, $scan, (string)$job['mode']);
  $changes = $this->scanComparePorts($previous, $current);
  if (count($changes) === 0)
    return 0;

  $insert = $this->database->connection()->prepare("
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
      'previous_service' => $this->scanNullIfEmpty($change['previous_service']),
      'previous_version' => $this->scanNullIfEmpty($change['previous_version']),
      'current_service' => $this->scanNullIfEmpty($change['current_service']),
      'current_version' => $this->scanNullIfEmpty($change['current_version']),
      'created_at' => $createdAt
    ));
    $inserted += $insert->rowCount();
  }
  return $inserted;
}

public function scanPortChangesBackfill(): int {
  $database = $this->database->connection();
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
    $this->prune();
    return 0;
  }

  $this->database->beginImmediate();
  try {
    $inserted = 0;
    $mark = $database->prepare("UPDATE scans SET port_changes_processed=1 WHERE id=:id");
    foreach ($rows as $row) {
      $inserted += $this->scanRecordPortChanges(
        array('id' => (int)$row['id'], 'ip' => $row['ip'], 'mode' => $row['mode']),
        $this->snapshots->read((string)$row['ip'], $this->policy->normalizeMetadata($row)) ?? array(),
        ($row['change_date'] ?? '') === '' ? null : (string)$row['change_date']
      );
      $mark->execute(array('id' => $row['id']));
    }
    $this->database->commit();
    $this->prune();
    return $inserted;
  } catch (Throwable $e) {
    $this->database->rollback();
    throw $e;
  }
}

public function scanEffectivePortsBefore(string $ip, int $beforeId): array {
  $deep = $this->scanPreviousSnapshotResult($ip, 'deep', $beforeId);
  $afterId = $deep === null ? 0 : (int)$deep['id'];
  $ports = $deep === null
    ? array()
    : $this->scanOpenPortMap($deep['scan']);

  $stmt = $this->database->connection()->prepare("
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
    $observation = $this->snapshots->read($ip, $this->policy->normalizeMetadata($row));
    if ($observation !== null)
      $ports = $this->scanApplyPortObservation($ports, $observation, (string)$row['mode']);
  }

  return $ports;
}

public function scanPreviousSnapshotResult(string $ip, string $mode, int $beforeId): ?array {
  $stmt = $this->database->connection()->prepare("
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
  $metadata = $this->policy->normalizeMetadata($row);
  $scan = $this->snapshots->read($ip, $metadata);
  return $scan === null ? null : array('id' => (int)$row['id'], 'scan' => $scan);
}

public function scanOpenPortMap(array $scan): array {
  $ports = array();
  foreach ($scan['ports'] ?? array() as $port) {
    if (strtolower((string)($port['state'] ?? '')) !== 'open')
      continue;
    $key = $this->scanPortKey($port);
    if ($key !== null)
      $ports[$key] = $port;
  }
  return $ports;
}

public function scanApplyPortObservation(array $base, array $scan, string $mode): array {
  $scope = $scan['port_scope'] ?? array();
  if (count($scope) === 0 && $mode === 'deep')
    $scope = array('tcp' => array(array(1, 65535)));
  $known = $base;

  foreach ($base as $key => $port) {
    if ($this->scanPortIsInScope($port, $scope))
      unset($base[$key]);
  }

  foreach ($this->scanOpenPortMap($scan) as $key => $observed)
    $base[$key] = $this->portKnowledge->merge($known[$key] ?? null, $observed);
  ksort($base);
  return $base;
}

public function scanPortIsInScope(array $port, array $scope): bool {
  $protocol = strtolower((string)($port['protocol'] ?? ''));
  $number = (int)($port['port'] ?? 0);
  foreach ($scope[$protocol] ?? array() as $range) {
    if ($number >= $range[0] && $number <= $range[1])
      return true;
  }
  return false;
}

public function scanPortKey(array $port): ?string {
  $protocol = strtolower(trim((string)($port['protocol'] ?? '')));
  $number = (int)($port['port'] ?? 0);
  if ($protocol === '' || $number < 1 || $number > 65535)
    return null;
  return $protocol . '|' . $number;
}

public function scanComparePorts(array $previous, array $current): array {
  $changes = array();
  foreach (array_unique(array_merge(array_keys($previous), array_keys($current))) as $key) {
    $before = $previous[$key] ?? null;
    $after = $current[$key] ?? null;
    if ($before === null) {
      $changes[] = $this->scanPortChange('appeared', null, $after);
    } elseif ($after === null) {
      $changes[] = $this->scanPortChange('disappeared', $before, null);
    } elseif ($this->scanPortVersionChanged($before, $after)) {
      $changes[] = $this->scanPortChange('changed', $before, $after);
    }
  }
  return $changes;
}

public function scanPortVersionChanged(array $before, array $after): bool {
  $beforeService = trim((string)($before['service'] ?? ''));
  $afterService = trim((string)($after['service'] ?? ''));
  if ($beforeService !== '' && $afterService !== '' && $beforeService !== $afterService)
    return true;

  $beforeVersion = trim((string)($before['details'] ?? ''));
  $afterVersion = trim((string)($after['details'] ?? ''));
  return $beforeVersion !== '' && $afterVersion !== '' && $beforeVersion !== $afterVersion;
}

public function scanPortChange(string $type, ?array $before, ?array $after): array {
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

public function scanNullIfEmpty(string $value): ?string {
  $value = trim($value);
  return $value === '' ? null : $value;
}
}
