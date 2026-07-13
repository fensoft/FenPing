ALTER TABLE scans ADD COLUMN network TEXT;
ALTER TABLE scans ADD COLUMN request_source TEXT NOT NULL DEFAULT 'legacy';
ALTER TABLE scans ADD COLUMN progress_percent INTEGER;
ALTER TABLE scans ADD COLUMN progress_phase TEXT;
ALTER TABLE scans ADD COLUMN progress_updated_at DATETIME;
ALTER TABLE scans ADD COLUMN cancel_requested_at DATETIME;

UPDATE scans
SET network = substr(
      ip,
      1,
      instr(ip, '.')
        + instr(substr(ip, instr(ip, '.') + 1), '.')
        + instr(substr(ip, instr(ip, '.') + instr(substr(ip, instr(ip, '.') + 1), '.') + 1), '.')
    ) || '0/24',
    progress_percent = CASE state WHEN 'complete' THEN 100 ELSE 0 END,
    progress_phase = CASE
      WHEN state IN ('queued', 'running', 'complete', 'failed', 'timeout', 'cancelled') THEN state
      ELSE 'queued'
    END,
    progress_updated_at = COALESCE(date_end, date_begin, queued_at, CURRENT_TIMESTAMP)
WHERE ip GLOB '[0-9]*.[0-9]*.[0-9]*.[0-9]*';

CREATE INDEX scans_network_state ON scans (network, state);
CREATE INDEX scans_network_source_started ON scans (network, request_source, date_begin);
