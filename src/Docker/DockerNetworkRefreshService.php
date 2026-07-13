<?php

declare(strict_types=1);

namespace FenPing\Docker;

use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Realtime\NullLiveUpdatePublisher;
use RuntimeException;
use Throwable;

final readonly class DockerNetworkRefreshService
{
    private LiveUpdatePublisher $liveUpdates;

    public function __construct(
        private DockerNetworkSource $source,
        private DockerNetworkCache $cache,
        private string $lockPath = '/run/fenping/docker-networks-refresh.lock',
        private int $apiFreshnessSeconds = 5,
        ?LiveUpdatePublisher $liveUpdates = null,
    ) {
        $this->liveUpdates = $liveUpdates ?? new NullLiveUpdatePublisher();
    }

    /** @return array{status: string, networks: int, updated_at: ?int} */
    public function refresh(bool $force = true, bool $waitForLock = true): array
    {
        if (!$this->source->available()) {
            return ['status' => 'skipped', 'networks' => count($this->cache->networks()), 'updated_at' => $this->cache->updatedAt()];
        }
        $directory = dirname($this->lockPath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('failed to create Docker network refresh directory');
        }
        $lock = fopen($this->lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('failed to open Docker network refresh lock');
        }
        $operation = LOCK_EX | ($waitForLock ? 0 : LOCK_NB);
        if (!flock($lock, $operation)) {
            fclose($lock);
            return ['status' => 'unchanged', 'networks' => count($this->cache->networks()), 'updated_at' => $this->cache->updatedAt()];
        }
        try {
            $updatedAt = $this->cache->updatedAt();
            $previousState = [
                'networks' => $this->cache->networks(),
                'names' => $this->cache->networkNames(),
            ];
            if (!$force && $updatedAt !== null && $updatedAt >= time() - $this->apiFreshnessSeconds) {
                return ['status' => 'unchanged', 'networks' => count($this->cache->networks()), 'updated_at' => $updatedAt];
            }
            try {
                $networks = $this->source->networks();
            } catch (Throwable $error) {
                if ($force) {
                    throw $error;
                }
                return ['status' => 'stale', 'networks' => count($this->cache->networks()), 'updated_at' => $this->cache->updatedAt()];
            }
            $now = time();
            $this->cache->replace($networks, $now);
            $currentState = [
                'networks' => $this->cache->networks(),
                'names' => $this->cache->networkNames(),
            ];
            if ($previousState !== $currentState) {
                $this->liveUpdates->publish(LiveUpdateScope::Networks);
            }
            return ['status' => 'refreshed', 'networks' => count($networks), 'updated_at' => $now];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
