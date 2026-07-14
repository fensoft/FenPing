<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Database\DatabaseManager;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class ScanControlStore
{
    public function __construct(
        private DatabaseManager $database,
        private LiveUpdatePublisher $liveUpdates,
        private ScanPolicyService $policy,
    ) {
    }

public function fail(int $id, string $error): void {
  $stmt = $this->database->connection()->prepare("
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

public function timeout(int $id, string $error): void {
  $stmt = $this->database->connection()->prepare("
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

public function rawById(int $id, bool $forUpdate = false): ?array {
  $stmt = $this->database->connection()->prepare("
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

public function networkCidrForIp(string $ip): string {
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new InvalidArgumentException('invalid scan ip');
  $parts = explode('.', $ip);
  return implode('.', array_slice($parts, 0, 3)) . '.0/24';
}

public function cancellationRequested(int $id): bool {
  $stmt = $this->database->connection()->prepare("SELECT state, cancel_requested_at FROM scans WHERE id=:id AND state IN ('running', 'cancelled')");
  $stmt->execute(array('id' => $id));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row !== false && ($row['state'] === 'cancelled' || $row['cancel_requested_at'] !== null);
}

public function requestCancel(string $ip, int $id): array {
  $database = $this->database->connection();
  $this->database->beginImmediate();
  try {
    $job = $this->rawById($id, true);
    if ($job === null || $job['ip'] !== $ip)
      throw new OutOfBoundsException('scan not found');
    if ($job['state'] === 'cancelled') {
      $this->database->commit();
      return array('status' => 200, 'metadata' => $this->policy->normalizeMetadata($job));
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
        UPDATE scans SET state='cancelled',
          cancel_requested_at=COALESCE(cancel_requested_at, CURRENT_TIMESTAMP),
          date_end=CURRENT_TIMESTAMP,
          duration=CASE WHEN date_begin IS NULL THEN NULL ELSE MAX(0, unixepoch(CURRENT_TIMESTAMP)-unixepoch(date_begin)) END,
          progress_phase='cancelled', progress_updated_at=CURRENT_TIMESTAMP,
          error=COALESCE(NULLIF(error, ''), 'cancelled by operator')
        WHERE id=:id AND state='running'
      ");
      $status = 200;
    }
    $stmt->execute(array('id' => $id));
    $metadata = $this->rawById($id, true);
    $this->database->commit();
    $this->liveUpdates->publish(LiveUpdateScope::Scans);
    return array('status' => $status, 'metadata' => $this->policy->normalizeMetadata($metadata ?? $job));
  } catch (Throwable $error) {
    $this->database->rollback();
    throw $error;
  }
}

public function cancelled(int $id): void {
  $stmt = $this->database->connection()->prepare("
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

public function updateProgress(int $id, string $phase, int $percent): void {
  $percent = max(0, min(99, $percent));
  $stmt = $this->database->connection()->prepare("
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
