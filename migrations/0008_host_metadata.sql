ALTER TABLE ips ADD COLUMN notes TEXT;
ALTER TABLE ips ADD COLUMN location TEXT;
ALTER TABLE ips ADD COLUMN owner TEXT;
ALTER TABLE ips ADD COLUMN model TEXT;
ALTER TABLE ips ADD COLUMN icon TEXT;

CREATE TABLE tags (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT COLLATE NOCASE NOT NULL UNIQUE
);

CREATE TABLE host_tags (
  host_id INTEGER NOT NULL,
  tag_id INTEGER NOT NULL,
  PRIMARY KEY (host_id, tag_id),
  FOREIGN KEY (host_id) REFERENCES ips (id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
);

CREATE TABLE inventory_saved_filters (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT COLLATE NOCASE NOT NULL UNIQUE
);

CREATE TABLE inventory_saved_filter_tags (
  filter_id INTEGER NOT NULL,
  tag_id INTEGER NOT NULL,
  PRIMARY KEY (filter_id, tag_id),
  FOREIGN KEY (filter_id) REFERENCES inventory_saved_filters (id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
);

CREATE INDEX host_tags_tag_id ON host_tags (tag_id, host_id);
CREATE INDEX inventory_saved_filter_tags_tag_id ON inventory_saved_filter_tags (tag_id, filter_id);
