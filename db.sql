-- Canonical schema for new databases. Existing databases advance through migrations/.

CREATE TABLE IF NOT EXISTS netboot_images (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  filename TEXT NOT NULL UNIQUE,
  original_name TEXT,
  size INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS dns_override_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT COLLATE NOCASE NOT NULL UNIQUE,
  enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
  contents TEXT NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ips (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT COLLATE NOCASE UNIQUE,
  mac TEXT COLLATE NOCASE UNIQUE,
  ip TEXT UNIQUE,
  important INTEGER,
  repeater INTEGER,
  web INTEGER,
  router INTEGER,
  dns TEXT,
  netboot_image_id INTEGER,
  scan_profile TEXT NOT NULL DEFAULT 'standard',
  scan_interval_hours INTEGER NOT NULL DEFAULT 24,
  display_name TEXT,
  notes TEXT,
  location TEXT,
  owner TEXT,
  model TEXT,
  icon TEXT
);

CREATE TABLE IF NOT EXISTS tags (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT COLLATE NOCASE NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS host_tags (
  host_id INTEGER NOT NULL,
  tag_id INTEGER NOT NULL,
  PRIMARY KEY (host_id, tag_id),
  FOREIGN KEY (host_id) REFERENCES ips (id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS inventory_saved_filters (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT COLLATE NOCASE NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS inventory_saved_filter_tags (
  filter_id INTEGER NOT NULL,
  tag_id INTEGER NOT NULL,
  PRIMARY KEY (filter_id, tag_id),
  FOREIGN KEY (filter_id) REFERENCES inventory_saved_filters (id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS inventory_device_metadata (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  network_name TEXT NOT NULL,
  container_name TEXT NOT NULL,
  display_name TEXT,
  important INTEGER,
  web INTEGER,
  scan_profile TEXT NOT NULL DEFAULT 'lightweight',
  scan_interval_hours INTEGER NOT NULL DEFAULT 24,
  notes TEXT,
  location TEXT,
  owner TEXT,
  model TEXT,
  icon TEXT,
  UNIQUE (network_name, container_name)
);

CREATE TABLE IF NOT EXISTS inventory_device_tags (
  device_id INTEGER NOT NULL,
  tag_id INTEGER NOT NULL,
  PRIMARY KEY (device_id, tag_id),
  FOREIGN KEY (device_id) REFERENCES inventory_device_metadata (id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS leases (
  ip TEXT NOT NULL,
  `hardware-ethernet` TEXT NOT NULL,
  `client-hostname` TEXT,
  ends DATETIME NOT NULL,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  active INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY (`hardware-ethernet`, ip)
);

CREATE TABLE IF NOT EXISTS device_approvals (
  mac TEXT PRIMARY KEY,
  approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ip_conflicts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  network TEXT NOT NULL,
  ip TEXT NOT NULL,
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME
);

CREATE TABLE IF NOT EXISTS ip_conflict_devices (
  conflict_id INTEGER NOT NULL,
  mac TEXT COLLATE NOCASE NOT NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conflict_id, mac),
  FOREIGN KEY (conflict_id) REFERENCES ip_conflicts (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ip_conflict_monitor (
  network TEXT PRIMARY KEY,
  last_attempt_at DATETIME NOT NULL,
  last_success_at DATETIME,
  last_error_at DATETIME,
  error TEXT
);

CREATE TABLE IF NOT EXISTS ping (
  ip TEXT PRIMARY KEY,
  mac TEXT COLLATE NOCASE,
  status TEXT,
  date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `range` (
  ip_begin TEXT,
  type TEXT
);

CREATE TABLE IF NOT EXISTS stats (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT,
  mac TEXT COLLATE NOCASE,
  status TEXT,
  date_begin DATETIME DEFAULT CURRENT_TIMESTAMP,
  date_end DATETIME DEFAULT CURRENT_TIMESTAMP,
  nb_scan INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS stats_old (
  ip TEXT,
  mac TEXT,
  status TEXT,
  date DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS scan_snapshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,
  mode TEXT NOT NULL,
  result_hash TEXT NOT NULL,
  content_hash TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (ip, mode, content_hash)
);

CREATE TABLE IF NOT EXISTS scans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,
  mode TEXT NOT NULL,
  state TEXT NOT NULL DEFAULT 'running',
  network TEXT,
  request_source TEXT NOT NULL DEFAULT 'legacy',
  queued_at DATETIME,
  progress_percent INTEGER,
  progress_phase TEXT,
  progress_updated_at DATETIME,
  cancel_requested_at DATETIME,
  status TEXT,
  date_begin DATETIME DEFAULT CURRENT_TIMESTAMP,
  date_end DATETIME,
  duration INTEGER,
  ports_count INTEGER NOT NULL DEFAULT 0,
  snapshot_id INTEGER,
  result_changed INTEGER NOT NULL DEFAULT 0,
  port_changes_processed INTEGER NOT NULL DEFAULT 0,
  scanner TEXT,
  scanner_version TEXT,
  scan_args TEXT,
  host_reason TEXT,
  host_reason_ttl INTEGER,
  last_boot DATETIME,
  uptime_seconds INTEGER,
  distance INTEGER,
  error TEXT
);

CREATE TABLE IF NOT EXISTS operation_status (
  operation TEXT PRIMARY KEY,
  state TEXT NOT NULL,
  last_started_at DATETIME,
  last_finished_at DATETIME,
  last_success_at DATETIME,
  last_failure_at DATETIME,
  last_error TEXT,
  success_count INTEGER NOT NULL DEFAULT 0,
  failure_count INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS operation_failures (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  operation TEXT NOT NULL,
  failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  error TEXT
);

CREATE TABLE IF NOT EXISTS notification_delivery_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  restart_enabled INTEGER NOT NULL DEFAULT 1 CHECK (restart_enabled IN (0, 1)),
  host_status_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (host_status_normal_enabled IN (0, 1)),
  host_status_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (host_status_important_enabled IN (0, 1)),
  service_changes_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (service_changes_normal_enabled IN (0, 1)),
  service_changes_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (service_changes_important_enabled IN (0, 1)),
  ip_conflicts_enabled INTEGER NOT NULL DEFAULT 1 CHECK (ip_conflicts_enabled IN (0, 1)),
  telegram_chat_id TEXT,
  telegram_bot_fingerprint TEXT
);

INSERT OR IGNORE INTO notification_delivery_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS scheduled_report_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  daily_enabled INTEGER NOT NULL DEFAULT 0 CHECK (daily_enabled IN (0, 1)),
  weekly_enabled INTEGER NOT NULL DEFAULT 0 CHECK (weekly_enabled IN (0, 1)),
  hour_utc INTEGER NOT NULL DEFAULT 8 CHECK (hour_utc BETWEEN 0 AND 23),
  weekly_day INTEGER NOT NULL DEFAULT 1 CHECK (weekly_day BETWEEN 0 AND 6),
  certificate_warning_days INTEGER NOT NULL DEFAULT 30 CHECK (certificate_warning_days BETWEEN 1 AND 365),
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO scheduled_report_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS scheduled_report_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  frequency TEXT NOT NULL CHECK (frequency IN ('daily', 'weekly')),
  period_key TEXT NOT NULL,
  scheduled_for DATETIME NOT NULL,
  window_start DATETIME NOT NULL,
  window_end DATETIME NOT NULL,
  state TEXT NOT NULL CHECK (state IN ('running', 'success', 'failure', 'skipped')),
  summary_json TEXT,
  error TEXT,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME,
  UNIQUE (frequency, period_key)
);

CREATE TABLE IF NOT EXISTS telegram_known_chats (
  chat_id TEXT PRIMARY KEY,
  chat_type TEXT NOT NULL,
  chat_title TEXT,
  chat_username TEXT,
  chat_first_name TEXT,
  chat_last_name TEXT,
  user_id TEXT,
  user_is_bot INTEGER CHECK (user_is_bot IS NULL OR user_is_bot IN (0, 1)),
  user_first_name TEXT,
  user_last_name TEXT,
  user_username TEXT,
  user_language_code TEXT,
  last_update_id INTEGER NOT NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS scan_port_changes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scan_id INTEGER NOT NULL,
  ip TEXT NOT NULL,
  mode TEXT NOT NULL,
  change_type TEXT NOT NULL,
  protocol TEXT NOT NULL,
  port INTEGER NOT NULL,
  previous_service TEXT,
  previous_version TEXT,
  current_service TEXT,
  current_version TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (scan_id, protocol, port)
);

CREATE TABLE IF NOT EXISTS scan_snapshot_scopes (
  snapshot_id INTEGER NOT NULL,
  protocol TEXT NOT NULL,
  port_begin INTEGER NOT NULL,
  port_end INTEGER NOT NULL,
  PRIMARY KEY (snapshot_id, protocol, port_begin, port_end),
  FOREIGN KEY (snapshot_id) REFERENCES scan_snapshots (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_addresses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  snapshot_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  address TEXT NOT NULL,
  address_type TEXT NOT NULL,
  vendor TEXT,
  UNIQUE (snapshot_id, address_type, address),
  FOREIGN KEY (snapshot_id) REFERENCES scan_snapshots (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_hostnames (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  snapshot_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  hostname TEXT NOT NULL,
  hostname_type TEXT NOT NULL,
  UNIQUE (snapshot_id, hostname_type, hostname),
  FOREIGN KEY (snapshot_id) REFERENCES scan_snapshots (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_ports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  snapshot_id INTEGER NOT NULL,
  protocol TEXT NOT NULL,
  port INTEGER NOT NULL,
  state TEXT NOT NULL,
  reason TEXT,
  reason_ttl INTEGER,
  service TEXT,
  product TEXT,
  version TEXT,
  extra_info TEXT,
  tunnel TEXT,
  method TEXT,
  confidence INTEGER,
  os_type TEXT,
  UNIQUE (snapshot_id, protocol, port),
  FOREIGN KEY (snapshot_id) REFERENCES scan_snapshots (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_port_cpes (
  port_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  cpe TEXT NOT NULL,
  PRIMARY KEY (port_id, position),
  FOREIGN KEY (port_id) REFERENCES scan_snapshot_ports (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_extra_ports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  snapshot_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  state TEXT NOT NULL,
  count INTEGER NOT NULL,
  FOREIGN KEY (snapshot_id) REFERENCES scan_snapshots (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_extra_reasons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  extra_port_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  reason TEXT NOT NULL,
  count INTEGER NOT NULL,
  protocol TEXT,
  ports TEXT,
  FOREIGN KEY (extra_port_id) REFERENCES scan_snapshot_extra_ports (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_os_matches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  snapshot_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  name TEXT NOT NULL,
  accuracy INTEGER NOT NULL,
  FOREIGN KEY (snapshot_id) REFERENCES scan_snapshots (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_os_classes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  os_match_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  vendor TEXT,
  os_family TEXT,
  os_generation TEXT,
  device_type TEXT,
  accuracy INTEGER,
  FOREIGN KEY (os_match_id) REFERENCES scan_snapshot_os_matches (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_os_cpes (
  os_class_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  cpe TEXT NOT NULL,
  PRIMARY KEY (os_class_id, position),
  FOREIGN KEY (os_class_id) REFERENCES scan_snapshot_os_classes (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_scripts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  snapshot_id INTEGER NOT NULL,
  port_id INTEGER,
  position INTEGER NOT NULL,
  script_id TEXT NOT NULL,
  output TEXT,
  FOREIGN KEY (snapshot_id) REFERENCES scan_snapshots (id) ON DELETE CASCADE,
  FOREIGN KEY (port_id) REFERENCES scan_snapshot_ports (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_script_nodes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  script_id INTEGER NOT NULL,
  parent_id INTEGER,
  position INTEGER NOT NULL,
  node_type TEXT NOT NULL,
  node_key TEXT,
  value TEXT,
  FOREIGN KEY (script_id) REFERENCES scan_snapshot_scripts (id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES scan_snapshot_script_nodes (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_snapshot_trace_hops (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  snapshot_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  protocol TEXT,
  port INTEGER,
  ttl INTEGER NOT NULL,
  ip TEXT NOT NULL,
  hostname TEXT,
  rtt REAL,
  FOREIGN KEY (snapshot_id) REFERENCES scan_snapshots (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS users (
  login TEXT,
  pass TEXT
);

CREATE TABLE IF NOT EXISTS audit_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor TEXT NOT NULL,
  remote_address TEXT,
  user_agent TEXT,
  action TEXT NOT NULL,
  resource_type TEXT NOT NULL,
  resource_id TEXT,
  summary TEXT NOT NULL,
  details_json TEXT NOT NULL DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS monitored_services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source TEXT NOT NULL CHECK (source IN ('discovered', 'manual')),
  type TEXT NOT NULL CHECK (type IN ('discovered', 'https', 'ssh', 'proxy', 'socks5')),
  name TEXT,
  target TEXT NOT NULL,
  port INTEGER CHECK (port IS NULL OR port BETWEEN 1 AND 65535),
  protocol TEXT CHECK (protocol IS NULL OR protocol IN ('tcp', 'udp')),
  service TEXT,
  version TEXT,
  tunnel TEXT,
  last_seen_at DATETIME,
  check_status TEXT CHECK (check_status IS NULL OR check_status IN ('pending', 'healthy', 'unhealthy')),
  check_detail TEXT,
  observed_ip TEXT,
  last_checked_at DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (
    (source='discovered' AND type='discovered' AND port IS NOT NULL AND protocol IS NOT NULL AND check_status IS NULL)
    OR
    (source='manual' AND type IN ('https', 'ssh', 'proxy', 'socks5') AND name IS NOT NULL AND protocol IS NULL)
  )
);

CREATE TABLE IF NOT EXISTS oui_vendors (
  prefix_length INTEGER NOT NULL,
  prefix TEXT NOT NULL,
  vendor TEXT NOT NULL,
  PRIMARY KEY (prefix_length, prefix)
);

CREATE INDEX IF NOT EXISTS ips_netboot_image_id ON ips (netboot_image_id);
CREATE INDEX IF NOT EXISTS host_tags_tag_id ON host_tags (tag_id, host_id);
CREATE INDEX IF NOT EXISTS inventory_saved_filter_tags_tag_id ON inventory_saved_filter_tags (tag_id, filter_id);
CREATE INDEX IF NOT EXISTS inventory_device_tags_tag_id ON inventory_device_tags (tag_id, device_id);
CREATE INDEX IF NOT EXISTS leases_ip ON leases (ip);
CREATE INDEX IF NOT EXISTS leases_ends ON leases (ends);
CREATE INDEX IF NOT EXISTS leases_active_last_seen ON leases (active, last_seen);
CREATE INDEX IF NOT EXISTS leases_mac_last_seen ON leases (`hardware-ethernet`, last_seen);
CREATE INDEX IF NOT EXISTS device_approvals_approved_at ON device_approvals (approved_at);
CREATE UNIQUE INDEX IF NOT EXISTS ip_conflicts_one_active_per_ip ON ip_conflicts (network, ip) WHERE resolved_at IS NULL;
CREATE INDEX IF NOT EXISTS ip_conflicts_detected ON ip_conflicts (detected_at);
CREATE INDEX IF NOT EXISTS ip_conflicts_resolved ON ip_conflicts (resolved_at);
CREATE INDEX IF NOT EXISTS ip_conflict_devices_mac ON ip_conflict_devices (mac COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS ping_mac ON ping (mac COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS range_ip_begin ON `range` (ip_begin);
CREATE INDEX IF NOT EXISTS stats_ip ON stats (ip);
CREATE INDEX IF NOT EXISTS stats_ip_date_begin ON stats (ip, date_begin);
CREATE INDEX IF NOT EXISTS stats_date_begin ON stats (date_begin);
CREATE INDEX IF NOT EXISTS stats_date_end ON stats (date_end);
CREATE INDEX IF NOT EXISTS scans_ip_date ON scans (ip, date_begin);
CREATE INDEX IF NOT EXISTS scans_ip_id ON scans (ip, id);
CREATE INDEX IF NOT EXISTS scans_snapshot_id ON scans (snapshot_id);
CREATE INDEX IF NOT EXISTS scans_state ON scans (state);
CREATE INDEX IF NOT EXISTS scans_queue ON scans (state, mode, id);
CREATE INDEX IF NOT EXISTS scans_queued_at ON scans (state, queued_at);
CREATE INDEX IF NOT EXISTS scans_network_state ON scans (network, state);
CREATE INDEX IF NOT EXISTS scans_network_source_started ON scans (network, request_source, date_begin);
CREATE UNIQUE INDEX IF NOT EXISTS scans_one_running_per_ip ON scans (ip) WHERE state='running';
CREATE UNIQUE INDEX IF NOT EXISTS scans_one_queued_per_ip ON scans (ip) WHERE state='queued';
CREATE INDEX IF NOT EXISTS scan_port_changes_created ON scan_port_changes (created_at);
CREATE INDEX IF NOT EXISTS scan_port_changes_ip_created ON scan_port_changes (ip, created_at);
CREATE INDEX IF NOT EXISTS scan_snapshots_result ON scan_snapshots (ip, mode, result_hash);
CREATE INDEX IF NOT EXISTS scan_snapshots_ip_mode_id ON scan_snapshots (ip, mode, id);
CREATE INDEX IF NOT EXISTS scan_snapshot_ports_service ON scan_snapshot_ports (service, port);
CREATE INDEX IF NOT EXISTS scan_snapshot_scripts_snapshot ON scan_snapshot_scripts (snapshot_id, port_id);
CREATE INDEX IF NOT EXISTS scan_snapshot_script_nodes_parent ON scan_snapshot_script_nodes (script_id, parent_id, position);
CREATE INDEX IF NOT EXISTS operation_failures_operation_time ON operation_failures (operation, failed_at);
CREATE INDEX IF NOT EXISTS scheduled_report_runs_started ON scheduled_report_runs (started_at);
CREATE INDEX IF NOT EXISTS audit_events_occurred ON audit_events (occurred_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS audit_events_action_occurred ON audit_events (action, occurred_at DESC);
CREATE INDEX IF NOT EXISTS audit_events_resource_occurred ON audit_events (resource_type, occurred_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS monitored_services_discovered_target
  ON monitored_services (target, protocol, port) WHERE source='discovered';
CREATE UNIQUE INDEX IF NOT EXISTS monitored_services_manual_target
  ON monitored_services (type, target, COALESCE(port, 0)) WHERE source='manual';
CREATE INDEX IF NOT EXISTS monitored_services_source ON monitored_services (source, id);

PRAGMA user_version = 14;
