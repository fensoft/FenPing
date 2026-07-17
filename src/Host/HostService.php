<?php

declare(strict_types=1);

namespace FenPing\Host;

use FenPing\Api\HttpException;
use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Dhcp\HostValidator;
use FenPing\Dhcp\MutationCoordinator;
use FenPing\Inventory\InventoryService;
use FenPing\Netboot\NetbootImageService;
use FenPing\Network\NetworkManager;
use FenPing\Network\NetworkPolicyException;
use FenPing\Scan\ProfileCatalog;
use FenPing\Scan\ScanJobRepository;
use FenPing\Status\StatusHistoryService;
use FenPing\Vendor\VendorLookup;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final readonly class HostService
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private NetworkManager $networks,
        private HostRepository $hosts,
        private HostMetadataRepository $metadata,
        private HostMetadataNormalizer $normalizer,
        private DiscoveredHostMetadataService $discoveredMetadata,
        private HostValidator $validator,
        private MutationCoordinator $mutations,
        private InventoryService $inventory,
        private StatusHistoryService $history,
        private ScanJobRepository $scans,
        private NetbootImageService $netboot,
        private VendorLookup $vendors,
    ) {
    }

    public function get(int $id): array
    {
        $host = $this->hosts->byId($id);
        if ($host === false) {
            throw new HttpException(404, 'host not found');
        }
        return $this->hosts->withDetectedMac($host);
    }

    public function detail(int $id): array
    {
        $host = $this->get($id);
        return $this->detailResponse($this->normalizeManaged($host));
    }

    public function detailByIp(string $ip, array $query = []): array
    {
        $identity = null;
        if (isset($query['network']) || isset($query['container'])) {
            try {
                $identity = [
                    'network' => $this->metadata->normalizeInventoryDeviceIdentityPart($query['network'] ?? null, 'network name'),
                    'container' => $this->metadata->normalizeInventoryDeviceIdentityPart($query['container'] ?? null, 'container name'),
                ];
            } catch (InvalidArgumentException $error) {
                throw new HttpException(400, $error->getMessage());
            }
        }
        try {
            $network = $this->networks->forIp($ip, false);
        } catch (NetworkPolicyException $error) {
            throw new HttpException($error->httpStatus, $error->getMessage());
        }
        foreach ($this->inventory->forNetwork($network->cidr) as $candidate) {
            if (($candidate['ip'] ?? '') !== $ip) {
                continue;
            }
            if ($identity !== null && (
                ($candidate['device_identity']['network'] ?? null) !== $identity['network']
                || ($candidate['device_identity']['container'] ?? null) !== $identity['container']
            )) {
                continue;
            }
            if (($candidate['id'] ?? null) !== null) {
                return $this->detail((int) $candidate['id']);
            }
            return $this->detailResponse($this->normalizeUnmanaged($candidate));
        }
        throw new HttpException(404, 'host not found');
    }

    public function create(array $body): array
    {
        try {
            $values = $this->validator->create($this->hostIp($body['ip'] ?? null), $body['mac'] ?? '');
            $source = $this->sourceDevice($body, (string) $values['ip']);
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        }
        if ($source !== null
            && $this->metadata->dockerContainerIdentity($source['network'], $source['container'], $values['ip']) === null) {
            throw new HttpException(409, 'source container identity is no longer available at this IP');
        }

        try {
            $change = $this->mutations->commit(function () use ($values, $source): int {
                $id = $this->hosts->create($values['ip'], $values['mac']);
                if ($source !== null) {
                    $this->metadata->transferInventoryDeviceMetadataToHost($source['network'], $source['container'], $id);
                }
                return $id;
            });
        } catch (PDOException $error) {
            $this->constraintError($error);
        }
        return ['id' => (int) $change['result'], 'log' => $change['log']];
    }

    public function update(int $id, array $body): array
    {
        $existing = $this->get($id);
        try {
            $this->networks->assertDhcpIp((string) ($existing['ip'] ?? ''));
            $netbootId = $this->netbootId($body['netboot_image_id'] ?? null);
            $values = $this->validator->edit(
                $this->hostIp($body['ip'] ?? null),
                $body['mac'] ?? '',
                $body['name'] ?? '',
                $body['router'] ?? null,
                $body['dns'] ?? null,
            );
            $profile = $this->normalizer->profile(
                $body['scan_profile'] ?? $existing['scan_profile'] ?? ProfileCatalog::MANAGED_DEFAULT,
            );
            $interval = $this->normalizer->intervalHours(
                $body['scan_interval_hours'] ?? $existing['scan_interval_hours'] ?? ProfileCatalog::MANAGED_INTERVAL_HOURS,
            );
            $displayName = $this->normalizer->text($body['display_name'] ?? $existing['display_name'] ?? '', 'display name');
            $notes = $this->normalizer->notes($body['notes'] ?? $existing['notes'] ?? '');
            $location = $this->normalizer->text($body['location'] ?? $existing['location'] ?? '', 'location');
            $owner = $this->normalizer->text($body['owner'] ?? $existing['owner'] ?? '', 'owner');
            $model = $this->normalizer->text($body['model'] ?? $existing['model'] ?? '', 'model');
            $icon = $this->normalizer->icon(array_key_exists('icon', $body) ? $body['icon'] : ($existing['icon'] ?? null));
            $tags = $this->normalizer->tags($body['tags'] ?? $existing['tags'] ?? []);
        } catch (NetworkPolicyException $error) {
            throw new HttpException($error->httpStatus, $error->getMessage());
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        }

        try {
            $change = $this->mutations->commit(function () use (
                $id, $values, $body, $netbootId, $profile, $interval,
                $displayName, $notes, $location, $owner, $model, $icon, $tags,
            ): bool {
                if ($this->hosts->byId($id) === false) {
                    throw new RuntimeException('host not found');
                }
                if ($netbootId !== null && !$this->netboot->exists($netbootId)) {
                    throw new InvalidArgumentException('invalid netboot image');
                }
                $this->hosts->update(
                    $id,
                    $values['ip'],
                    $values['mac'],
                    $values['name'],
                    $this->normalizer->databaseFlag($body['repeater'] ?? null),
                    $this->normalizer->databaseFlag($body['important'] ?? null),
                    $this->normalizer->databaseFlag($body['web'] ?? null),
                    $values['router'],
                    $values['dns'],
                    $netbootId,
                    $profile,
                    $interval,
                    $notes,
                    $location,
                    $owner,
                    $model,
                    $icon,
                    $tags,
                    $displayName,
                );
                return true;
            });
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'host not found') {
                throw new HttpException(404, $error->getMessage());
            }
            throw $error;
        } catch (PDOException $error) {
            $this->constraintError($error);
        }
        return ['saved' => true, 'log' => $change['log']];
    }

    public function updateDiscoveredMetadata(int $id, array $body): array
    {
        foreach (['ip', 'mac', 'name', 'router', 'dns', 'repeater', 'netboot_image_id'] as $field) {
            if (array_key_exists($field, $body)) {
                throw new HttpException(400, "$field is DHCP-only");
            }
        }
        $existing = $this->get($id);
        if ($this->discoveredMetadata->identity($existing) === null) {
            throw new HttpException(409, 'metadata editing requires a named non-DHCP host');
        }
        try {
            $host = $this->discoveredMetadata->save($id, $body);
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        }
        return ['saved' => true, 'host' => $this->normalizeManaged($host)];
    }

    public function delete(int $id): array
    {
        $existing = $this->get($id);
        try {
            $this->networks->assertDhcpIp((string) ($existing['ip'] ?? ''));
        } catch (NetworkPolicyException $error) {
            throw new HttpException($error->httpStatus, $error->getMessage());
        }
        $change = $this->mutations->commit(function () use ($id): bool {
            if ($this->hosts->byId($id) === false) {
                throw new RuntimeException('host not found');
            }
            $this->hosts->delete($id);
            return true;
        });
        return ['deleted' => true, 'log' => $change['log']];
    }

    private function detailResponse(array $host): array
    {
        $ip = (string) ($host['ip'] ?? '');
        $netbootImage = !empty($host['netboot_image_id'])
            ? $this->netboot->find((int) $host['netboot_image_id'])
            : null;
        return [
            'host' => $host,
            'history' => $ip !== '' ? $this->history->response($ip) : ['summary' => null, 'rows' => []],
            'scans' => $ip !== '' ? $this->scans->forIp($ip, 50) : [],
            'latest_scan' => $ip !== '' ? $this->scans->latest($ip) : null,
            'netboot_image' => $netbootImage === false ? null : $netbootImage,
        ];
    }

    private function normalizeManaged(array $host): array
    {
        $host['id'] = (int) $host['id'];
        foreach (['important', 'repeater', 'web'] as $field) {
            $host[$field] = (int) ($host[$field] ?? 0);
        }
        $host['netboot_image_id'] = $host['netboot_image_id'] === null ? null : (int) $host['netboot_image_id'];
        $host['scan_profile'] = $this->normalizer->profile($host['scan_profile'] ?? ProfileCatalog::MANAGED_DEFAULT);
        $host['scan_interval_hours'] = $this->normalizer->intervalHours($host['scan_interval_hours'] ?? ProfileCatalog::MANAGED_INTERVAL_HOURS);
        $host['mac'] = strtolower((string) ($host['mac'] ?? ''));
        $host['vendor'] = $this->vendors->forMac($host['mac']);
        $host['dhcp_managed'] = 1;
        $host['network_is_dhcp'] = $this->config->dhcpNetwork->contains((string) ($host['ip'] ?? '')) ? 1 : 0;
        $identity = $this->discoveredMetadata->identity($host);
        $host['device_identity'] = $identity;
        $host['metadata_editable'] = $identity === null ? 0 : 1;
        $ping = $this->pingState($host);
        $host['status'] = $ping['status'];
        $host['date'] = $ping['date'];
        return $host;
    }

    private function normalizeUnmanaged(array $host): array
    {
        $host['id'] = null;
        $host['mac'] = strtolower((string) ($host['mac'] ?? ''));
        $host['vendor'] = (string) ($host['vendor'] ?? $this->vendors->forMac($host['mac']));
        $host['router'] = '';
        $host['dns'] = '';
        $host['netboot_image_id'] = null;
        $host['dhcp_managed'] = 0;
        $host['network_is_dhcp'] = $this->config->dhcpNetwork->contains((string) ($host['ip'] ?? '')) ? 1 : 0;
        return $host;
    }

    private function pingState(array $host): array
    {
        $statement = $this->database->connection()->prepare("
            SELECT status, date FROM ping
            WHERE (:ip<>'' AND ip=:ip) OR (:mac<>'' AND LOWER(mac)=:mac)
            ORDER BY CASE WHEN ip=:ip THEN 0 ELSE 1 END, date DESC LIMIT 1
        ");
        $statement->execute([
            'ip' => (string) ($host['ip'] ?? ''),
            'mac' => strtolower((string) ($host['mac'] ?? '')),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? ['status' => '', 'date' => null]
            : ['status' => $row['status'] ?? '', 'date' => $row['date'] ?? null];
    }

    private function hostIp(mixed $value): ?string
    {
        $ip = trim((string) $value);
        if ($ip === '') {
            return null;
        }
        if (!str_contains($ip, '.')) {
            $ip = $this->config->network . '.' . $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('invalid ip');
        }
        return $ip;
    }

    private function netbootId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $text = trim((string) $value);
        if (!ctype_digit($text) || (int) $text <= 0 || !$this->netboot->exists((int) $text)) {
            throw new InvalidArgumentException('invalid netboot image');
        }
        return (int) $text;
    }

    private function sourceDevice(array $body, string $ip): ?array
    {
        if (!array_key_exists('source_device', $body)) {
            return null;
        }
        if (!is_array($body['source_device'])) {
            throw new InvalidArgumentException('source device must be an object');
        }
        return [
            'network' => $this->metadata->normalizeInventoryDeviceIdentityPart($body['source_device']['network'] ?? null, 'network name'),
            'container' => $this->metadata->normalizeInventoryDeviceIdentityPart($body['source_device']['container'] ?? null, 'container name'),
        ];
    }

    private function constraintError(PDOException $error): never
    {
        if ((string) $error->getCode() === '23000') {
            throw new HttpException(409, 'host name, MAC address, and IP address must be unique');
        }
        throw $error;
    }
}
