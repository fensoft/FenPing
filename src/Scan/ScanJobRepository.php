<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;

final readonly class ScanJobRepository
{
    public function __construct(private Backend $backend, private DatabaseManager $database)
    {
    }

    public function enqueue(string $ip, string $profile, string $source = 'manual'): array { return $this->backend->scanMetadataEnqueue($ip, $profile, $source); }
    public function findJob(int $id): ?array { return $this->backend->scanMetadataJobById($id); }
    public function claimQueued(int $concurrency): array { return $this->backend->scanMetadataClaimQueued($concurrency); }
    public function queuedCount(): int { return $this->backend->scanMetadataQueuedCount(); }
    public function runningCount(): int { return $this->backend->scanMetadataRunningCount(); }
    public function expireRunning(int $seconds): int { return $this->backend->scanMetadataExpireStaleRunning($seconds); }
    public function start(string $ip, string $profile): int { return $this->backend->scanMetadataStart($ip, $profile); }
    public function complete(int $id, array $scan): bool { return $this->backend->scanMetadataComplete($id, $scan); }
    public function fail(int $id, string $error): void { $this->backend->scanMetadataFailed($id, $error); }
    public function timeout(int $id, string $error): void { $this->backend->scanMetadataTimedOut($id, $error); }
    public function cancel(string $ip, int $id): array { return $this->backend->scanMetadataRequestCancel($ip, $id); }
    public function cancellationRequested(int $id): bool { return $this->backend->scanMetadataCancellationRequested($id); }
    public function markCancelled(int $id): void { $this->backend->scanMetadataCancelled($id); }
    public function updateProgress(int $id, string $phase, int $percent): void { $this->backend->scanMetadataUpdateProgress($id, $phase, $percent); }
    public function latest(string $ip): ?array { return $this->backend->scanMetadataLatest($ip); }
    public function bestResult(string $ip, ?string $profile = null): ?array { return $this->backend->scanMetadataBestResult($ip, $profile); }
    public function previousResult(string $ip, string $profile, int $beforeId): ?array { return $this->backend->scanMetadataPreviousResult($ip, $profile, $beforeId); }
    public function byId(string $ip, int $id): ?array { return $this->backend->scanMetadataById($ip, $id); }
    public function history(string $ip, int $limit = 30): array { return $this->backend->scanMetadataHistory($ip, $limit); }
    public function forIp(string $ip, int $limit = 50): array { return $this->backend->scanMetadataForIp($ip, $limit); }
    public function queue(int $limit = 100): array { return $this->backend->scanMetadataQueue($limit); }
    public function policySummary(): array { return $this->backend->scanPolicySummary(); }
    public function latestUsableByIp(): array { return $this->backend->scanMetadataLatestUsableByIp(); }
}
