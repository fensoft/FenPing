<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Service\MonitoredServiceRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class ScanJobRepository
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private LiveUpdatePublisher $liveUpdates,
        private ProfileCatalog $profiles,
        private ScanPolicyService $policy,
        private ScanControlStore $control,
        private ScanResultStore $results,
        private SnapshotRepository $snapshots,
        private PortChangeService $portChanges,
        private MonitoredServiceRepository $monitoredServices,
    ) {
    }

    public function fail(int $id, string $error): void { $this->control->fail($id, $error); }
    public function timeout(int $id, string $error): void { $this->control->timeout($id, $error); }
    public function cancel(string $ip, int $id): array { return $this->control->requestCancel($ip, $id); }
    public function cancellationRequested(int $id): bool { return $this->control->cancellationRequested($id); }
    public function markCancelled(int $id): void { $this->control->cancelled($id); }
    public function updateProgress(int $id, string $phase, int $percent): void { $this->control->updateProgress($id, $phase, $percent); }
    public function latest(string $ip): ?array { return $this->results->scanMetadataLatest($ip); }
    public function bestResult(string $ip, ?string $profile = null): ?array { return $this->results->scanMetadataBestResult($ip, $profile); }
    public function previousResult(string $ip, string $profile, int $beforeId): ?array { return $this->results->scanMetadataPreviousResult($ip, $profile, $beforeId); }
    public function byId(string $ip, int $id): ?array { return $this->results->scanMetadataById($ip, $id); }
    public function history(string $ip, int $limit = 30): array { return $this->results->scanMetadataHistory($ip, $limit); }
    public function forIp(string $ip, int $limit = 50): array { return $this->results->scanMetadataForIp($ip, $limit); }
    public function queue(int $limit = 100): array { return $this->results->scanMetadataQueue($limit); }
    public function policySummary(): array { return $this->policy->scanPolicySummary(); }
    public function latestUsableByIp(): array { return $this->results->scanMetadataLatestUsableByIp(); }

