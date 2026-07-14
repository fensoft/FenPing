<?php

declare(strict_types=1);

namespace FenPing\Inventory;

use FenPing\Host\HostMetadataRepository;
use RuntimeException;

final readonly class InventoryService
{
    public function __construct(
        private InventoryReadService $read,
        private InventoryRowNormalizer $rows,
        private HostMetadataRepository $metadata,
        private SavedInventoryFilterRepository $filters,
        private InventoryScheduler $scheduler,
    ) {
    }

    public function inventory(): array { return $this->read->forNetwork(); }
    public function forNetwork(?string $networkCidr = null): array { return $this->read->forNetwork($networkCidr); }
    public function latestScans(): array { return $this->read->getLatestScans(); }
    public function run(array $arguments): int { return $this->scheduler->runInventoryCommand($arguments); }
    public function scheduledTargets(array $hosts, ?int $now = null): array { return $this->scheduler->inventoryScheduledTargets($hosts, $now); }
    public function initialUnmanagedHour(string $ip): int { return $this->scheduler->inventoryInitialUnmanagedScanHour($ip); }
    public function availableTags(): array { return $this->rows->inventoryAvailableTags(); }
    public function savedFilters(): array { return $this->metadata->savedInventoryFilters(); }

    public function updateDeviceMetadata(array $body): array
    {
        $network = $this->metadata->normalizeInventoryDeviceIdentityPart($body['network'] ?? null, 'network name');
        $container = $this->metadata->normalizeInventoryDeviceIdentityPart($body['container'] ?? null, 'container name');
        if ($this->metadata->dockerContainerIdentity($network, $container) === null) {
            throw new RuntimeException('container identity is no longer available');
        }
        return $this->metadata->saveInventoryDeviceMetadata($network, $container, $body);
    }

    public function createSavedFilter(mixed $name, mixed $tags): array
    {
        return $this->filters->createSavedInventoryFilter($name, $tags);
    }

    public function updateSavedFilter(int $id, mixed $name, mixed $tags): array|false
    {
        return $this->filters->updateSavedInventoryFilter($id, $name, $tags);
    }

    public function deleteSavedFilter(int $id): bool
    {
        return $this->filters->deleteSavedInventoryFilter($id);
    }
}
