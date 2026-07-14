<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

trait InventoryRowBehavior
{
public function normalizeCategoryIp($ip) {
  $ip = trim((string)$ip);
  if ($ip != "" && strpos($ip, ".") === false)
    return $this->config->network . "." . $ip;
  return $ip;
}

public function normalizeInventoryRow($data, $ip = null) {
  $defaults = array(
    "id" => null,
    "name" => "",
    "ip" => $ip,
    "mac" => "",
    "status" => "",
    "date" => null,
    "important" => 0,
    "display_name" => "",
    "web" => null,
    "repeater" => null,
    "notes" => "",
    "location" => "",
    "owner" => "",
    "model" => "",
    "icon" => null,
    "tags" => array(),
    "stored_tags" => array(),
    "automatic_tags" => array(),
    "scan_profile" => null,
    "scan_interval_hours" => 0,
    "device_identity" => null,
    "metadata_editable" => 0,
    "network_is_dhcp" => 0
  );
  $row = array_merge($defaults, $data);
  $row["name"] = $row["name"] === null ? "" : $row["name"];
  $row["ip"] = $row["ip"] === null ? "" : $row["ip"];
  $row["mac"] = $row["mac"] === null ? "" : $row["mac"];
  $row["important"] = $row["important"] === null ? 0 : $row["important"];
  $row["display_name"] = $row["display_name"] === null ? "" : (string)$row["display_name"];
  $row["metadata_editable"] = (int)(bool)$row["metadata_editable"];
  $row["network_is_dhcp"] = (int)(bool)$row["network_is_dhcp"];
  return $row;
}

public function normalizeInventoryTagNames(array $tags): array {
  $normalized = array();
  foreach ($tags as $tag) {
    if (!is_string($tag))
      continue;
    $tag = trim($tag);
    if ($tag === '')
      continue;
    $key = strtolower($tag);
    if (!isset($normalized[$key]))
      $normalized[$key] = $tag;
  }
  $normalized = array_values($normalized);
  usort($normalized, 'strnatcasecmp');
  return $normalized;
}

public function dockerAutomaticInventoryTag(string $network, string $device): string {
  $network = trim($network);
  $device = trim($device);
  return $network === '' || $device === '' ? '' : $network . '-' . $device;
}

public function dockerAutomaticInventoryTags(): array {
  $tags = array();
  foreach ($this->dockerNetworks->gateways() as $gateway)
    $tags[] = 'gateway';
  foreach ($this->dockerNetworks->containers() as $container)
    $tags[] = $this->dockerAutomaticInventoryTag($container['network'], $container['container']);
  return $this->normalizeInventoryTagNames($tags);
}

public function dockerAutomaticInventoryTagsForIp(string $ip): array {
  $tags = array();
  foreach ($this->dockerNetworks->gateways() as $gateway)
    if ($gateway['ip'] === $ip)
      $tags[] = 'gateway';
  foreach ($this->dockerNetworks->containers() as $container)
    if ($container['ip'] === $ip)
      $tags[] = $this->dockerAutomaticInventoryTag($container['network'], $container['container']);
  return $this->normalizeInventoryTagNames($tags);
}

public function withAutomaticInventoryTags(array $data, array $automaticTags): array {
  $storedTags = $this->normalizeInventoryTagNames(
    is_array($data['stored_tags'] ?? null)
      ? $data['stored_tags']
      : (is_array($data['tags'] ?? null) ? $data['tags'] : array())
  );
  $automaticTags = $this->normalizeInventoryTagNames($automaticTags);
  $data['stored_tags'] = $storedTags;
  $data['automatic_tags'] = $automaticTags;
  $data['tags'] = $this->normalizeInventoryTagNames(array_merge($storedTags, $automaticTags));
  if (in_array('gateway', $automaticTags, true)
      && trim((string)($data['name'] ?? '')) === '' && trim((string)($data['display_name'] ?? '')) === '')
    $data['name'] = 'docker';
  return $data;
}

public function inventoryAvailableTags(): array {
  return $this->normalizeInventoryTagNames(array_merge(
    $this->availableHostTags(),
    $this->dockerAutomaticInventoryTags()
  ));
}

public function inventoryHostIsVisible(array $data, bool $reserved, ?DateTimeImmutable $now = null): bool {
  if ($reserved || strcasecmp(trim((string)($data['status'] ?? '')), 'Down') !== 0)
    return true;

  $date = trim((string)($data['date'] ?? ''));
  if ($date === '')
    return true;
  try {
    $downSince = new DateTimeImmutable($date, new DateTimeZone('UTC'));
  } catch (Throwable) {
    return true;
  }
  $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
  $cutoff = $now->modify('-' . $this->config->inventoryDownRetentionDays . ' days');
  return $downSince >= $cutoff;
}
}
