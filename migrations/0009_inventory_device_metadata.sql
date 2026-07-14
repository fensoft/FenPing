ALTER TABLE ips ADD COLUMN display_name TEXT;

CREATE TABLE inventory_device_metadata (
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

CREATE TABLE inventory_device_tags (
  device_id INTEGER NOT NULL,
  tag_id INTEGER NOT NULL,
  PRIMARY KEY (device_id, tag_id),
  FOREIGN KEY (device_id) REFERENCES inventory_device_metadata (id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
);

CREATE INDEX inventory_device_tags_tag_id ON inventory_device_tags (tag_id, device_id);
