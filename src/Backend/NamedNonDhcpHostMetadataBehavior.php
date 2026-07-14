<?php

declare(strict_types=1);

namespace FenPing\Backend;

use FenPing\Network\NetworkPolicyException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

trait NamedNonDhcpHostMetadataBehavior
{
public function namedNonDhcpHostIdentity(array $host): ?array {
  $ip = trim((string)($host['ip'] ?? ''));
  $name = trim((string)($host['name'] ?? ''));
  if ($ip === '' || $name === '' || $this->config->dhcpNetwork->contains($ip))
    return null;
  try {
    $network = $this->networks->forIp($ip, false);
  } catch (NetworkPolicyException) {
    return null;
  }
  return array('network' => $network->cidr, 'container' => $name);
}

public function saveNamedNonDhcpHostMetadata(int $hostId, array $body): array {
  $existing = $this->getId($hostId);
  if ($existing === false)
    throw new RuntimeException('host not found');
  if ($this->namedNonDhcpHostIdentity($existing) === null)
    throw new InvalidArgumentException('metadata editing requires a named non-DHCP host');

  $values = array(
    'id' => $hostId,
    'display_name' => array_key_exists('display_name', $body)
      ? $this->normalizeHostMetadataText($body['display_name'], 'display name')
      : (string)($existing['display_name'] ?? ''),
    'important' => array_key_exists('important', $body)
      ? $this->toDbFlag($body['important'])
      : ((int)($existing['important'] ?? 0) === 1 ? '1' : null),
    'web' => array_key_exists('web', $body)
      ? $this->toDbFlag($body['web'])
      : ((int)($existing['web'] ?? 0) === 1 ? '1' : null),
    'scan_profile' => array_key_exists('scan_profile', $body)
      ? $this->normalizeScheduledScanProfile($body['scan_profile'])
      : $this->normalizeScheduledScanProfile($existing['scan_profile'] ?? self::SCAN_MANAGED_DEFAULT_PROFILE),
    'scan_interval_hours' => array_key_exists('scan_interval_hours', $body)
      ? $this->normalizeScanIntervalHours($body['scan_interval_hours'])
      : $this->normalizeScanIntervalHours($existing['scan_interval_hours'] ?? self::SCAN_MANAGED_DEFAULT_INTERVAL_HOURS),
    'notes' => array_key_exists('notes', $body)
      ? $this->normalizeHostNotes($body['notes'])
      : (string)($existing['notes'] ?? ''),
    'location' => array_key_exists('location', $body)
      ? $this->normalizeHostMetadataText($body['location'], 'location')
      : (string)($existing['location'] ?? ''),
    'owner' => array_key_exists('owner', $body)
      ? $this->normalizeHostMetadataText($body['owner'], 'owner')
      : (string)($existing['owner'] ?? ''),
    'model' => array_key_exists('model', $body)
      ? $this->normalizeHostMetadataText($body['model'], 'model')
      : (string)($existing['model'] ?? ''),
    'icon' => array_key_exists('icon', $body)
      ? $this->normalizeHostIcon($body['icon'])
      : $this->normalizeHostIcon($existing['icon'] ?? null)
  );
  $tags = array_key_exists('tags', $body)
    ? $this->normalizeHostTags($body['tags'])
    : $this->normalizeHostTags($existing['tags'] ?? array());

  $this->database->immediate(function(PDO $database) use ($values, $tags): void {
    $stored = $values;
    foreach (array('display_name', 'notes', 'location', 'owner', 'model') as $field)
      if ($stored[$field] === '')
        $stored[$field] = null;
    $stmt = $database->prepare("
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
    $stmt->execute($stored);
    if ($stmt->rowCount() < 1 && $this->getId((int)$values['id']) === false)
      throw new RuntimeException('host not found');
    $this->replaceHostTags((int)$values['id'], $tags);
  });

  $saved = $this->getId($hostId);
  if ($saved === false)
    throw new RuntimeException('host not found');
  return $saved;
}

public function handleNamedNonDhcpHostMetadataEdit(array $params): array {
  $body = $this->requestBody();
  foreach (array('ip', 'mac', 'name', 'router', 'dns', 'repeater', 'netboot_image_id') as $field)
    if (array_key_exists($field, $body))
      $this->jsonError(400, "$field is DHCP-only");

  $existing = $this->getId($params['id']);
  if ($existing === false)
    $this->jsonError(404, 'host not found');
  if ($this->namedNonDhcpHostIdentity($existing) === null)
    $this->jsonError(409, 'metadata editing requires a named non-DHCP host');

  try {
    $host = $this->saveNamedNonDhcpHostMetadata((int)$params['id'], $body);
  } catch (InvalidArgumentException $error) {
    $this->jsonError(400, $error->getMessage());
  } catch (RuntimeException $error) {
    if ($error->getMessage() === 'host not found')
      $this->jsonError(404, $error->getMessage());
    throw $error;
  }
  return array('saved' => true, 'host' => $this->normalizeHostDetail($host));
}
}
