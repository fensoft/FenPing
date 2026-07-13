CREATE TABLE notification_delivery_settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  restart_enabled INTEGER NOT NULL DEFAULT 1 CHECK (restart_enabled IN (0, 1)),
  host_status_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (host_status_normal_enabled IN (0, 1)),
  host_status_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (host_status_important_enabled IN (0, 1)),
  service_changes_normal_enabled INTEGER NOT NULL DEFAULT 1 CHECK (service_changes_normal_enabled IN (0, 1)),
  service_changes_important_enabled INTEGER NOT NULL DEFAULT 1 CHECK (service_changes_important_enabled IN (0, 1)),
  ip_conflicts_enabled INTEGER NOT NULL DEFAULT 1 CHECK (ip_conflicts_enabled IN (0, 1))
);

INSERT INTO notification_delivery_settings (id) VALUES (1);
