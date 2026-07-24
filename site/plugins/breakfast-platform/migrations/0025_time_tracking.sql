-- Phase 4 — Operations: time tracking.
--
-- Time logged against a project (and optionally a specific task). Entries are
-- either logged with an explicit duration or captured with a live start/stop
-- timer (at most one running timer per user). Billable time carries a rate in
-- pence/hour; once attached to an invoice an entry is locked (billed) and can no
-- longer be edited or deleted. Durations are integer seconds; money is pence.

CREATE TABLE IF NOT EXISTS time_entries (
    uuid             TEXT PRIMARY KEY,
    project_uuid     TEXT NOT NULL REFERENCES projects(uuid) ON DELETE CASCADE,
    task_uuid        TEXT REFERENCES project_tasks(uuid) ON DELETE SET NULL,
    author           TEXT NOT NULL DEFAULT '',
    description      TEXT NOT NULL DEFAULT '',
    activity         TEXT NOT NULL DEFAULT 'general',   -- general|design|build|meeting|admin|support
    started_at       TEXT,                              -- when the work happened / timer began
    duration_seconds INTEGER NOT NULL DEFAULT 0,
    billable         INTEGER NOT NULL DEFAULT 1,
    rate_pence       INTEGER NOT NULL DEFAULT 0,        -- per hour, pence
    running          INTEGER NOT NULL DEFAULT 0,        -- 1 while a live timer is counting
    billed           INTEGER NOT NULL DEFAULT 0,        -- locked once invoiced
    invoice_uuid     TEXT,
    revision         INTEGER NOT NULL DEFAULT 1,
    created_at       TEXT NOT NULL,
    updated_at       TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_time_project ON time_entries (project_uuid, started_at);
CREATE INDEX IF NOT EXISTS idx_time_task ON time_entries (task_uuid);
CREATE INDEX IF NOT EXISTS idx_time_author ON time_entries (author);
CREATE INDEX IF NOT EXISTS idx_time_running ON time_entries (author, running);
