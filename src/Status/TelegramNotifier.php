<?php

declare(strict_types=1);

namespace FenPing\Status;

use FenPing\Config\AppConfig;
use FenPing\Discord\DiscordPayloadBuilder;
use FenPing\Health\OperationTracker;
use Throwable;

final readonly class TelegramNotifier
{
    public function __construct(
        private AppConfig $config,
        private NotificationRuleRepository $rules,
        private TelegramChatRepository $chats,
        private TelegramApiClient $api,
        private OperationTracker $operations,
        private DiscordPayloadBuilder $payloads,
    ) {
    }

public function sendTelegramRestartNotification(): bool {
  if (!$this->chats->telegramNotificationsEnabled() || !$this->rules->notificationRules()['restart'])
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
  if (!$this->chats->telegramNotificationsEnabled())
    return;
  $changes = $this->filterStatusChanges($changes);
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
  if (!$this->chats->telegramNotificationsEnabled())
    return;
  $changes = $this->filterServiceChanges($changes);
  $blocks = array();
  foreach ($changes as $change)
    $blocks[] = $this->telegramServiceBlock($change);
  foreach ($this->telegramBatchMessages('FenPing service changes', $blocks) as $message)
    $this->telegramPostText($message);
}

public function sendTelegramAnomalyChanges(array $changes): void {
  if (!$this->chats->telegramNotificationsEnabled()) return;
  $changes = $this->rules->filterAnomalies($changes);
  $blocks = array();
  foreach ($changes as $change) {
    $type = str_replace('_', ' ', (string)($change['anomaly_type'] ?? $change['type'] ?? 'anomaly'));
    $event = (string)($change['event_type'] ?? $change['event'] ?? 'detected');
    $lines = array(
      ucfirst($type) . ' ' . $event,
      'Network: ' . $this->notificationTextValue((string)($change['network'] ?? '')),
      'IP: ' . $this->notificationTextValue((string)($change['ip'] ?? '')),
      'MAC: ' . $this->notificationTextValue((string)($change['mac'] ?? '')),
      'Important: ' . ((int)($change['important'] ?? 0) === 1 ? 'Yes' : 'No'),
      'Time: ' . $this->notificationTextValue((string)($change['occurred_at'] ?? ''))
    );
    if (($change['anomaly_type'] ?? '') === 'ip_change')
      $lines[] = 'Previous IP: ' . $this->notificationTextValue((string)($change['previous_ip'] ?? ''));
    if (($change['anomaly_type'] ?? '') === 'unexpected_vendor')
      $lines[] = 'Vendor: ' . $this->notificationTextValue((string)($change['vendor'] ?? ''));
    $blocks[] = implode("\n", $lines);
  }
  foreach ($this->telegramBatchMessages('FenPing network anomalies', $blocks) as $message)
    $this->telegramPostText($message);
}

public function sendTelegramManualServiceChange(array $change): void {
  if (!$this->chats->telegramNotificationsEnabled())
    return;
  $target = (string)($change['target'] ?? '');
  if (($change['port'] ?? null) !== null)
    $target .= ':' . (int)$change['port'];
  $this->telegramPostText(implode("\n", array(
    'FenPing manual service change',
    $this->notificationTextValue((string)($change['name'] ?? 'Manual service')) . ': '
      . (string)($change['previous_status'] ?? 'unknown') . ' -> ' . (string)($change['status'] ?? 'unknown'),
    'Type: ' . strtoupper((string)($change['type'] ?? 'service')),
    'Target: ' . $this->notificationTextValue($target),
    'Detail: ' . $this->notificationTextValue((string)($change['check_detail'] ?? '-')),
    'Time: ' . $this->notificationTextValue((string)($change['occurred_at'] ?? ''))
  )));
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
    'Previous: ' . $this->notificationTextValue($this->payloads->discordPortServiceLabel($change, 'previous')),
    'Current: ' . $this->notificationTextValue($this->payloads->discordPortServiceLabel($change, 'current')),
    'Important: ' . ((int)($change['important'] ?? 0) === 1 ? 'Yes' : 'No'),
    'Scan: ' . $this->notificationTextValue((string)($change['mode'] ?? '-'))
  ));
}

public function sendTelegramIpConflictChanges(array $changes): void {
  if (!$this->chats->telegramNotificationsEnabled() || !$this->rules->notificationRules()['ip_conflicts'] || $changes === array())
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
  $chatId = $this->chats->telegramSelectedChatId();
  if ($chatId === null || trim($text) === '')
    return false;

  $this->operations->started('telegram_notification_delivery');
  try {
    $this->api->telegramApiRequest('sendMessage', array(
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

private function filterStatusChanges(array $changes): array {
  $rules = $this->rules->notificationRules()['host_status'];
  return array_values(array_filter($changes, static function(array $change) use ($rules): bool {
    return $rules[(int)($change['important'] ?? 0) === 1 ? 'important' : 'normal'];
  }));
}

private function filterServiceChanges(array $changes): array {
  return $this->rules->filterServiceChanges($changes);
}
}