public function enqueue(string $ip, string $mode, string $source = 'manual'): array {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new InvalidArgumentException('invalid scan ip');
  if (!$this->profiles->isValid($mode))
    throw new InvalidArgumentException('invalid scan profile');
  if (!in_array($source, array('manual', 'scheduled'), true))
    throw new InvalidArgumentException('invalid scan request source');

  $database = $this->database->connection();
  $network = $this->control->networkCidrForIp($ip);
  $this->database->beginImmediate();
  try {
    $stmt = $database->prepare("
      SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
             progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count,
             snapshot_id, result_changed, error
      FROM scans
      WHERE ip=:ip AND state IN ('queued', 'running')
      ORDER BY CASE state WHEN 'running' THEN 0 ELSE 1 END, id DESC
    ");
    $stmt->execute(array('ip' => $ip));
    $activeJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requestedRank = $this->profiles->rank($mode);
    $covering = null;
    foreach ($activeJobs as $active) {
      if ($this->profiles->rank((string)$active['mode']) < $requestedRank)
        continue;
      if ($covering === null || $this->profiles->rank((string)$active['mode']) > $this->profiles->rank((string)$covering['mode']))
        $covering = $active;
    }
    if ($covering !== null) {
      if ($source === 'manual' && $covering['state'] === 'queued' && $covering['request_source'] !== 'manual') {
        $promote = $database->prepare("UPDATE scans SET request_source='manual' WHERE id=:id AND state='queued'");
        $promote->execute(array('id' => $covering['id']));
        $covering['request_source'] = 'manual';
      }
      $this->database->commit();
      return array('metadata' => $this->policy->normalizeMetadata($covering), 'created' => false);
    }

    foreach ($activeJobs as $active) {
      if ($active['state'] !== 'queued' || $this->profiles->rank((string)$active['mode']) >= $requestedRank)
        continue;
      $update = $database->prepare("
        UPDATE scans
        SET mode=:mode, request_source=CASE WHEN :source='manual' THEN 'manual' ELSE request_source END
        WHERE id=:id AND state='queued'
      ");
      $update->execute(array('mode' => $mode, 'source' => $source, 'id' => $active['id']));
      if ($update->rowCount() === 1) {
        $active['mode'] = $mode;
        if ($source === 'manual') $active['request_source'] = 'manual';
        $this->database->commit();
        return array('metadata' => $this->policy->normalizeMetadata($active), 'created' => false);
      }
    }

    $insert = $database->prepare("
      INSERT INTO scans (ip, mode, state, network, request_source, queued_at, progress_percent,
                         progress_phase, progress_updated_at, date_begin, ports_count)
      VALUES (:ip, :mode, 'queued', :network, :source, CURRENT_TIMESTAMP, 0,
              'queued', CURRENT_TIMESTAMP, NULL, 0)
    ");
    $insert->execute(array('ip' => $ip, 'mode' => $mode, 'network' => $network, 'source' => $source));
    $metadata = $this->findJob((int)$database->lastInsertId());
    if ($metadata === null)
      throw new RuntimeException('failed to read queued scan');
    $this->database->commit();
    return array('metadata' => $metadata, 'created' => true);
  } catch (Throwable $error) {
    $this->database->rollback();
    throw $error;
  }
}

public function findJob(int $id): ?array {
  $stmt = $this->database->connection()->prepare("
    SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
           progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count,
           snapshot_id, result_changed, error
    FROM scans
    WHERE id=:id
    LIMIT 1
  ");
  $stmt->execute(array('id' => $id));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : $this->policy->normalizeMetadata($metadata);
}

public function claimQueued(int $concurrency): array {
  $concurrency = max(1, min($this->config->scanGlobalConcurrency, $concurrency));
  $database = $this->database->connection();
  $this->database->beginImmediate();
  try {
    $running = (int)$database->query("SELECT COUNT(*) FROM scans WHERE state='running'")->fetchColumn();
    $limit = max(0, $concurrency - $running);
    if ($limit === 0) {
      $this->database->commit();
      return array();
    }

    $runningByNetwork = array();
    $stmt = $database->query("SELECT network, COUNT(*) AS total FROM scans WHERE state='running' GROUP BY network");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
      $runningByNetwork[(string)$row['network']] = (int)$row['total'];

    $scheduledStarts = array();
    $stmt = $database->query("
      SELECT network, COUNT(*) AS total
      FROM scans
      WHERE request_source='scheduled' AND date_begin>=datetime('now', '-24 hours')
      GROUP BY network
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
      $scheduledStarts[(string)$row['network']] = (int)$row['total'];

    $stmt = $database->query("
      SELECT queued.id, queued.ip, queued.network, queued.request_source
      FROM scans queued
      WHERE queued.state='queued'
        AND NOT EXISTS (SELECT 1 FROM scans running WHERE running.ip=queued.ip AND running.state='running')
      ORDER BY CASE queued.mode
        WHEN 'quick' THEN 0
        WHEN 'lightweight' THEN 0
        WHEN 'standard' THEN 1
        ELSE 2
      END, queued.id ASC
    ");

    $jobs = array();
    $update = $database->prepare("
      UPDATE scans
      SET state='running', network=:network, date_begin=CURRENT_TIMESTAMP, date_end=NULL, duration=NULL,
          error=NULL, progress_percent=1, progress_phase='starting', progress_updated_at=CURRENT_TIMESTAMP
      WHERE id=:id AND state='queued'
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
      if (count($jobs) >= $limit)
        break;
      $id = (int)$candidate['id'];
      $network = trim((string)$candidate['network']);
      if ($network === '')
        $network = $this->control->networkCidrForIp((string)$candidate['ip']);
      $limits = $this->config->scanLimitsForNetwork($network);
      if (($runningByNetwork[$network] ?? 0) >= $limits['concurrency'])
        continue;
      if ($candidate['request_source'] === 'scheduled'
          && ($scheduledStarts[$network] ?? 0) >= $limits['daily_budget'])
        continue;

      $update->execute(array('id' => $id, 'network' => $network));
      if ($update->rowCount() !== 1)
        continue;
      $runningByNetwork[$network] = ($runningByNetwork[$network] ?? 0) + 1;
      if ($candidate['request_source'] === 'scheduled')
        $scheduledStarts[$network] = ($scheduledStarts[$network] ?? 0) + 1;
      $job = $this->findJob($id);
      if ($job !== null)
        $jobs[] = $job;
    }
    $this->database->commit();
    if ($jobs !== array())
      $this->liveUpdates->publish(LiveUpdateScope::Scans);
    return $jobs;
  } catch (Throwable $error) {
    $this->database->rollback();
    throw $error;
  }
}

public function queuedCount(): int {
  return (int)$this->database->connection()->query("SELECT COUNT(*) FROM scans WHERE state='queued'")->fetchColumn();
}

public function runningCount(): int {
  return (int)$this->database->connection()->query("SELECT COUNT(*) FROM scans WHERE state='running'")->fetchColumn();
}

public function expireRunning(int $maxSeconds): int {
  $maxSeconds = max(60, $maxSeconds);
  $stmt = $this->database->connection()->prepare("
    UPDATE scans
    SET state=CASE WHEN cancel_requested_at IS NULL THEN 'timeout' ELSE 'cancelled' END,
        date_end=CURRENT_TIMESTAMP,
        duration=CASE WHEN date_begin IS NULL THEN NULL ELSE MAX(0, unixepoch(CURRENT_TIMESTAMP)-unixepoch(date_begin)) END,
        progress_phase=CASE WHEN cancel_requested_at IS NULL THEN 'timeout' ELSE 'cancelled' END,
        progress_updated_at=CURRENT_TIMESTAMP,
        error=CASE WHEN cancel_requested_at IS NULL
          THEN COALESCE(NULLIF(error, ''), 'scan worker stopped before completion')
          ELSE COALESCE(NULLIF(error, ''), 'cancelled by operator') END
    WHERE state='running'
      AND (date_begin IS NULL OR date_begin <= datetime('now', '-$maxSeconds seconds'))
  ");
  $stmt->execute();
  $expired = $stmt->rowCount();
  if ($expired > 0)
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
  return $expired;
}

public function start(string $ip, string $mode): int {
  $stmt = $this->database->connection()->prepare("
    INSERT INTO scans (ip, mode, state, network, request_source, date_begin, ports_count,
                       progress_percent, progress_phase, progress_updated_at)
    VALUES (:ip, :mode, 'running', :network, 'manual', CURRENT_TIMESTAMP, 0, 1, 'starting', CURRENT_TIMESTAMP)
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode, 'network' => $this->control->networkCidrForIp($ip)));
  $id = (int)$this->database->connection()->lastInsertId();
  $this->liveUpdates->publish(LiveUpdateScope::Scans);
  return $id;
}

public function complete(int $id, array $scan): bool {
  $database = $this->database->connection();
  $this->database->beginImmediate();

  try {
    $job = $this->control->rawById($id, true);
    if ($job === null)
      throw new RuntimeException("scan job $id not found");
    if ($job['state'] !== 'running' || $job['cancel_requested_at'] !== null) {
      $this->database->rollback();
      $this->control->cancelled($id);
      return false;
    }

    $snapshotId = null;
    $changed = 0;
    if (($scan['status'] ?? '') === 'up') {
      $snapshot = $this->snapshots->scanEnsureSnapshot($job, $scan);
      $snapshotId = $snapshot['id'];
      $changed = $snapshot['changed'] ? 1 : 0;
      $this->portChanges->scanRecordPortChanges($job, $scan);
      $this->monitoredServices->observeScan((string)$job['ip'], $scan);
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
          error=NULL,
          progress_percent=100,
          progress_phase='complete',
          progress_updated_at=CURRENT_TIMESTAMP
      WHERE id=:id AND state='running' AND cancel_requested_at IS NULL
    ");
    $stmt->execute(array(
      'id' => $id,
      'status' => $scan['status'] ?: 'unknown',
      'duration' => $scan['duration'],
      'ports_count' => count($scan['ports'] ?? array()),
      'snapshot_id' => $snapshotId,
      'result_changed' => $changed,
      'scanner' => $this->nullIfEmpty((string)($scan['scanner'] ?? '')),
      'scanner_version' => $this->nullIfEmpty((string)($scan['scanner_version'] ?? '')),
      'scan_args' => $this->nullIfEmpty((string)($scan['args'] ?? '')),
      'host_reason' => $this->nullIfEmpty((string)($scan['status_reason'] ?? '')),
      'host_reason_ttl' => $scan['status_reason_ttl'] ?? null,
      'last_boot' => $this->normalizeDate((string)($scan['uptime'] ?? '')),
      'uptime_seconds' => $scan['uptime_seconds'] ?? null,
      'distance' => $scan['distance'] ?? null
    ));
    $this->database->commit();
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
    return $changed === 1;
  } catch (Throwable $e) {
    $this->database->rollback();
    throw $e;
  }
}

private function normalizeDate(string $value): ?string {
  $value = trim($value);
  if ($value === '')
    return null;
  $timestamp = strtotime($value);
  return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

private function nullIfEmpty(string $value): ?string {
  return trim($value) === '' ? null : $value;
}
}
