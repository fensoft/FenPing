<?php

declare(strict_types=1);

namespace FenPing\Discord;

use FenPing\Config\AppConfig;

final readonly class DiscordPayloadBuilder
{
    public function __construct(private AppConfig $config)
    {
    }

public function discordPortChangeEmbed(array $change): array {
  $name = trim((string)($change['name'] ?? ''));
  $ip = (string)($change['ip'] ?? '');
  $protocol = strtolower((string)($change['protocol'] ?? ''));
  $port = (int)($change['port'] ?? 0);
  $type = (string)($change['change_type'] ?? 'changed');
  $label = $name !== '' ? $name : ($ip !== '' ? $ip : 'Unknown host');
  $action = array('appeared' => 'appeared', 'disappeared' => 'disappeared', 'changed' => 'changed version')[$type] ?? 'changed';
  $timestamp = strtotime((string)($change['created_at'] ?? ''));

  $embed = array(
    'title' => $label . ': ' . $port . '/' . $protocol . ' ' . $action,
    'description' => 'A network service changed.',
    'color' => $this->discordPortChangeColor($type),
    'fields' => array(
      $this->discordEmbedField('Host', $label, true),
      $this->discordEmbedField('IP', $ip, true),
      $this->discordEmbedField('Port', $port . '/' . $protocol, true),
      $this->discordEmbedField('Previous', $this->discordPortServiceLabel($change, 'previous'), false),
      $this->discordEmbedField('Current', $this->discordPortServiceLabel($change, 'current'), false),
      $this->discordEmbedField('Scan', (string)($change['mode'] ?? '-'), true)
    )
  );
  if ($timestamp !== false)
    $embed['timestamp'] = date(DATE_ATOM, $timestamp);
  return $embed;
}

public function discordPortServiceLabel(array $change, string $prefix): string {
  $service = trim((string)($change[$prefix . '_service'] ?? ''));
  $version = trim((string)($change[$prefix . '_version'] ?? ''));
  $value = trim($service . ' ' . $version);
  return $value === '' ? '-' : $value;
}

public function discordPortChangeColor(string $type): int {
  if ($type === 'appeared')
    return 0x16a34a;
  if ($type === 'disappeared')
    return 0xdc2626;
  return 0xf59e0b;
}

public function discordChangeEmbed(array $change): array {
  $name = trim((string)($change['name'] ?? ''));
  $ip = (string)($change['ip'] ?? '');
  $mac = strtolower((string)($change['mac'] ?? ''));
  $previous = (string)($change['previous_status'] ?? 'Unknown');
  $status = (string)($change['status'] ?? 'Unknown');
  $date = (string)($change['date_begin'] ?? '');
  $important = (int)($change['important'] ?? 0) === 1;
  $label = $name !== '' ? $name : ($ip !== '' ? $ip : 'Unknown host');
  $description = $important ? 'Important host changed state.' : 'Host changed state.';
  $timestamp = strtotime($date);

  $embed = array(
    'title' => $label . ' is now ' . ($status !== '' ? $status : 'Unknown'),
    'description' => $description,
    'color' => $this->discordStatusColor($status),
    'fields' => array(
      $this->discordEmbedField('Host', $label, true),
      $this->discordEmbedField('IP', $ip, true),
      $this->discordEmbedField('MAC', $mac, true),
      $this->discordEmbedField('Previous', $previous, true),
      $this->discordEmbedField('Current', $status, true),
      $this->discordEmbedField('Important', $important ? 'Yes' : 'No', true),
      $this->discordEmbedField('Time', $date, false)
    )
  );

  if ($timestamp !== false)
    $embed['timestamp'] = date(DATE_ATOM, $timestamp);

  return $embed;
}

public function discordStatusColor(string $status): int {
  if ($status === 'Up')
    return 0x16a34a;
  if ($status === 'Down')
    return 0xdc2626;
  if ($status === 'arp')
    return 0x2563eb;
  if ($status === 'arp-down')
    return 0xf59e0b;
  return 0x64748b;
}

public function discordMentionPayload(): array {
  if ($this->config->discordMention === '')
    return array('allowed_mentions' => array('parse' => array()));
  if ($this->config->discordMention === '@everyone') {
    return array(
      'content' => '@everyone',
      'allowed_mentions' => array('parse' => array('everyone'))
    );
  }
  return array(
    'content' => '<@' . $this->config->discordMention . '>',
    'allowed_mentions' => array('users' => array($this->config->discordMention))
  );
}

public function discordApplyMention(array $payload): array {
  $mention = $this->discordMentionPayload();
  $mentionContent = (string)($mention['content'] ?? '');
  if ($mentionContent !== '') {
    $content = (string)($payload['content'] ?? '');
    if ($content === '')
      $payload['content'] = $mentionContent;
    elseif ($content !== $mentionContent && !str_starts_with($content, $mentionContent . "\n"))
      $payload['content'] = $mentionContent . "\n" . $content;
  }
  $payload['allowed_mentions'] = $mention['allowed_mentions'];
  return $payload;
}

public function discordEmbedField(string $name, string $value, bool $inline): array {
  $value = trim($value);
  if ($value === '')
    $value = '-';

  if (strlen($value) > 1000)
    $value = substr($value, 0, 997) . '...';

  return array(
    'name' => $name,
    'value' => $value,
    'inline' => $inline
  );
}
}
