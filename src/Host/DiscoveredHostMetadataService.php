<?php

declare(strict_types=1);

namespace FenPing\Host;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Network\NetworkManager;
use FenPing\Network\NetworkPolicyException;
use FenPing\Scan\ProfileCatalog;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class DiscoveredHostMetadataService
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private NetworkManager $networks,
        private HostRepository $hosts,
        private HostMetadataRepository $metadata,
        private HostMetadataNormalizer $normalizer,
    ) {
    }

    public function identity(array $host): ?array
    {
        $ip = trim((string) ($host['ip'] ?? ''));
        $name = trim((string) ($host['name'] ?? ''));
        if ($ip === '' || $name === '' || $this->config->dhcpNetwork->contains($ip)) {
            return null;
        }
        try {
            $network = $this->networks->forIp($ip, false);
        } catch (NetworkPolicyException) {
            return null;
        }
        return ['network' => $network->cidr, 'container' => $name];
    }

    public function save(int $hostId, array $body): array
    {
        $existing = $this->hosts->byId($hostId);
        if ($existing === false) {
            throw new RuntimeException('host not found');
        }
        if ($this->identity($existing) === null) {
            throw new InvalidArgumentException('metadata editing requires a named non-DHCP host');
        }

        $values = [
            'id' => $hostId,
            'display_name' => array_key_exists('display_name', $body)
                ? $this->normalizer->text($body['display_name'], 'display name')
                : (string) ($existing['display_name'] ?? ''),
            'important' => array_key_exists('important', $body)
                ? $this->normalizer->databaseFlag($body['important'])
                : ((int) ($existing['important'] ?? 0) === 1 ? '1' : null),
            'web' => array_key_exists('web', $body)
                ? $this->normalizer->databaseFlag($body['web'])
                : ((int) ($existing['web'] ?? 0) === 1 ? '1' : null),
            'scan_profile' => array_key_exists('scan_profile', $body)
                ? $this->normalizer->profile($body['scan_profile'])
                : $this->normalizer->profile($existing['scan_profile'] ?? ProfileCatalog::MANAGED_DEFAULT),
            'scan_interval_hours' => array_key_exists('scan_interval_hours', $body)
                ? $this->normalizer->intervalHours($body['scan_interval_hours'])
                : $this->normalizer->intervalHours($existing['scan_interval_hours'] ?? ProfileCatalog::MANAGED_INTERVAL_HOURS),
            'notes' => array_key_exists('notes', $body)
                ? $this->normalizer->notes($body['notes']) : (string) ($existing['notes'] ?? ''),
            'location' => array_key_exists('location', $body)
                ? $this->normalizer->text($body['location'], 'location') : (string) ($existing['location'] ?? ''),
            'owner' => array_key_exists('owner', $body)
                ? $this->normalizer->text($body['owner'], 'owner') : (string) ($existing['owner'] ?? ''),
            'model' => array_key_exists('model', $body)
                ? $this->normalizer->text($body['model'], 'model') : (string) ($existing['model'] ?? ''),
            'icon' => array_key_exists('icon', $body)
                ? $this->normalizer->icon($body['icon']) : $this->normalizer->icon($existing['icon'] ?? null),
        ];
        $tags = array_key_exists('tags', $body)
            ? $this->normalizer->tags($body['tags'])
            : $this->normalizer->tags($existing['tags'] ?? []);

        $this->database->immediate(function (PDO $database) use ($values, $tags): void {
            $stored = $values;
            foreach (['display_name', 'notes', 'location', 'owner', 'model'] as $field) {
                $stored[$field] = $stored[$field] === '' ? null : $stored[$field];
            }
            $statement = $database->prepare("
                UPDATE ips SET display_name=:display_name, important=:important, web=:web,
                  scan_profile=:scan_profile, scan_interval_hours=:scan_interval_hours,
                  notes=:notes, location=:location, owner=:owner, model=:model, icon=:icon
                WHERE id=:id
            ");
            $statement->execute($stored);
            if ($statement->rowCount() < 1 && $this->hosts->byId((int) $values['id']) === false) {
                throw new RuntimeException('host not found');
            }
            $this->metadata->replaceHostTags((int) $values['id'], $tags);
        });

        $saved = $this->hosts->byId($hostId);
        if ($saved === false) {
            throw new RuntimeException('host not found');
        }
        return $saved;
    }
}
