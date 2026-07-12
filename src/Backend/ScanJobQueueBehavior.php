<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

trait ScanJobQueueBehavior
{
public function scanMetadataEnqueue(string $ip, string $mode): array {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new InvalidArgumentException('invalid scan ip');
  if (!$this->scanProfileIsValid($mode))
    throw new InvalidArgumentException('invalid scan profile');

  $database = $this->db();
  $this->dbBeginImmediate($database);
  try {
    $stmt = $database->prepare("
      SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
      FROM scans
      WHERE ip=:ip AND state IN ('queued', 'running')
      ORDER BY CASE state WHEN 'running' THEN 0 ELSE 1 END, id DESC
    ");
    $stmt->execute(array('ip' => $ip));
    $activeJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requestedRank = $this->scanProfileRank($mode);
    $covering = null;
    foreach ($activeJobs as $active) {
      if ($this->scanProfileRank((string)$active['mode']) < $requestedRank)
        continue;
      if ($covering === null || $this->scanProfileRank((string)$active['mode']) > $this->scanProfileRank((string)$covering['mode']))
        $covering = $active;
    }
    if ($covering !== null) {
      $this->dbCommit($database);
      return array('metadata' => $this->scanNormalizeMetadata($covering), 'created' => false);
    }

    foreach ($activeJobs as $active) {
      if ($active['state'] !== 'queued' || $this->scanProfileRank((string)$active['mode']) >= $requestedRank)
        continue;
      $update = $database->prepare("UPDATE scans SET mode=:mode WHERE id=:id AND state='queued'");
      $update->execute(array('mode' => $mode, 'id' => $active['id']));
      if ($update->rowCount() === 1) {
        $active['mode'] = $mode;
        $this->dbCommit($database);
        return array('metadata' => $this->scanNormalizeMetadata($active), 'created' => false);
      }
    }

    $insert = $database->prepare("
      INSERT INTO scans (ip, mode, state, date_begin, ports_count)
      VALUES (:ip, :mode, 'queued', NULL, 0)
    ");
    $insert->execute(array('ip' => $ip, 'mode' => $mode));
    $metadata = $this->scanMetadataJobById((int)$database->lastInsertId());
    if ($metadata === null)
      throw new RuntimeException('failed to read queued scan');
    $this->dbCommit($database);
    return array('metadata' => $metadata, 'created' => true);
  } catch (Throwable $error) {
    $this->dbRollback($database);
    throw $error;
  }
}

public function scanMetadataJobById(int $id): ?array {
  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE id=:id
    LIMIT 1
  ");
  $stmt->execute(array('id' => $id));
  $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
  return $metadata === false ? null : $this->scanNormalizeMetadata($metadata);
}

public function scanMetadataClaimQueued(int $concurrency): array {
  $concurrency = max(1, min(20, $concurrency));
  $database = $this->db();
  $this->dbBeginImmediate($database);
  try {
    $running = (int)$database->query("SELECT COUNT(*) FROM scans WHERE state='running'")->fetchColumn();
    $limit = max(0, $concurrency - $running);
    if ($limit === 0) {
      $this->dbCommit($database);
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
      $job = $this->scanMetadataJobById((int)$id);
      if ($job !== null)
        $jobs[] = $job;
    }
    $this->dbCommit($database);
    return $jobs;
  } catch (Throwable $error) {
    $this->dbRollback($database);
    throw $error;
  }
}

public function scanMetadataQueuedCount(): int {
  return (int)$this->db()->query("SELECT COUNT(*) FROM scans WHERE state='queued'")->fetchColumn();
}

public function scanMetadataRunningCount(): int {
  return (int)$this->db()->query("SELECT COUNT(*) FROM scans WHERE state='running'")->fetchColumn();
}

public function scanMetadataExpireStaleRunning(int $maxSeconds): int {
  $maxSeconds = max(60, $maxSeconds);
  $stmt = $this->db()->prepare("
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

public function scanMetadataStart(string $ip, string $mode): int {
  $stmt = $this->db()->prepare("
    INSERT INTO scans (ip, mode, state, date_begin, ports_count)
    VALUES (:ip, :mode, 'running', CURRENT_TIMESTAMP, 0)
  ");
  $stmt->execute(array('ip' => $ip, 'mode' => $mode));
  return (int)$this->db()->lastInsertId();
}

public function scanMetadataComplete(int $id, array $scan): bool {
  $database = $this->db();
  $this->dbBeginImmediate($database);

  try {
    $job = $this->scanMetadataRawById($id, true);
    if ($job === null)
      throw new RuntimeException("scan job $id not found");

    $snapshotId = null;
    $changed = 0;
    if (($scan['status'] ?? '') === 'up') {
      $snapshot = $this->scanEnsureSnapshot($job, $scan);
      $snapshotId = $snapshot['id'];
      $changed = $snapshot['changed'] ? 1 : 0;
      $this->scanRecordPortChanges($job, $scan);
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
      'scanner' => $this->scanNullIfEmpty((string)($scan['scanner'] ?? '')),
      'scanner_version' => $this->scanNullIfEmpty((string)($scan['scanner_version'] ?? '')),
      'scan_args' => $this->scanNullIfEmpty((string)($scan['args'] ?? '')),
      'host_reason' => $this->scanNullIfEmpty((string)($scan['status_reason'] ?? '')),
      'host_reason_ttl' => $scan['status_reason_ttl'] ?? null,
      'last_boot' => $this->scanNormalizeDate((string)($scan['uptime'] ?? '')),
      'uptime_seconds' => $scan['uptime_seconds'] ?? null,
      'distance' => $scan['distance'] ?? null
    ));
    $this->dbCommit($database);
    return $changed === 1;
  } catch (Throwable $e) {
    $this->dbRollback($database);
    throw $e;
  }
}

public function scanNormalizeDate(string $value): ?string {
  $value = trim($value);
  if ($value === '')
    return null;
  $timestamp = strtotime($value);
  return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

public function scanMetadataRawById(int $id, bool $forUpdate = false): ?array {
  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE id=:id
    LIMIT 1
  ");
  $stmt->execute(array('id' => $id));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row === false ? null : $row;
}
}
