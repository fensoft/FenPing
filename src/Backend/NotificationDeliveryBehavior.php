<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use PDO;
use Throwable;

trait NotificationDeliveryBehavior
{
public function notificationDefaultRules(): array {
  return array(
    'restart' => true,
    'host_status' => array('normal' => true, 'important' => true),
    'service_changes' => array('normal' => true, 'important' => true),
    'ip_conflicts' => true
  );
}

public function notificationRules(): array {
  $row = $this->db()->query("
    SELECT
      restart_enabled,
      host_status_normal_enabled,
      host_status_important_enabled,
      service_changes_normal_enabled,
      service_changes_important_enabled,
      ip_conflicts_enabled
    FROM notification_delivery_settings
    WHERE id=1
  ")->fetch(PDO::FETCH_ASSOC);
  if ($row === false)
    return $this->notificationDefaultRules();

  return array(
    'restart' => (int)$row['restart_enabled'] === 1,
    'host_status' => array(
      'normal' => (int)$row['host_status_normal_enabled'] === 1,
      'important' => (int)$row['host_status_important_enabled'] === 1
    ),
    'service_changes' => array(
      'normal' => (int)$row['service_changes_normal_enabled'] === 1,
      'important' => (int)$row['service_changes_important_enabled'] === 1
    ),
    'ip_conflicts' => (int)$row['ip_conflicts_enabled'] === 1
  );
}

public function notificationRulesUpdate(array $rules): array {
  $rules = $this->notificationValidateRules($rules);
  $stmt = $this->db()->prepare("
    INSERT INTO notification_delivery_settings (
      id,
      restart_enabled,
      host_status_normal_enabled,
      host_status_important_enabled,
      service_changes_normal_enabled,
      service_changes_important_enabled,
      ip_conflicts_enabled
    ) VALUES (1, :restart, :host_normal, :host_important, :service_normal, :service_important, :conflicts)
    ON CONFLICT(id) DO UPDATE SET
      restart_enabled=excluded.restart_enabled,
      host_status_normal_enabled=excluded.host_status_normal_enabled,
      host_status_important_enabled=excluded.host_status_important_enabled,
      service_changes_normal_enabled=excluded.service_changes_normal_enabled,
      service_changes_important_enabled=excluded.service_changes_important_enabled,
      ip_conflicts_enabled=excluded.ip_conflicts_enabled
  ");
  $stmt->execute(array(
    'restart' => $rules['restart'] ? 1 : 0,
    'host_normal' => $rules['host_status']['normal'] ? 1 : 0,
    'host_important' => $rules['host_status']['important'] ? 1 : 0,
    'service_normal' => $rules['service_changes']['normal'] ? 1 : 0,
    'service_important' => $rules['service_changes']['important'] ? 1 : 0,
    'conflicts' => $rules['ip_conflicts'] ? 1 : 0
  ));
  return $rules;
}

public function notificationValidateRules(array $rules): array {
  $this->notificationRequireExactKeys(
    $rules,
    array('restart', 'host_status', 'service_changes', 'ip_conflicts')
  );
  foreach (array('restart', 'ip_conflicts') as $key) {
    if (!is_bool($rules[$key]))
      throw new InvalidArgumentException('notification rules must contain booleans');
  }
  foreach (array('host_status', 'service_changes') as $key) {
    if (!is_array($rules[$key]))
      throw new InvalidArgumentException('notification rules must contain normal and important groups');
    $this->notificationRequireExactKeys($rules[$key], array('normal', 'important'));
    if (!is_bool($rules[$key]['normal']) || !is_bool($rules[$key]['important']))
      throw new InvalidArgumentException('notification rules must contain booleans');
  }
  return array(
    'restart' => $rules['restart'],
    'host_status' => array(
      'normal' => $rules['host_status']['normal'],
      'important' => $rules['host_status']['important']
    ),
    'service_changes' => array(
      'normal' => $rules['service_changes']['normal'],
      'important' => $rules['service_changes']['important']
    ),
    'ip_conflicts' => $rules['ip_conflicts']
  );
}

public function notificationRequireExactKeys(array $value, array $expected): void {
  $actual = array_keys($value);
  sort($actual, SORT_STRING);
  sort($expected, SORT_STRING);
  if ($actual !== $expected)
    throw new InvalidArgumentException('invalid notification rules');
}

public function notificationDelivery(): array {
  return array(
    'rules' => $this->notificationRules(),
    'discord' => array(
      'configured' => $this->discordNotificationsEnabled(),
      'mention_target' => $this->discordMentionTarget()
    ),
    'telegram' => array(
      'configured' => $this->telegramBotConfigured(),
      'chat_selected' => $this->telegramSelectedChatId() !== null
    )
  );
}

public function discordMentionTarget(): ?string {
  if ($this->config->discordMention === '')
    return null;
  return $this->config->discordMention === '@everyone' ? 'everyone' : 'user';
}

public function notificationProvidersEnabled(): bool {
  return $this->discordNotificationsEnabled() || $this->telegramNotificationsEnabled();
}

public function notificationStatusChangesEnabled(): bool {
  if (!$this->notificationProvidersEnabled())
    return false;
  $rules = $this->notificationRules()['host_status'];
  return $rules['normal'] || $rules['important'];
}

public function notificationFilterStatusChanges(array $changes): array {
  $rules = $this->notificationRules()['host_status'];
  return array_values(array_filter($changes, static function(array $change) use ($rules): bool {
    $important = (int)($change['important'] ?? 0) === 1;
    return $rules[$important ? 'important' : 'normal'];
  }));
}

public function notificationFilterServiceChanges(array $changes): array {
  $rules = $this->notificationRules()['service_changes'];
  return array_values(array_filter($changes, static function(array $change) use ($rules): bool {
    $important = (int)($change['important'] ?? 0) === 1;
    return $rules[$important ? 'important' : 'normal'];
  }));
}

public function sendNotificationStatusChangesSince(?int $afterId): void {
  if ($afterId === null || !$this->notificationProvidersEnabled())
    return;
  $changes = $this->discordStatusChangesSince($afterId);
  $this->sendDiscordStatusChanges($changes);
  $this->sendTelegramStatusChanges($changes);
}

public function sendNotificationPortChangesForScan(int $scanId): void {
  if (!$this->notificationProvidersEnabled())
    return;
  $changes = $this->discordPortChangesForScan($scanId);
  $this->sendDiscordPortChanges($changes);
  $this->sendTelegramServiceChanges($changes);
}

public function sendNotificationIpConflictChanges(array $changes): void {
  $this->sendDiscordIpConflictChanges($changes);
  $this->sendTelegramIpConflictChanges($changes);
}

public function sendNotificationRestartNotification(): array {
  $rules = $this->notificationRules();
  if (!$rules['restart'])
    return array('discord' => null, 'telegram' => null);

  return array(
    'discord' => $this->discordNotificationsEnabled()
      ? $this->sendDiscordRestartNotification()
      : null,
    'telegram' => $this->telegramNotificationsEnabled()
      ? $this->sendTelegramRestartNotification()
      : null
  );
}

public function sendTelegramRestartNotification(): bool {
  if (!$this->telegramNotificationsEnabled() || !$this->notificationRules()['restart'])
    return false;
  $host = gethostname();
  if ($host === false || $host === '')
    $host = 'fenping';
  $lines = array(
    'FenPing restarted',
    'Host: ' . $this->notificationTextValue($host),
    'Time: ' . date('Y-m-d H:i:s T')
  );
  if (($this->config->network ?? '') !== '')
    $lines[] = 'Network: ' . $this->config->network . '.0/24';
  if (($this->config->applianceIp ?? '') !== '')
    $lines[] = 'Address: ' . $this->config->applianceIp;
  return $this->telegramPostText(implode("\n", $lines));
}

public function sendTelegramStatusChanges(array $changes): void {
  if (!$this->telegramNotificationsEnabled())
    return;
  $changes = $this->notificationFilterStatusChanges($changes);
  $blocks = array();
  foreach ($changes as $change)
    $blocks[] = $this->telegramStatusBlock($change);
  foreach ($this->telegramBatchMessages('FenPing host status changes', $blocks) as $message)
    $this->telegramPostText($message);
}

public function telegramStatusBlock(array $change): string {
  $name = trim((string)($change['name'] ?? ''));
  $ip = trim((string)($change['ip'] ?? ''));
  $label = $name !== '' ? $name : ($ip !== '' ? $ip : 'Unknown host');
  $previous = trim((string)($change['previous_status'] ?? 'Unknown')) ?: 'Unknown';
  $current = trim((string)($change['status'] ?? 'Unknown')) ?: 'Unknown';
  $lines = array(
    $this->notificationTextValue($label) . ': ' . $previous . ' -> ' . $current,
    'IP: ' . $this->notificationTextValue($ip),
    'MAC: ' . $this->notificationTextValue((string)($change['mac'] ?? '')),
    'Important: ' . ((int)($change['important'] ?? 0) === 1 ? 'Yes' : 'No'),
    'Time: ' . $this->notificationTextValue((string)($change['date_begin'] ?? ''))
  );
  return implode("\n", $lines);
}

public function sendTelegramServiceChanges(array $changes): void {
  if (!$this->telegramNotificationsEnabled())
    return;
  $changes = $this->notificationFilterServiceChanges($changes);
  $blocks = array();
  foreach ($changes as $change)
    $blocks[] = $this->telegramServiceBlock($change);
  foreach ($this->telegramBatchMessages('FenPing service changes', $blocks) as $message)
    $this->telegramPostText($message);
}

public function telegramServiceBlock(array $change): string {
  $name = trim((string)($change['name'] ?? ''));
  $ip = trim((string)($change['ip'] ?? ''));
  $label = $name !== '' ? $name : ($ip !== '' ? $ip : 'Unknown host');
  $type = (string)($change['change_type'] ?? 'changed');
  $action = array('appeared' => 'appeared', 'disappeared' => 'disappeared', 'changed' => 'changed version')[$type] ?? 'changed';
  return implode("\n", array(
    $this->notificationTextValue($label) . ': ' . (int)($change['port'] ?? 0)
      . '/' . strtolower((string)($change['protocol'] ?? '')) . ' ' . $action,
    'IP: ' . $this->notificationTextValue($ip),
    'Previous: ' . $this->notificationTextValue($this->discordPortServiceLabel($change, 'previous')),
    'Current: ' . $this->notificationTextValue($this->discordPortServiceLabel($change, 'current')),
    'Important: ' . ((int)($change['important'] ?? 0) === 1 ? 'Yes' : 'No'),
    'Scan: ' . $this->notificationTextValue((string)($change['mode'] ?? '-'))
  ));
}

public function sendTelegramIpConflictChanges(array $changes): void {
  if (!$this->telegramNotificationsEnabled() || !$this->notificationRules()['ip_conflicts'] || $changes === array())
    return;
  $blocks = array();
  foreach ($changes as $change)
    $blocks[] = $this->telegramIpConflictBlock($change);
  foreach ($this->telegramBatchMessages('FenPing IP conflict changes', $blocks) as $message)
    $this->telegramPostText($message);
}

public function telegramIpConflictBlock(array $change): string {
  $resolved = (string)($change['type'] ?? 'detected') === 'resolved';
  $devices = array();
  foreach ($change['devices'] ?? array() as $device) {
    $name = trim((string)($device['name'] ?? ''));
    $mac = strtolower(trim((string)($device['mac'] ?? '')));
    $devices[] = $this->notificationTextValue($name !== '' ? $name . ' - ' . $mac : $mac);
  }
  return implode("\n", array(
    ($resolved ? 'IP conflict resolved: ' : 'Possible IP conflict: ')
      . $this->notificationTextValue((string)($change['ip'] ?? '')),
    'Network: ' . $this->notificationTextValue((string)($change['network'] ?? '')),
    'Devices: ' . ($devices === array() ? '-' : implode(', ', $devices)),
    'Time: ' . $this->notificationTextValue((string)($change['occurred_at'] ?? ''))
  ));
}

public function telegramBatchMessages(string $heading, array $blocks): array {
  if ($blocks === array())
    return array();
  $messages = array();
  $current = $heading;
  foreach ($blocks as $block) {
    $block = $this->notificationTextValue((string)$block, 1200);
    $candidate = $current . "\n\n" . $block;
    if (strlen($candidate) > 3500 && $current !== $heading) {
      $messages[] = $current;
      $current = $heading . "\n\n" . $block;
    } else {
      $current = $candidate;
    }
  }
  if ($current !== $heading)
    $messages[] = $current;
  return $messages;
}

public function notificationTextValue(string $value, int $maxCharacters = 400): string {
  $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
  if ($value === '')
    return '-';
  if (strlen($value) <= $maxCharacters)
    return $value;
  if (preg_match('/^.{0,' . max(1, $maxCharacters - 1) . '}/us', $value, $matches))
    return rtrim($matches[0]) . '…';
  return '-';
}

public function telegramPostText(string $text): bool {
  $chatId = $this->telegramSelectedChatId();
  if ($chatId === null || trim($text) === '')
    return false;

  $this->operations->started('telegram_notification_delivery');
  try {
    $this->telegramApiRequest('sendMessage', array(
      'chat_id' => $chatId,
      'text' => $text
    ));
  } catch (Throwable $error) {
    $message = $error->getMessage();
    $this->operations->failed('telegram_notification_delivery', $message);
    fwrite(STDERR, 'Telegram notification failed: ' . $message . PHP_EOL);
    return false;
  }

  $this->operations->succeeded('telegram_notification_delivery');
  return true;
}
}
