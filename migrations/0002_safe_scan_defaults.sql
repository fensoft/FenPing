ALTER TABLE ips RENAME TO ips_schema_v1;

CREATE TABLE ips (
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
  scan_interval_hours INTEGER NOT NULL DEFAULT 24
);

INSERT INTO ips (
  id, name, mac, ip, important, repeater, web, router, dns,
  netboot_image_id, scan_profile, scan_interval_hours
)
SELECT
  id, name, mac, ip, important, repeater, web, router, dns,
  netboot_image_id, scan_profile, scan_interval_hours
FROM ips_schema_v1;

DROP TABLE ips_schema_v1;
CREATE INDEX ips_netboot_image_id ON ips (netboot_image_id);
