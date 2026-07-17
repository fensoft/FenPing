<?php

declare(strict_types=1);

namespace FenPing\Status;

use FenPing\Database\DatabaseManager;
use PDO;

final readonly class StatusHistoryCleaner
{
    public function __construct(
        private DatabaseManager $database,
        private int $minimumReclaimableBytes = 16 * 1024 * 1024,
        private float $minimumReclaimableRatio = 0.20,
    ) {
    }

    /** @return array{deleted: int, deleted_by_age: int, deleted_by_limit: int, remaining: int, compacted: bool, database_bytes_before: int, database_bytes_after: int, reclaimable_bytes_before: int, reclaimed_bytes: int} */
    public function clean(int $retentionDays = 365, int $maxEventsPerIp = 1000): array
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('status history retention days must be a positive integer');
        }
        if ($maxEventsPerIp < 1) {
            throw new \InvalidArgumentException('status history maximum events per IP must be a positive integer');
        }

        $result = $this->database->immediate(function (PDO $database) use ($retentionDays, $maxEventsPerIp): array {
            $expired = $database->prepare("
                DELETE FROM stats
                WHERE date_end < datetime('now', :retention)
            ");
            $expired->execute(['retention' => '-' . $retentionDays . ' days']);
            $deletedByAge = $expired->rowCount();

            $overLimit = $database->prepare("
                DELETE FROM stats
                WHERE id IN (
                    SELECT id
                    FROM (
                        SELECT
                            id,
                            ROW_NUMBER() OVER (PARTITION BY ip ORDER BY id DESC) AS event_number
                        FROM stats
                    ) ranked
                    WHERE event_number > :max_events
                )
            ");
            $overLimit->bindValue('max_events', $maxEventsPerIp, PDO::PARAM_INT);
            $overLimit->execute();
            $deletedByLimit = $overLimit->rowCount();
            $remaining = (int) $database->query('SELECT COUNT(*) FROM stats')->fetchColumn();

            return [
                'deleted' => $deletedByAge + $deletedByLimit,
                'deleted_by_age' => $deletedByAge,
                'deleted_by_limit' => $deletedByLimit,
                'remaining' => $remaining,
            ];
        });

        $database = $this->database->connection();
        $before = $this->databaseSpace($database);
        $compact = $before['reclaimable'] >= $this->minimumReclaimableBytes
            && $before['allocated'] > 0
            && $before['reclaimable'] / $before['allocated'] >= $this->minimumReclaimableRatio;
        if ($compact) {
            $database->exec('VACUUM');
            $database->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
        }
        $after = $this->databaseSpace($database);

        return $result + [
            'compacted' => $compact,
            'database_bytes_before' => $before['allocated'],
            'database_bytes_after' => $after['allocated'],
            'reclaimable_bytes_before' => $before['reclaimable'],
            'reclaimed_bytes' => max(0, $before['allocated'] - $after['allocated']),
        ];
    }

    /** @return array{allocated: int, reclaimable: int} */
    private function databaseSpace(PDO $database): array
    {
        $pageSize = (int) $database->query('PRAGMA page_size')->fetchColumn();
        $pageCount = (int) $database->query('PRAGMA page_count')->fetchColumn();
        $freePages = (int) $database->query('PRAGMA freelist_count')->fetchColumn();
        return [
            'allocated' => $pageSize * $pageCount,
            'reclaimable' => $pageSize * $freePages,
        ];
    }
}
