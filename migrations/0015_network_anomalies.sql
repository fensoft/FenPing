CREATE TABLE IF NOT EXISTS notification_delivery_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  restart_enabled INTEGER NOT NULL DEFAULT 1,
  host_status_normal_enabled INTEGER NOT NULL DEFAULT 1,
  host_status_important_enabled INTEGER NOT NULL DEFAULT 1,
  service_changes_normal_enabled INTEGER NOT NULL DEFAULT 1,
  service_changes_important_enabled INTEGER NOT NULL DEFAULT 1,
  ip_conflicts_enabled INTEGER NOT NULL DEFAULT 1,
  telegram_chat_id TEXT,
  telegram_bot_fingerprint TEXT
);
INSERT OR IGNORE INTO notification_delivery_settings (id) VALUES (1);

ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_open_ports_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_open_ports_normal_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_open_ports_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_open_ports_important_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_unexpected_vendors_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_unexpected_vendors_normal_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_unexpected_vendors_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_unexpected_vendors_important_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_ip_changes_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_ip_changes_normal_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_ip_changes_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_ip_changes_important_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_duplicate_identities_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_duplicate_identities_normal_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_duplicate_identities_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_duplicate_identities_important_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_churn_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_churn_normal_enabled IN (0, 1));
ALTER TABLE notification_delivery_settings ADD COLUMN anomaly_churn_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (anomaly_churn_important_enabled IN (0, 1));

CREATE TABLE network_anomaly_monitors (
  network TEXT PRIMARY KEY,
  initialized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_observed_at DATETIME
);

CREATE TABLE network_identity_state (
  network TEXT NOT NULL,
  mac TEXT COLLATE NOCASE NOT NULL,
  current_ip TEXT,
  vendor_key TEXT,
  vendor TEXT,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  present INTEGER NOT NULL DEFAULT 1 CHECK (present IN (0, 1)),
  PRIMARY KEY (network, mac)
);

CREATE TABLE network_vendor_baselines (
  network TEXT NOT NULL,
  vendor_key TEXT NOT NULL,
  vendor TEXT NOT NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (network, vendor_key)
);

CREATE TABLE network_observation_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  network TEXT NOT NULL,
  observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE network_presence_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  network TEXT NOT NULL,
  mac TEXT COLLATE NOCASE NOT NULL,
  ip TEXT,
  change_type TEXT NOT NULL CHECK (change_type IN ('arrival', 'departure')),
  important INTEGER NOT NULL DEFAULT 0 CHECK (important IN (0, 1)),
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE network_anomaly_conditions (
  condition_key TEXT PRIMARY KEY,
  network TEXT NOT NULL,
  anomaly_type TEXT NOT NULL CHECK (anomaly_type IN ('duplicate_identity', 'churn')),
  subtype TEXT NOT NULL,
  identity_key TEXT,
  important INTEGER NOT NULL DEFAULT 0 CHECK (important IN (0, 1)),
  details_json TEXT NOT NULL DEFAULT '{}',
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notified INTEGER NOT NULL DEFAULT 1 CHECK (notified IN (0, 1))
);

CREATE TABLE network_anomaly_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  network TEXT NOT NULL,
  anomaly_type TEXT NOT NULL CHECK (anomaly_type IN ('unexpected_vendor', 'ip_change', 'duplicate_identity', 'churn')),
  subtype TEXT,
  event_type TEXT NOT NULL CHECK (event_type IN ('detected', 'resolved')),
  ip TEXT,
  previous_ip TEXT,
  mac TEXT COLLATE NOCASE,
  hostname TEXT,
  vendor TEXT,
  important INTEGER NOT NULL DEFAULT 0 CHECK (important IN (0, 1)),
  details_json TEXT NOT NULL DEFAULT '{}',
  dedupe_key TEXT NOT NULL UNIQUE,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX network_identity_state_present ON network_identity_state (network, present, last_seen_at);
CREATE INDEX network_observation_runs_network_time ON network_observation_runs (network, observed_at);
CREATE INDEX network_presence_events_network_time ON network_presence_events (network, occurred_at);
CREATE INDEX network_presence_events_mac_time ON network_presence_events (network, mac, occurred_at);
CREATE INDEX network_anomaly_events_time ON network_anomaly_events (occurred_at DESC, id DESC);
CREATE INDEX network_anomaly_events_network_time ON network_anomaly_events (network, occurred_at DESC);
CREATE INDEX network_anomaly_events_type_time ON network_anomaly_events (anomaly_type, occurred_at DESC);
