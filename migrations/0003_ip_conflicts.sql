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

CREATE UNIQUE INDEX IF NOT EXISTS ip_conflicts_one_active_per_ip ON ip_conflicts (network, ip) WHERE resolved_at IS NULL;
CREATE INDEX IF NOT EXISTS ip_conflicts_detected ON ip_conflicts (detected_at);
CREATE INDEX IF NOT EXISTS ip_conflicts_resolved ON ip_conflicts (resolved_at);
CREATE INDEX IF NOT EXISTS ip_conflict_devices_mac ON ip_conflict_devices (mac COLLATE NOCASE);
