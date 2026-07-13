ALTER TABLE scans ADD COLUMN queued_at DATETIME;

UPDATE scans
SET queued_at=COALESCE(date_begin, date_end, CURRENT_TIMESTAMP)
WHERE queued_at IS NULL;

CREATE TABLE operation_status (
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

CREATE TABLE operation_failures (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  operation TEXT NOT NULL,
  failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  error TEXT
);

CREATE INDEX scans_queued_at ON scans (state, queued_at);
CREATE INDEX operation_failures_operation_time ON operation_failures (operation, failed_at);
