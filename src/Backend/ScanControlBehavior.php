<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use RuntimeException;
use Throwable;
use FenPing\Realtime\LiveUpdateScope;

trait ScanControlBehavior
{
public function scanMetadataFailed(int $id, string $error): void {
  $stmt = $this->db()->prepare("
    UPDATE scans
    SET state='failed',
        date_end=CURRENT_TIMESTAMP,
        duration=CASE WHEN date_begin IS NULL THEN NULL ELSE MAX(0, unixepoch(CURRENT_TIMESTAMP)-unixepoch(date_begin)) END,
        progress_phase='failed',
        progress_updated_at=CURRENT_TIMESTAMP,
        error=:error
    WHERE id=:id AND state='running' AND cancel_requested_at IS NULL
  ");
  $stmt->execute(array('id' => $id, 'error' => $error));
  if ($stmt->rowCount() > 0)
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
}

public function scanMetadataTimedOut(int $id, string $error): void {
  $stmt = $this->db()->prepare("
    UPDATE scans
    SET state='timeout',
        date_end=CURRENT_TIMESTAMP,
        duration=CASE WHEN date_begin IS NULL THEN NULL ELSE MAX(0, unixepoch(CURRENT_TIMESTAMP)-unixepoch(date_begin)) END,
        progress_phase='timeout',
        progress_updated_at=CURRENT_TIMESTAMP,
        error=:error
    WHERE id=:id AND state='running' AND cancel_requested_at IS NULL
  ");
  $stmt->execute(array('id' => $id, 'error' => $error));
  if ($stmt->rowCount() > 0)
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
}

public function scanMetadataRawById(int $id, bool $forUpdate = false): ?array {
  $stmt = $this->db()->prepare("
    SELECT id, ip, mode, state, network, request_source, queued_at, progress_percent, progress_phase,
           progress_updated_at, cancel_requested_at, status, date_begin, date_end, duration, ports_count, snapshot_id, result_changed, error
    FROM scans
    WHERE id=:id
    LIMIT 1
  ");
  $stmt->execute(array('id' => $id));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row === false ? null : $row;
}

public function scanNetworkCidrForIp(string $ip): string {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new InvalidArgumentException('invalid scan ip');
  $parts = explode('.', $ip);
  return implode('.', array_slice($parts, 0, 3)) . '.0/24';
}

public function scanMetadataCancellationRequested(int $id): bool {
  $stmt = $this->db()->prepare("SELECT cancel_requested_at FROM scans WHERE id=:id AND state='running'");
  $stmt->execute(array('id' => $id));
  $value = $stmt->fetchColumn();
  return $value !== false && $value !== null;
}

public function scanMetadataRequestCancel(string $ip, int $id): array {
  $database = $this->db();
  $this->dbBeginImmediate($database);
  try {
    $job = $this->scanMetadataRawById($id, true);
    if ($job === null || $job['ip'] !== $ip)
      throw new OutOfBoundsException('scan not found');
    if ($job['state'] === 'cancelled') {
      $this->dbCommit($database);
      return array('status' => 200, 'metadata' => $this->scanNormalizeMetadata($job));
    }
    if (!in_array($job['state'], array('queued', 'running'), true))
      throw new RuntimeException('scan is not active');

    if ($job['state'] === 'queued') {
      $stmt = $database->prepare("
        UPDATE scans SET state='cancelled', date_end=CURRENT_TIMESTAMP, progress_phase='cancelled',
          progress_updated_at=CURRENT_TIMESTAMP, error='cancelled by operator'
        WHERE id=:id AND state='queued'
      ");
      $status = 200;
    } else {
      $stmt = $database->prepare("
        UPDATE scans SET cancel_requested_at=COALESCE(cancel_requested_at, CURRENT_TIMESTAMP),
          progress_phase='cancelling', progress_updated_at=CURRENT_TIMESTAMP
        WHERE id=:id AND state='running'
      ");
      $status = 202;
    }
    $stmt->execute(array('id' => $id));
    $metadata = $this->scanMetadataRawById($id, true);
    $this->dbCommit($database);
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
    return array('status' => $status, 'metadata' => $this->scanNormalizeMetadata($metadata ?? $job));
  } catch (Throwable $error) {
    $this->dbRollback($database);
    throw $error;
  }
}

public function scanMetadataCancelled(int $id): void {
  $stmt = $this->db()->prepare("
    UPDATE scans SET state='cancelled', date_end=CURRENT_TIMESTAMP,
      duration=CASE WHEN date_begin IS NULL THEN NULL ELSE MAX(0, unixepoch(CURRENT_TIMESTAMP)-unixepoch(date_begin)) END,
      progress_phase='cancelled', progress_updated_at=CURRENT_TIMESTAMP,
      error=COALESCE(NULLIF(error, ''), 'cancelled by operator')
    WHERE id=:id AND state IN ('queued', 'running')
  ");
  $stmt->execute(array('id' => $id));
  if ($stmt->rowCount() > 0)
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
}

public function scanMetadataUpdateProgress(int $id, string $phase, int $percent): void {
  $percent = max(0, min(99, $percent));
  $stmt = $this->db()->prepare("
    UPDATE scans SET progress_percent=MAX(COALESCE(progress_percent, 0), :percent),
      progress_phase=:phase, progress_updated_at=CURRENT_TIMESTAMP
    WHERE id=:id AND state='running' AND cancel_requested_at IS NULL
      AND (COALESCE(progress_phase, '')<>:phase_compare OR COALESCE(progress_percent, 0)<:percent_compare)
  ");
  $stmt->bindValue(':id', $id, PDO::PARAM_INT);
  $stmt->bindValue(':phase', $phase, PDO::PARAM_STR);
  $stmt->bindValue(':phase_compare', $phase, PDO::PARAM_STR);
  $stmt->bindValue(':percent', $percent, PDO::PARAM_INT);
  $stmt->bindValue(':percent_compare', $percent, PDO::PARAM_INT);
  $stmt->execute();
  if ($stmt->rowCount() > 0)
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
}
}
