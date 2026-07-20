<?php

declare(strict_types=1);

namespace FenPing\Backup;

final readonly class BackupTableCatalog
{
public function backupTableNames(): array {
  return array(
    'device_approvals', 'netboot_images', 'dns_override_groups', 'monitored_services', 'ips', 'tags', 'inventory_saved_filters',
    'inventory_device_metadata', 'host_tags', 'inventory_saved_filter_tags',
    'inventory_device_tags', 'leases', 'oui_vendors',
    'ip_conflicts', 'ip_conflict_devices', 'ip_conflict_monitor',
    'network_anomaly_monitors', 'network_identity_state', 'network_vendor_baselines',
    'network_observation_runs', 'network_presence_events', 'network_anomaly_conditions',
    'network_anomaly_events',
    'notification_delivery_settings', 'scheduled_report_settings', 'scheduled_report_runs',
    'ping', 'range', 'scan_snapshots', 'scans', 'scan_port_changes',
    'scan_snapshot_addresses', 'scan_snapshot_hostnames',
    'scan_snapshot_scopes', 'scan_snapshot_ports',
    'scan_snapshot_port_cpes', 'scan_snapshot_extra_ports',
    'scan_snapshot_extra_reasons', 'scan_snapshot_os_matches',
    'scan_snapshot_os_classes', 'scan_snapshot_os_cpes',
    'scan_snapshot_scripts', 'scan_snapshot_script_nodes',
    'scan_snapshot_trace_hops', 'stats', 'stats_old', 'users'
  );
}

}
