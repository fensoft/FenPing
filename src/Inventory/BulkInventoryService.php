<?php

declare(strict_types=1);

namespace FenPing\Inventory;

use FenPing\Audit\AuditLogService;
use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Dhcp\MutationCoordinator;
use FenPing\Host\DiscoveredHostMetadataService;
use FenPing\Host\HostMetadataNormalizer;
use FenPing\Host\HostMetadataRepository;
use FenPing\Host\HostRepository;
use FenPing\Scan\ProfileCatalog;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class BulkInventoryService
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private HostRepository $hosts,
        private HostMetadataRepository $metadata,
        private HostMetadataNormalizer $normalizer,
        private DiscoveredHostMetadataService $discoveredMetadata,
        private BulkInventoryTargetNormalizer $targetNormalizer,
        private MutationCoordinator $mutations,
        private AuditLogService $audit,
    ) {
    }

    public function execute(array $body): array
    {
        $action = $this->action($body['action'] ?? null);
        $targets = $this->targetNormalizer->normalize($body['targets'] ?? null, $action);

        $result = match ($action) {
            'tags' => $this->updateTags($targets, $body),
            'scan_profile' => $this->updateScanProfile($targets, $body),
            'approve' => $this->approve($targets),
            'delete' => $this->delete($targets),
        };
        $result = ['action' => $action, ...$result];
        $this->recordAudit($action, $result, $body);
        unset($result['_changed_targets']);
        return $result;
    }

    private function action(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['tags', 'scan_profile', 'approve', 'delete'], true)) {
            throw new InvalidArgumentException('invalid bulk inventory action');
        }
        return $value;
    }

    private function updateTags(array $targets, array $body): array
    {
        $add = $this->normalizer->tags($body['add_tags'] ?? []);
        $remove = $this->normalizer->tags($body['remove_tags'] ?? []);
        if ($add === [] && $remove === []) {
            throw new InvalidArgumentException('at least one tag must be added or removed');
        }
        $removeKeys = array_fill_keys(array_map('strtolower', $remove), true);
        foreach ($add as $tag) {
            if (isset($removeKeys[strtolower($tag)])) {
                throw new InvalidArgumentException('the same tag cannot be added and removed');
            }
        }

        $state = $this->state($targets);
        $this->database->immediate(function () use ($targets, $add, $removeKeys, &$state): void {
            foreach ($targets as $target) {
                if ($target['kind'] === 'host') {
                    $host = $this->hosts->byId($target['id']);
                    if ($host === false) {
                        $this->skip($state, $target, 'not_found');
                        continue;
                    }
                    if (!$this->hostMetadataEditable($host)) {
                        $this->skip($state, $target, 'not_metadata_editable');
                        continue;
                    }
                    $updated = $this->changedTags($host['tags'] ?? [], $add, $removeKeys);
                    if ($updated === $host['tags']) {
                        $state['unchanged_count']++;
                        continue;
                    }
                    $this->metadata->replaceHostTags($target['id'], $updated);
                    $this->changed($state, $target);
                    continue;
                }
                if ($target['kind'] === 'device') {
                    if ($this->metadata->dockerContainerIdentity($target['network'], $target['container']) === null) {
                        $this->skip($state, $target, 'not_found');
                        continue;
                    }
                    $current = $this->metadata->inventoryDeviceMetadata($target['network'], $target['container']);
                    $currentTags = $current === false ? [] : $current['tags'];
                    $updated = $this->changedTags($currentTags, $add, $removeKeys);
                    if ($updated === $currentTags) {
                        $state['unchanged_count']++;
                        continue;
                    }
                    $deviceId = $current === false
                        ? $this->createDeviceMetadata($target['network'], $target['container'])
                        : (int) $current['id'];
                    $this->metadata->replaceInventoryDeviceTags($deviceId, $updated);
                    $this->changed($state, $target);
                    continue;
                }
                $this->skip($state, $target, 'not_metadata_editable');
            }
        });
        return $this->finish($state);
    }

    private function changedTags(array $current, array $add, array $removeKeys): array
    {
        $kept = array_values(array_filter(
            $this->normalizer->tags($current),
            static fn(string $tag): bool => !isset($removeKeys[strtolower($tag)]),
        ));
        return $this->normalizer->tags([...$kept, ...$add]);
    }

    private function updateScanProfile(array $targets, array $body): array
    {
        $profile = $this->normalizer->profile($body['scan_profile'] ?? null);
        $state = $this->state($targets);
        $this->database->immediate(function (PDO $database) use ($targets, $profile, &$state): void {
            foreach ($targets as $target) {
                if ($target['kind'] === 'host') {
                    $host = $this->hosts->byId($target['id']);
                    if ($host === false) {
                        $this->skip($state, $target, 'not_found');
                        continue;
                    }
                    if (!$this->hostMetadataEditable($host)) {
                        $this->skip($state, $target, 'not_metadata_editable');
                        continue;
                    }
                    if ((string) $host['scan_profile'] === $profile) {
                        $state['unchanged_count']++;
                        continue;
                    }
                    $statement = $database->prepare('UPDATE ips SET scan_profile=:profile WHERE id=:id');
                    $statement->execute(['profile' => $profile, 'id' => $target['id']]);
                    $this->changed($state, $target);
                    continue;
                }
                if ($target['kind'] === 'device') {
                    if ($this->metadata->dockerContainerIdentity($target['network'], $target['container']) === null) {
                        $this->skip($state, $target, 'not_found');
                        continue;
                    }
                    $current = $this->metadata->inventoryDeviceMetadata($target['network'], $target['container']);
                    $currentProfile = $current === false ? ProfileCatalog::UNMANAGED_DEFAULT : (string) $current['scan_profile'];
                    if ($currentProfile === $profile) {
                        $state['unchanged_count']++;
                        continue;
                    }
                    $statement = $database->prepare("
                        INSERT INTO inventory_device_metadata (network_name, container_name, scan_profile)
                        VALUES (:network_name, :container_name, :profile)
                        ON CONFLICT(network_name, container_name) DO UPDATE SET scan_profile=excluded.scan_profile
                    ");
                    $statement->execute([
                        'network_name' => $target['network'],
                        'container_name' => $target['container'],
                        'profile' => $profile,
                    ]);
                    $this->changed($state, $target);
                    continue;
                }
                $this->skip($state, $target, 'not_metadata_editable');
            }
        });
        return $this->finish($state);
    }

    private function approve(array $targets): array
    {
        $state = $this->state($targets);
        $this->database->immediate(function (PDO $database) use ($targets, &$state): void {
            $managed = $database->prepare("SELECT 1 FROM ips WHERE LOWER(mac)=:mac LIMIT 1");
            $approved = $database->prepare("SELECT 1 FROM device_approvals WHERE LOWER(mac)=:mac LIMIT 1");
            $insert = $database->prepare('INSERT INTO device_approvals (mac) VALUES (:mac)');
            foreach ($targets as $target) {
                if ($target['kind'] === 'host') {
                    $reason = $this->hosts->byId($target['id']) === false ? 'not_found' : 'not_dynamic';
                    $this->skip($state, $target, $reason);
                    continue;
                }
                $mac = (string) ($target['mac'] ?? '');
                if ($mac === '') {
                    $this->skip($state, $target, 'missing_mac');
                    continue;
                }
                $managed->execute(['mac' => $mac]);
                if ($managed->fetchColumn() !== false) {
                    $this->skip($state, $target, 'not_dynamic');
                    continue;
                }
                $approved->execute(['mac' => $mac]);
                if ($approved->fetchColumn() !== false) {
                    $state['unchanged_count']++;
                    continue;
                }
                $insert->execute(['mac' => $mac]);
                $this->changed($state, $target);
            }
        });
        return $this->finish($state);
    }

    private function delete(array $targets): array
    {
        if (!$this->hasEligibleDeletion($targets)) {
            return $this->finish($this->applyDelete($targets, false));
        }
        $change = $this->mutations->commit(fn(): array => $this->applyDelete($targets, true));
        $result = $this->finish($change['result']);
        $result['log'] = $change['log'];
        return $result;
    }

    private function hasEligibleDeletion(array $targets): bool
    {
        foreach ($targets as $target) {
            if ($target['kind'] !== 'host') {
                continue;
            }
            $host = $this->hosts->byId($target['id']);
            if ($host !== false && $this->config->dhcpNetwork->contains((string) ($host['ip'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    private function applyDelete(array $targets, bool $mutate): array
    {
        $state = $this->state($targets);
        foreach ($targets as $target) {
            if ($target['kind'] !== 'host') {
                $this->skip($state, $target, 'not_dhcp_reservation');
                continue;
            }
            $host = $this->hosts->byId($target['id']);
            if ($host === false) {
                $this->skip($state, $target, 'not_found');
                continue;
            }
            if (!$this->config->dhcpNetwork->contains((string) ($host['ip'] ?? ''))) {
                $this->skip($state, $target, 'not_dhcp_reservation');
                continue;
            }
            if (!$mutate) {
                throw new RuntimeException('eligible deletion was not coordinated');
            }
            $this->hosts->delete($target['id']);
            $this->changed($state, $target);
        }
        return $state;
    }

    private function hostMetadataEditable(array $host): bool
    {
        $ip = (string) ($host['ip'] ?? '');
        return $this->config->dhcpNetwork->contains($ip) || $this->discoveredMetadata->identity($host) !== null;
    }

    private function createDeviceMetadata(string $network, string $container): int
    {
        $database = $this->database->connection();
        $insert = $database->prepare(
            'INSERT OR IGNORE INTO inventory_device_metadata (network_name, container_name) VALUES (:network, :container)',
        );
        $insert->execute(['network' => $network, 'container' => $container]);
        $select = $database->prepare(
            'SELECT id FROM inventory_device_metadata WHERE network_name=:network AND container_name=:container',
        );
        $select->execute(['network' => $network, 'container' => $container]);
        $id = $select->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('failed to save inventory device metadata');
        }
        return (int) $id;
    }

    private function state(array $targets): array
    {
        return [
            'requested_count' => count($targets),
            'changed_count' => 0,
            'unchanged_count' => 0,
            'skipped' => [],
            '_changed_targets' => [],
        ];
    }

    private function changed(array &$state, array $target): void
    {
        $state['changed_count']++;
        $state['_changed_targets'][] = $target;
    }

    private function skip(array &$state, array $target, string $reason): void
    {
        $state['skipped'][] = ['target' => $target, 'reason' => $reason];
    }

    private function finish(array $state): array
    {
        $state['skipped_count'] = count($state['skipped']);
        return $state;
    }

    private function recordAudit(string $action, array $result, array $body): void
    {
        if ($result['changed_count'] < 1) {
            return;
        }
        $details = [
            'action' => $action,
            'requested_count' => $result['requested_count'],
            'changed_count' => $result['changed_count'],
            'unchanged_count' => $result['unchanged_count'],
            'skipped_count' => $result['skipped_count'],
            'targets' => $result['_changed_targets'],
        ];
        if ($action === 'tags') {
            $details['add_tags'] = $this->normalizer->tags($body['add_tags'] ?? []);
            $details['remove_tags'] = $this->normalizer->tags($body['remove_tags'] ?? []);
        } elseif ($action === 'scan_profile') {
            $details['scan_profile'] = $this->normalizer->profile($body['scan_profile']);
        }
        $count = $result['changed_count'];
        $summary = match ($action) {
            'tags' => "Updated tags on $count inventory devices",
            'scan_profile' => "Updated scheduled scan profile on $count inventory devices",
            'approve' => "Approved $count inventory devices",
            'delete' => "Deleted $count DHCP reservations",
        };
        $this->audit->record(
            'inventory.bulk_' . $action,
            'inventory',
            null,
            $summary,
            $details,
        );
    }
}
