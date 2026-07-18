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

CREATE INDEX IF NOT EXISTS scheduled_report_runs_started ON scheduled_report_runs (started_at);
