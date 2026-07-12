<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

trait ScanRetentionBehavior
{
public function scanPruneHistory(string $ip): void {
  $this->scanPruneOldHistory($ip);
  $this->scanPruneOrphanSnapshots();
  $this->scanPrunePortChanges();
}

public function scanPrunePortChanges(): void {
  $days = self::SCAN_HISTORY_DAYS;
  $this->db()->exec("DELETE FROM scan_port_changes WHERE created_at < datetime('now', '-$days days')");
}

public function scanPruneOldHistory(string $ip): void {
  $days = self::SCAN_HISTORY_DAYS;
  $stmt = $this->db()->prepare("
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
    $this->scanDeleteMetadata($row);
}

public function scanPruneOrphanSnapshots(): void {
  $this->db()->exec("
    DELETE FROM scan_snapshots
    WHERE NOT EXISTS (SELECT 1 FROM scans WHERE scans.snapshot_id=scan_snapshots.id)
  ");
}

public function scanDeleteMetadata(array $metadata): void {
  if (isset($metadata['id']) && $metadata['id'] !== null) {
    $stmt = $this->db()->prepare("DELETE FROM scans WHERE id=:id");
    $stmt->execute(array('id' => $metadata['id']));
  }
}
}
