<?php

declare(strict_types=1);

namespace FenPing\Health;

use FenPing\Database\DatabaseManager;
use FenPing\Support\Clock;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Realtime\NullLiveUpdatePublisher;
use PDO;
use Throwable;

final readonly class OperationTracker
{
    private const FAILURE_RETENTION_DAYS = 30;

    private LiveUpdatePublisher $liveUpdates;

    public function __construct(private DatabaseManager $database, private Clock $clock, ?LiveUpdatePublisher $liveUpdates = null)
    {
        $this->liveUpdates = $liveUpdates ?? new NullLiveUpdatePublisher();
    }

    public function started(string $operation): void
    {
        if ($this->bestEffort(function (PDO $database) use ($operation): void {
            $statement = $database->prepare("
                INSERT INTO operation_status (operation, state, last_started_at)
                VALUES (:operation, 'running', :occurred_at)
                ON CONFLICT(operation) DO UPDATE SET
                  state='running',
                  last_started_at=excluded.last_started_at
            ");
            $statement->execute($this->parameters($operation));
        })) {
            $this->liveUpdates->publish(LiveUpdateScope::Operations);
        }
    }

    public function succeeded(string $operation): void
    {
        if ($this->bestEffort(function (PDO $database) use ($operation): void {
            $statement = $database->prepare("
                INSERT INTO operation_status (
                  operation, state, last_started_at, last_finished_at,
                  last_success_at, success_count
                )
                VALUES (:operation, 'success', :occurred_at, :occurred_at, :occurred_at, 1)
                ON CONFLICT(operation) DO UPDATE SET
                  state='success',
                  last_finished_at=excluded.last_finished_at,
                  last_success_at=excluded.last_success_at,
                  last_error=NULL,
                  success_count=operation_status.success_count+1
            ");
            $statement->execute($this->parameters($operation));
        })) {
            $this->liveUpdates->publish(LiveUpdateScope::Operations);
        }
    }

    public function failed(string $operation, string $error): void
    {
        $error = $this->sanitize($error);
        if ($this->bestEffort(function (PDO $database) use ($operation, $error): void {
            $parameters = $this->parameters($operation) + ['error' => $error];
            $statement = $database->prepare("
                INSERT INTO operation_status (
                  operation, state, last_started_at, last_finished_at,
                  last_failure_at, last_error, failure_count
                )
                VALUES (
                  :operation, 'failure', :occurred_at, :occurred_at,
                  :occurred_at, :error, 1
                )
                ON CONFLICT(operation) DO UPDATE SET
                  state='failure',
                  last_finished_at=excluded.last_finished_at,
                  last_failure_at=excluded.last_failure_at,
                  last_error=excluded.last_error,
                  failure_count=operation_status.failure_count+1
            ");
            $statement->execute($parameters);
            $failure = $database->prepare(
                'INSERT INTO operation_failures (operation, failed_at, error) '
                . 'VALUES (:operation, :occurred_at, :error)',
            );
            $failure->execute($parameters);
            $database->exec(
                "DELETE FROM operation_failures WHERE failed_at<datetime('now', '-"
                . self::FAILURE_RETENTION_DAYS . " days')",
            );
        })) {
            $this->liveUpdates->publish(LiveUpdateScope::Operations);
        }
    }

    public function statuses(): array
    {
        try {
            $rows = $this->database->connection()
                ->query('SELECT * FROM operation_status ORDER BY operation')
                ->fetchAll(PDO::FETCH_ASSOC);
            $statuses = [];
            foreach ($rows as $row) {
                $statuses[(string) $row['operation']] = [
                    'state' => (string) $row['state'],
                    'last_started_at' => $row['last_started_at'],
                    'last_finished_at' => $row['last_finished_at'],
                    'last_success_at' => $row['last_success_at'],
                    'last_failure_at' => $row['last_failure_at'],
                    'last_error' => $row['last_error'],
                    'success_count' => (int) $row['success_count'],
                    'failure_count' => (int) $row['failure_count'],
                ];
            }
            return $statuses;
        } catch (Throwable) {
            return [];
        }
    }

    public function recentFailures(int $hours): array
    {
        try {
            $hours = max(1, min(self::FAILURE_RETENTION_DAYS * 24, $hours));
            $statement = $this->database->connection()->prepare("
                SELECT operation, COUNT(*) AS failures, MAX(failed_at) AS last_failure_at
                FROM operation_failures
                WHERE failed_at>=datetime('now', '-$hours hours')
                GROUP BY operation
            ");
            $statement->execute();
            $failures = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $failures[(string) $row['operation']] = [
                    'count' => (int) $row['failures'],
                    'last_failure_at' => $row['last_failure_at'],
                ];
            }
            return $failures;
        } catch (Throwable) {
            return [];
        }
    }

    private function parameters(string $operation): array
    {
        return [
            'operation' => substr($operation, 0, 64),
            'occurred_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
    }

    private function bestEffort(callable $callback): bool
    {
        try {
            $callback($this->database->connection());
            return true;
        } catch (Throwable) {
            // Health recording must never change the outcome of the operation.
            return false;
        }
    }

    private function sanitize(string $error): string
    {
        $error = preg_replace('#https?://\S+#i', '[url]', $error) ?? $error;
        $error = preg_replace('/\s+/', ' ', trim($error)) ?: 'operation failed';
        return substr($error, 0, 500);
    }
}
