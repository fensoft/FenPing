CREATE TABLE IF NOT EXISTS monitored_services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source TEXT NOT NULL CHECK (source IN ('discovered', 'manual')),
  type TEXT NOT NULL CHECK (type IN ('discovered', 'https', 'ssh', 'proxy')),
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
    (source='manual' AND type IN ('https', 'ssh', 'proxy') AND name IS NOT NULL AND protocol IS NULL)
  )
);

CREATE UNIQUE INDEX IF NOT EXISTS monitored_services_discovered_target
  ON monitored_services (target, protocol, port) WHERE source='discovered';
CREATE UNIQUE INDEX IF NOT EXISTS monitored_services_manual_target
  ON monitored_services (type, target, COALESCE(port, 0)) WHERE source='manual';
CREATE INDEX IF NOT EXISTS monitored_services_source ON monitored_services (source, id);
