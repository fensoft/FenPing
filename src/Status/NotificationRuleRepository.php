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
    'ip_conflicts' => true,
    'network_anomalies' => array(
      'open_ports' => array('normal' => true, 'important' => true),
      'unexpected_vendors' => array('normal' => true, 'important' => true),
      'ip_changes' => array('normal' => true, 'important' => true),
      'duplicate_identities' => array('normal' => true, 'important' => true),
      'churn' => array('normal' => true, 'important' => true)
    )
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
      ip_conflicts_enabled,
      anomaly_open_ports_normal_enabled, anomaly_open_ports_important_enabled,
      anomaly_unexpected_vendors_normal_enabled, anomaly_unexpected_vendors_important_enabled,
      anomaly_ip_changes_normal_enabled, anomaly_ip_changes_important_enabled,
      anomaly_duplicate_identities_normal_enabled, anomaly_duplicate_identities_important_enabled,
      anomaly_churn_normal_enabled, anomaly_churn_important_enabled
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
    'ip_conflicts' => (int)$row['ip_conflicts_enabled'] === 1,
    'network_anomalies' => array(
      'open_ports' => array('normal' => (int)$row['anomaly_open_ports_normal_enabled'] === 1, 'important' => (int)$row['anomaly_open_ports_important_enabled'] === 1),
      'unexpected_vendors' => array('normal' => (int)$row['anomaly_unexpected_vendors_normal_enabled'] === 1, 'important' => (int)$row['anomaly_unexpected_vendors_important_enabled'] === 1),
      'ip_changes' => array('normal' => (int)$row['anomaly_ip_changes_normal_enabled'] === 1, 'important' => (int)$row['anomaly_ip_changes_important_enabled'] === 1),
      'duplicate_identities' => array('normal' => (int)$row['anomaly_duplicate_identities_normal_enabled'] === 1, 'important' => (int)$row['anomaly_duplicate_identities_important_enabled'] === 1),
      'churn' => array('normal' => (int)$row['anomaly_churn_normal_enabled'] === 1, 'important' => (int)$row['anomaly_churn_important_enabled'] === 1)
    )
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
      ip_conflicts_enabled,
      anomaly_open_ports_normal_enabled, anomaly_open_ports_important_enabled,
      anomaly_unexpected_vendors_normal_enabled, anomaly_unexpected_vendors_important_enabled,
      anomaly_ip_changes_normal_enabled, anomaly_ip_changes_important_enabled,
      anomaly_duplicate_identities_normal_enabled, anomaly_duplicate_identities_important_enabled,
      anomaly_churn_normal_enabled, anomaly_churn_important_enabled
    ) VALUES (1, :restart, :host_normal, :host_important, :service_normal, :service_important, :conflicts,
      :open_normal, :open_important, :vendor_normal, :vendor_important, :ip_normal, :ip_important,
      :duplicate_normal, :duplicate_important, :churn_normal, :churn_important)
    ON CONFLICT(id) DO UPDATE SET
      restart_enabled=excluded.restart_enabled,
      host_status_normal_enabled=excluded.host_status_normal_enabled,
      host_status_important_enabled=excluded.host_status_important_enabled,
      service_changes_normal_enabled=excluded.service_changes_normal_enabled,
      service_changes_important_enabled=excluded.service_changes_important_enabled,
      ip_conflicts_enabled=excluded.ip_conflicts_enabled,
      anomaly_open_ports_normal_enabled=excluded.anomaly_open_ports_normal_enabled,
      anomaly_open_ports_important_enabled=excluded.anomaly_open_ports_important_enabled,
      anomaly_unexpected_vendors_normal_enabled=excluded.anomaly_unexpected_vendors_normal_enabled,
      anomaly_unexpected_vendors_important_enabled=excluded.anomaly_unexpected_vendors_important_enabled,
      anomaly_ip_changes_normal_enabled=excluded.anomaly_ip_changes_normal_enabled,
      anomaly_ip_changes_important_enabled=excluded.anomaly_ip_changes_important_enabled,
      anomaly_duplicate_identities_normal_enabled=excluded.anomaly_duplicate_identities_normal_enabled,
      anomaly_duplicate_identities_important_enabled=excluded.anomaly_duplicate_identities_important_enabled,
      anomaly_churn_normal_enabled=excluded.anomaly_churn_normal_enabled,
      anomaly_churn_important_enabled=excluded.anomaly_churn_important_enabled
  ");
  $stmt->execute(array(
    'restart' => $rules['restart'] ? 1 : 0,
    'host_normal' => $rules['host_status']['normal'] ? 1 : 0,
    'host_important' => $rules['host_status']['important'] ? 1 : 0,
    'service_normal' => $rules['service_changes']['normal'] ? 1 : 0,
    'service_important' => $rules['service_changes']['important'] ? 1 : 0,
    'conflicts' => $rules['ip_conflicts'] ? 1 : 0,
    'open_normal' => $rules['network_anomalies']['open_ports']['normal'] ? 1 : 0,
    'open_important' => $rules['network_anomalies']['open_ports']['important'] ? 1 : 0,
    'vendor_normal' => $rules['network_anomalies']['unexpected_vendors']['normal'] ? 1 : 0,
    'vendor_important' => $rules['network_anomalies']['unexpected_vendors']['important'] ? 1 : 0,
    'ip_normal' => $rules['network_anomalies']['ip_changes']['normal'] ? 1 : 0,
    'ip_important' => $rules['network_anomalies']['ip_changes']['important'] ? 1 : 0,
    'duplicate_normal' => $rules['network_anomalies']['duplicate_identities']['normal'] ? 1 : 0,
    'duplicate_important' => $rules['network_anomalies']['duplicate_identities']['important'] ? 1 : 0,
    'churn_normal' => $rules['network_anomalies']['churn']['normal'] ? 1 : 0,
    'churn_important' => $rules['network_anomalies']['churn']['important'] ? 1 : 0
  ));
  return $rules;
}

