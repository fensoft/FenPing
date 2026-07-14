<?php

declare(strict_types=1);

namespace FenPing\Status;

use InvalidArgumentException;
use PDO;
use FenPing\Database\DatabaseManager;

final readonly class NotificationRuleRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

public function notificationDefaultRules(): array {
  return array(
    'restart' => true,
    'host_status' => array('normal' => true, 'important' => true),
    'service_changes' => array('normal' => true, 'important' => true),
    'ip_conflicts' => true
  );
}

public function notificationRules(): array {
  $row = $this->database->connection()->query("
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
  $stmt = $this->database->connection()->prepare("
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


public function filterStatusChanges(array $changes): array {
  $rules = $this->notificationRules()['host_status'];
  return array_values(array_filter($changes, static fn(array $change): bool => $rules[(int)($change['important'] ?? 0) === 1 ? 'important' : 'normal']));
}

public function filterServiceChanges(array $changes): array {
  $rules = $this->notificationRules()['service_changes'];
  return array_values(array_filter($changes, static fn(array $change): bool => $rules[(int)($change['important'] ?? 0) === 1 ? 'important' : 'normal']));
}
}
