<?php

declare(strict_types=1);

namespace FenPing\Backend;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

trait DiscordBehavior
{
public function discordWebhookUrl(): string {
  return trim($this->config->discordWebhookUrl);
}

public function discordNotificationsEnabled(): bool {
  return $this->discordWebhookUrl() !== '';
}

public function statsMaxId(): int {
  $value = $this->db()->query("SELECT COALESCE(MAX(id), 0) FROM stats")->fetchColumn();
  return (int)$value;
}

public function sendDiscordStatusChangesSince(?int $afterId): void {
  if ($afterId === null)
    return;
  $this->sendDiscordStatusChanges($this->discordStatusChangesSince($afterId));
}

public function sendDiscordStatusChanges(array $changes): void {
  if (!$this->discordNotificationsEnabled())
    return;
  $changes = $this->notificationFilterStatusChanges($changes);
  foreach ($this->discordNotificationPayloads($changes) as $payload)
    $this->discordPostPayload($payload);
}

public function sendDiscordPortChangesForScan(int $scanId): void {
  $this->sendDiscordPortChanges($this->discordPortChangesForScan($scanId));
}

public function sendDiscordPortChanges(array $changes): void {
  if (!$this->discordNotificationsEnabled())
    return;
  $changes = $this->notificationFilterServiceChanges($changes);
  foreach ($this->discordPortNotificationPayloads($changes) as $payload)
    $this->discordPostPayload($payload);
}

public function sendDiscordIpConflictChanges(array $changes): void {
  if (!$this->discordNotificationsEnabled() || !$this->notificationRules()['ip_conflicts'] || $changes === array())
    return;

  foreach ($this->discordIpConflictPayloads($changes) as $payload)
    $this->discordPostPayload($payload);
}

public function discordIpConflictPayloads(array $changes): array {
  $payloads = array();
  foreach (array_chunk($changes, 10) as $chunk) {
    $embeds = array();
    foreach ($chunk as $change)
      $embeds[] = $this->discordIpConflictEmbed($change);
    $payloads[] = $this->discordApplyMention(array(
      'username' => 'FenPing',
      'embeds' => $embeds
    ));
  }
  return $payloads;
}

public function discordIpConflictEmbed(array $change): array {
  $type = (string)($change['type'] ?? 'detected');
  $resolved = $type === 'resolved';
  $ip = (string)($change['ip'] ?? '');
  $network = (string)($change['network'] ?? '');
  $occurredAt = (string)($change['occurred_at'] ?? '');
  $deviceLines = array();
  foreach ($change['devices'] ?? array() as $device) {
    $name = trim((string)($device['name'] ?? ''));
    $mac = strtolower((string)($device['mac'] ?? ''));
    $vendor = trim((string)($device['vendor'] ?? ''));
    $managedIp = trim((string)($device['managed_ip'] ?? ''));
    $label = $name !== '' ? $name . ' — ' . $mac : $mac;
    if ($vendor !== '')
      $label .= ' (' . $vendor . ')';
    if ($managedIp !== '' && $managedIp !== $ip)
      $label .= ' [reserved ' . $managedIp . ']';
    $deviceLines[] = $label;
  }
  $timestamp = strtotime($occurredAt);
  $embed = array(
    'title' => $resolved ? 'IP conflict resolved: ' . $ip : 'Possible IP conflict: ' . $ip,
    'description' => $resolved
      ? 'The address returned to a single or no ARP responder.'
      : 'Multiple MAC addresses answered for the same IPv4 address.',
    'color' => $resolved ? 0x16a34a : 0xdc2626,
    'fields' => array(
      $this->discordEmbedField('IP', $ip, true),
      $this->discordEmbedField('Network', $network, true),
      $this->discordEmbedField('Devices', implode("\n", $deviceLines), false),
      $this->discordEmbedField('Time', $occurredAt, false)
    )
  );
  if ($timestamp !== false)
    $embed['timestamp'] = date(DATE_ATOM, $timestamp);
  return $embed;
}

public function sendDiscordRestartNotification(): bool {
  if (!$this->discordNotificationsEnabled() || !$this->notificationRules()['restart'])
    return false;

  return $this->discordPostPayload($this->discordRestartPayload());
}

public function discordRestartPayload(): array {
  $host = gethostname();
  if ($host === false || $host === '')
    $host = 'fenping';

  $fields = array(
    $this->discordEmbedField('Host', $host, true),
    $this->discordEmbedField('Time', date('Y-m-d H:i:s T'), true)
  );

  if (($this->config->network ?? '') !== '')
    $fields[] = $this->discordEmbedField('Network', $this->config->network . '.0/24', true);
  if (($this->config->applianceIp ?? '') !== '')
    $fields[] = $this->discordEmbedField('Address', $this->config->applianceIp, true);

  return $this->discordApplyMention(array(
    'username' => 'FenPing',
    'embeds' => array(array(
      'title' => 'FenPing restarted',
      'description' => 'The LAN appliance boot sequence completed.',
      'color' => 0x2563eb,
      'fields' => $fields,
      'timestamp' => date(DATE_ATOM)
    ))
  ));
}

public function discordStatusChangesSince(int $afterId): array {
  $stmt = $this->db()->prepare("
    SELECT
      s.id,
      s.ip,
      s.mac,
      s.status,
      s.date_begin,
      (SELECT prev.status FROM stats prev WHERE prev.ip=s.ip AND prev.id<s.id ORDER BY prev.id DESC LIMIT 1) AS previous_status,
      COALESCE((
        SELECT i.name
        FROM ips i
        WHERE i.ip=s.ip
        ORDER BY i.id DESC
        LIMIT 1
      ), (
        SELECT i.name
        FROM ips i
        WHERE LOWER(i.mac)=LOWER(s.mac)
        ORDER BY i.id DESC
        LIMIT 1
      ), '') AS name,
      COALESCE((
        SELECT i.important
        FROM ips i
        WHERE i.ip=s.ip
        ORDER BY i.id DESC
        LIMIT 1
      ), (
        SELECT i.important
        FROM ips i
        WHERE LOWER(i.mac)=LOWER(s.mac)
        ORDER BY i.id DESC
        LIMIT 1
      ), 0) AS important
    FROM stats s
    WHERE s.id>:after_id
      AND s.ip IS NOT NULL
      AND s.ip<>''
      AND EXISTS (SELECT 1 FROM stats prev_exists WHERE prev_exists.ip=s.ip AND prev_exists.id<s.id)
    ORDER BY s.date_begin ASC, s.id ASC
  ");
  $stmt->execute(array('after_id' => $afterId));

  $changes = array();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['id'] = (int)$row['id'];
    $row['important'] = (int)$row['important'];
    $row['previous_status'] = ($row['previous_status'] ?? '') === '' ? null : $row['previous_status'];
    $changes[] = $row;
  }
  return $changes;
}

public function discordNotificationPayloads(array $changes): array {
  $payloads = array();
  foreach (array(false, true) as $important) {
    $group = array_values(array_filter(
      $changes,
      static fn(array $change): bool => ((int)($change['important'] ?? 0) === 1) === $important
    ));
    foreach (array_chunk($group, 10) as $chunk) {
      $embeds = array();
      foreach ($chunk as $change)
        $embeds[] = $this->discordChangeEmbed($change);
      $payloads[] = $this->discordApplyMention(array(
        'username' => 'FenPing',
        'embeds' => $embeds
      ));
    }
  }
  return $payloads;
}

public function discordPortChangesForScan(int $scanId): array {
  $stmt = $this->db()->prepare("
    SELECT
      c.*,
      COALESCE(NULLIF((SELECT i.name FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1), ''), '') AS name,
      COALESCE(NULLIF((SELECT i.mac FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1), ''), '') AS mac,
      COALESCE((
        SELECT i.important FROM ips i WHERE i.ip=c.ip ORDER BY i.id DESC LIMIT 1
      ), (
        SELECT i.important
        FROM ips i
        WHERE LOWER(i.mac)=LOWER((
          SELECT s.mac FROM stats s
          WHERE s.ip=c.ip AND s.mac IS NOT NULL AND s.mac<>''
          ORDER BY s.id DESC LIMIT 1
        ))
        ORDER BY i.id DESC LIMIT 1
      ), 0) AS important
    FROM scan_port_changes c
    WHERE c.scan_id=:scan_id
    ORDER BY c.port ASC, c.protocol ASC
  ");
  $stmt->execute(array('scan_id' => $scanId));
  $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($changes as &$change)
    $change['important'] = (int)($change['important'] ?? 0);
  unset($change);
  return $changes;
}

public function discordPortNotificationPayloads(array $changes): array {
  $payloads = array();
  foreach (array(false, true) as $important) {
    $group = array_values(array_filter(
      $changes,
      static fn(array $change): bool => ((int)($change['important'] ?? 0) === 1) === $important
    ));
    foreach (array_chunk($group, 10) as $chunk) {
      $embeds = array();
      foreach ($chunk as $change)
        $embeds[] = $this->discordPortChangeEmbed($change);
      $payloads[] = $this->discordApplyMention(array(
        'username' => 'FenPing',
        'embeds' => $embeds
      ));
    }
  }
  return $payloads;
}


public function discordPost(string $message): bool {
  return $this->discordPostPayload(array(
    'username' => 'FenPing',
    'content' => $message
  ));
}

public function discordPostPayload(array $payload): bool {
  $payload = $this->discordApplyMention($payload);
  $url = $this->discordWebhookUrl();
  if ($url === '')
    return false;

  $this->operations->started('notification_delivery');
  $json = json_encode($payload);
  if ($json === false) {
    $this->operations->failed('notification_delivery', 'failed to encode notification payload');
    return false;
  }

  try {
    $response = $this->fenpingHttpRequest($url, array(
      'method' => 'POST',
      'headers' => array(
        'Content-Type' => 'application/json',
        'User-Agent' => 'FenPing Discord notifier'
      ),
      'body' => $json,
      'timeout' => 8,
      'max_bytes' => 1024 * 1024
    ));
  } catch (Throwable $error) {
    $this->operations->failed('notification_delivery', $error->getMessage());
    fwrite(STDERR, 'Discord notification failed: ' . $error->getMessage() . PHP_EOL);
    return false;
  }

  if ($response['status'] < 200 || $response['status'] >= 300) {
    $message = 'HTTP ' . $response['status'];
    $this->operations->failed('notification_delivery', $message);
    fwrite(STDERR, 'Discord notification failed: ' . $message . PHP_EOL);
    return false;
  }

  $this->operations->succeeded('notification_delivery');
  return true;
}
}