public function notificationValidateRules(array $rules): array {
  if (!array_key_exists('network_anomalies', $rules))
    $rules['network_anomalies'] = $this->notificationDefaultRules()['network_anomalies'];
  $this->notificationRequireExactKeys(
    $rules,
    array('restart', 'host_status', 'service_changes', 'ip_conflicts', 'network_anomalies')
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
  if (!is_array($rules['network_anomalies']))
    throw new InvalidArgumentException('notification rules must contain network anomaly groups');
  $anomalyKeys = array('open_ports', 'unexpected_vendors', 'ip_changes', 'duplicate_identities', 'churn');
  $this->notificationRequireExactKeys($rules['network_anomalies'], $anomalyKeys);
  foreach ($anomalyKeys as $key) {
    if (!is_array($rules['network_anomalies'][$key]))
      throw new InvalidArgumentException('network anomaly rules must contain normal and important groups');
    $this->notificationRequireExactKeys($rules['network_anomalies'][$key], array('normal', 'important'));
    if (!is_bool($rules['network_anomalies'][$key]['normal']) || !is_bool($rules['network_anomalies'][$key]['important']))
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
    'ip_conflicts' => $rules['ip_conflicts'],
    'network_anomalies' => $rules['network_anomalies']
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
  $allRules = $this->notificationRules();
  return array_values(array_filter($changes, static function(array $change) use ($allRules): bool {
    $importance = (int)($change['important'] ?? 0) === 1 ? 'important' : 'normal';
    $rules = (string)($change['change_type'] ?? '') === 'appeared'
      ? $allRules['network_anomalies']['open_ports']
      : $allRules['service_changes'];
    return $rules[$importance];
  }));
}

public function filterAnomalies(array $changes): array {
  $rules = $this->notificationRules()['network_anomalies'];
  $mapping = array(
    'unexpected_vendor' => 'unexpected_vendors', 'ip_change' => 'ip_changes',
    'duplicate_identity' => 'duplicate_identities', 'churn' => 'churn', 'open_port' => 'open_ports'
  );
  return array_values(array_filter($changes, static function(array $change) use ($rules, $mapping): bool {
    $group = $mapping[(string)($change['anomaly_type'] ?? $change['type'] ?? '')] ?? null;
    if ($group === null) return false;
    $importance = (int)($change['important'] ?? 0) === 1 ? 'important' : 'normal';
    return $rules[$group][$importance];
  }));
}
}
