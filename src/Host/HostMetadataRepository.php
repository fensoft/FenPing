<?php

declare(strict_types=1);

namespace FenPing\Host;

use FenPing\Database\DatabaseManager;
use FenPing\Docker\DockerNetworkCache;
use FenPing\Scan\ProfileCatalog;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class HostMetadataRepository
{
    public function __construct(
        private DatabaseManager $database,
        private DockerNetworkCache $dockerNetworks,
        private HostMetadataNormalizer $normalizer,
    ) {
    }

public function hostTags(int $hostId): array {
  $stmt = $this->database->connection()->prepare("
    SELECT tags.name
    FROM host_tags
    INNER JOIN tags ON tags.id=host_tags.tag_id
    WHERE host_tags.host_id=:host_id
    ORDER BY tags.name COLLATE NOCASE, tags.id
  ");
  $stmt->execute(array('host_id' => $hostId));
  return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

public function normalizeManagedHostMetadata(array $host): array {
  $host['display_name'] = (string)($host['display_name'] ?? '');
  $host['notes'] = (string)($host['notes'] ?? '');
  $host['location'] = (string)($host['location'] ?? '');
  $host['owner'] = (string)($host['owner'] ?? '');
  $host['model'] = (string)($host['model'] ?? '');
  $icon = trim((string)($host['icon'] ?? ''));
  $host['icon'] = $icon === '' ? null : $icon;
  $host['tags'] = isset($host['id']) ? $this->hostTags((int)$host['id']) : array();
  return $host;
}

public function managedHostMetadataMap(): array {
  $rows = $this->database->connection()->query('SELECT id, display_name, notes, location, owner, model, icon FROM ips')->fetchAll(PDO::FETCH_ASSOC);
  $map = array();
  foreach ($rows as $row) {
    $id = (int)$row['id'];
    $map[$id] = array(
      'display_name' => (string)($row['display_name'] ?? ''),
      'notes' => (string)($row['notes'] ?? ''),
      'location' => (string)($row['location'] ?? ''),
      'owner' => (string)($row['owner'] ?? ''),
      'model' => (string)($row['model'] ?? ''),
      'icon' => trim((string)($row['icon'] ?? '')) === '' ? null : (string)$row['icon'],
      'tags' => array()
    );
  }
  $stmt = $this->database->connection()->query("
    SELECT host_tags.host_id, tags.name
    FROM host_tags
    INNER JOIN tags ON tags.id=host_tags.tag_id
    ORDER BY tags.name COLLATE NOCASE, tags.id
  ");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hostId = (int)$row['host_id'];
    if (isset($map[$hostId]))
      $map[$hostId]['tags'][] = (string)$row['name'];
  }
  return $map;
}

public function tagId(string $name): int {
  $insert = $this->database->connection()->prepare('INSERT OR IGNORE INTO tags (name) VALUES (:name)');
  $insert->execute(array('name' => $name));
  $select = $this->database->connection()->prepare('SELECT id FROM tags WHERE name=:name COLLATE NOCASE');
  $select->execute(array('name' => $name));
  $id = $select->fetchColumn();
  if ($id === false)
    throw new RuntimeException('failed to save tag');
  return (int)$id;
}

public function replaceHostTags(int $hostId, array $tags): void {
  $delete = $this->database->connection()->prepare('DELETE FROM host_tags WHERE host_id=:host_id');
  $delete->execute(array('host_id' => $hostId));
  $insert = $this->database->connection()->prepare('INSERT INTO host_tags (host_id, tag_id) VALUES (:host_id, :tag_id)');
  foreach ($tags as $tag)
    $insert->execute(array('host_id' => $hostId, 'tag_id' => $this->tagId($tag)));
}

public function inventoryDeviceMetadataKey(string $network, string $container): string {
  return $network . "\0" . $container;
}

public function normalizeInventoryDeviceIdentityPart(mixed $value, string $label): string {
  if (!is_string($value))
    throw new InvalidArgumentException("$label must be a string");
  $value = trim($value);
  if ($value === '')
    throw new InvalidArgumentException("$label is required");
  return $value;
}

public function dockerContainerIdentity(string $network, string $container, ?string $ip = null): ?array {
  $identity = $this->dockerNetworks->container($network, $container);
  if ($identity === null || ($ip !== null && $identity['ip'] !== $ip))
    return null;
  return $identity;
}

public function inventoryDeviceTags(int $deviceId): array {
  $stmt = $this->database->connection()->prepare("
    SELECT tags.name
    FROM inventory_device_tags
    INNER JOIN tags ON tags.id=inventory_device_tags.tag_id
    WHERE inventory_device_tags.device_id=:device_id
    ORDER BY tags.name COLLATE NOCASE, tags.id
  ");
  $stmt->execute(array('device_id' => $deviceId));
  return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

public function normalizeInventoryDeviceMetadata(array $row): array {
  $row['id'] = (int)$row['id'];
  $row['network_name'] = (string)$row['network_name'];
  $row['container_name'] = (string)$row['container_name'];
  $row['display_name'] = (string)($row['display_name'] ?? '');
  $row['important'] = (int)($row['important'] ?? 0);
  $row['web'] = (int)($row['web'] ?? 0);
  $row['scan_profile'] = (string)($row['scan_profile'] ?? ProfileCatalog::UNMANAGED_DEFAULT);
  $row['scan_interval_hours'] = (int)($row['scan_interval_hours'] ?? ProfileCatalog::UNMANAGED_INTERVAL_HOURS);
  $row['notes'] = (string)($row['notes'] ?? '');
  $row['location'] = (string)($row['location'] ?? '');
  $row['owner'] = (string)($row['owner'] ?? '');
  $row['model'] = (string)($row['model'] ?? '');
  $icon = trim((string)($row['icon'] ?? ''));
  $row['icon'] = $icon === '' ? null : $icon;
  $row['tags'] = $this->inventoryDeviceTags($row['id']);
  return $row;
}

public function inventoryDeviceMetadata(string $network, string $container): array|false {
  $stmt = $this->database->connection()->prepare("
    SELECT * FROM inventory_device_metadata
    WHERE network_name=:network_name AND container_name=:container_name
  ");
  $stmt->execute(array('network_name' => $network, 'container_name' => $container));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row === false ? false : $this->normalizeInventoryDeviceMetadata($row);
}

public function inventoryDeviceMetadataMap(): array {
  $rows = $this->database->connection()->query('SELECT * FROM inventory_device_metadata')->fetchAll(PDO::FETCH_ASSOC);
  $map = array();
  foreach ($rows as $row) {
    $normalized = $this->normalizeInventoryDeviceMetadata($row);
    $map[$this->inventoryDeviceMetadataKey($normalized['network_name'], $normalized['container_name'])] = $normalized;
  }
  return $map;
}

public function replaceInventoryDeviceTags(int $deviceId, array $tags): void {
  $delete = $this->database->connection()->prepare('DELETE FROM inventory_device_tags WHERE device_id=:device_id');
  $delete->execute(array('device_id' => $deviceId));
  $insert = $this->database->connection()->prepare('INSERT INTO inventory_device_tags (device_id, tag_id) VALUES (:device_id, :tag_id)');
  foreach ($tags as $tag)
    $insert->execute(array('device_id' => $deviceId, 'tag_id' => $this->tagId($tag)));
}

public function saveInventoryDeviceMetadata(string $network, string $container, array $body): array {
  $existing = $this->inventoryDeviceMetadata($network, $container);
  $current = $existing === false ? array(
    'display_name' => '',
    'important' => 0,
    'web' => 0,
    'scan_profile' => ProfileCatalog::UNMANAGED_DEFAULT,
    'scan_interval_hours' => ProfileCatalog::UNMANAGED_INTERVAL_HOURS,
    'notes' => '',
    'location' => '',
    'owner' => '',
    'model' => '',
    'icon' => null,
    'tags' => array()
  ) : $existing;

  $values = array(
    'network_name' => $network,
    'container_name' => $container,
    'display_name' => array_key_exists('display_name', $body)
      ? $this->normalizer->text($body['display_name'], 'display name')
      : (string)$current['display_name'],
    'important' => array_key_exists('important', $body)
      ? $this->normalizer->databaseFlag($body['important'])
      : ((int)$current['important'] === 1 ? '1' : null),
    'web' => array_key_exists('web', $body)
      ? $this->normalizer->databaseFlag($body['web'])
      : ((int)$current['web'] === 1 ? '1' : null),
    'scan_profile' => array_key_exists('scan_profile', $body)
      ? $this->normalizer->profile($body['scan_profile'])
      : (string)$current['scan_profile'],
    'scan_interval_hours' => array_key_exists('scan_interval_hours', $body)
      ? $this->normalizer->intervalHours($body['scan_interval_hours'])
      : (int)$current['scan_interval_hours'],
    'notes' => array_key_exists('notes', $body)
      ? $this->normalizer->notes($body['notes'])
      : (string)$current['notes'],
    'location' => array_key_exists('location', $body)
      ? $this->normalizer->text($body['location'], 'location')
      : (string)$current['location'],
    'owner' => array_key_exists('owner', $body)
      ? $this->normalizer->text($body['owner'], 'owner')
      : (string)$current['owner'],
    'model' => array_key_exists('model', $body)
      ? $this->normalizer->text($body['model'], 'model')
      : (string)$current['model'],
    'icon' => array_key_exists('icon', $body)
      ? $this->normalizer->icon($body['icon'])
      : $current['icon']
  );
  $tags = array_key_exists('tags', $body)
    ? $this->normalizer->tags($body['tags'])
    : $this->normalizer->tags($current['tags']);

  $this->database->immediate(function(PDO $database) use ($values, $tags): void {
    $stmt = $database->prepare("
      INSERT INTO inventory_device_metadata (
        network_name, container_name, display_name, important, web,
        scan_profile, scan_interval_hours, notes, location, owner, model, icon
      ) VALUES (
        :network_name, :container_name, :display_name, :important, :web,
        :scan_profile, :scan_interval_hours, :notes, :location, :owner, :model, :icon
      )
      ON CONFLICT(network_name, container_name) DO UPDATE SET
        display_name=excluded.display_name,
        important=excluded.important,
        web=excluded.web,
        scan_profile=excluded.scan_profile,
        scan_interval_hours=excluded.scan_interval_hours,
        notes=excluded.notes,
        location=excluded.location,
        owner=excluded.owner,
        model=excluded.model,
        icon=excluded.icon
    ");
    $stored = $values;
    foreach (array('display_name', 'notes', 'location', 'owner', 'model') as $field)
      if ($stored[$field] === '')
        $stored[$field] = null;
    $stmt->execute($stored);
    $id = $database->prepare("
      SELECT id FROM inventory_device_metadata
      WHERE network_name=:network_name AND container_name=:container_name
    ");
    $id->execute(array('network_name' => $values['network_name'], 'container_name' => $values['container_name']));
    $deviceId = $id->fetchColumn();
    if ($deviceId === false)
      throw new RuntimeException('failed to save inventory device metadata');
    $this->replaceInventoryDeviceTags((int)$deviceId, $tags);
  });

  $saved = $this->inventoryDeviceMetadata($network, $container);
  if ($saved === false)
    throw new RuntimeException('failed to load inventory device metadata');
  return $saved;
}

public function transferInventoryDeviceMetadataToHost(string $network, string $container, int $hostId): bool {
  $metadata = $this->inventoryDeviceMetadata($network, $container);
  if ($metadata === false)
    return false;
  $stmt = $this->database->connection()->prepare("
    UPDATE ips SET
      display_name=:display_name,
      important=:important,
      web=:web,
      scan_profile=:scan_profile,
      scan_interval_hours=:scan_interval_hours,
      notes=:notes,
      location=:location,
      owner=:owner,
      model=:model,
      icon=:icon
    WHERE id=:id
  ");
  $stmt->execute(array(
    'display_name' => $metadata['display_name'] === '' ? null : $metadata['display_name'],
    'important' => $metadata['important'] === 1 ? 1 : null,
    'web' => $metadata['web'] === 1 ? 1 : null,
    'scan_profile' => $metadata['scan_profile'],
    'scan_interval_hours' => $metadata['scan_interval_hours'],
    'notes' => $metadata['notes'] === '' ? null : $metadata['notes'],
    'location' => $metadata['location'] === '' ? null : $metadata['location'],
    'owner' => $metadata['owner'] === '' ? null : $metadata['owner'],
    'model' => $metadata['model'] === '' ? null : $metadata['model'],
    'icon' => $metadata['icon'],
    'id' => $hostId
  ));
  $this->replaceHostTags($hostId, $metadata['tags']);
  $delete = $this->database->connection()->prepare('DELETE FROM inventory_device_metadata WHERE id=:id');
  $delete->execute(array('id' => $metadata['id']));
  return true;
}

public function availableHostTags(): array {
  $stmt = $this->database->connection()->query("
    SELECT name FROM tags
    WHERE EXISTS (SELECT 1 FROM host_tags WHERE host_tags.tag_id=tags.id)
       OR EXISTS (SELECT 1 FROM inventory_saved_filter_tags WHERE inventory_saved_filter_tags.tag_id=tags.id)
       OR EXISTS (SELECT 1 FROM inventory_device_tags WHERE inventory_device_tags.tag_id=tags.id)
    ORDER BY name COLLATE NOCASE, id
  ");
  return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

public function savedInventoryFilters(): array {
  $filters = array();
  $stmt = $this->database->connection()->query('SELECT id, name FROM inventory_saved_filters ORDER BY name COLLATE NOCASE, id');
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
    $filters[(int)$row['id']] = array('id' => (int)$row['id'], 'name' => (string)$row['name'], 'tags' => array());
  $tags = $this->database->connection()->query("
    SELECT inventory_saved_filter_tags.filter_id, tags.name
    FROM inventory_saved_filter_tags
    INNER JOIN tags ON tags.id=inventory_saved_filter_tags.tag_id
    ORDER BY tags.name COLLATE NOCASE, tags.id
  ");
  while ($row = $tags->fetch(PDO::FETCH_ASSOC)) {
    $filterId = (int)$row['filter_id'];
    if (isset($filters[$filterId]))
      $filters[$filterId]['tags'][] = (string)$row['name'];
  }
  return array_values($filters);
}

public function savedInventoryFilter(int $id): array|false {
  foreach ($this->savedInventoryFilters() as $filter) {
    if ($filter['id'] === $id)
      return $filter;
  }
  return false;
}
}
