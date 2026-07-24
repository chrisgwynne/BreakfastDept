-- Phase 4 — Operations: retainers & recurring billing.
--
-- A retainer is a recurring agreement on a project: a fixed periodic fee plus an
-- included allowance of hours. Each period is billed in arrears — a draft invoice
-- is generated for the fee plus any time overage (billable time logged in the
-- window beyond the allowance, at the overage rate), and the covered time entries
-- are locked (billed) so they can never be billed twice. Generation is idempotent
-- per (retainer, period start). Money is pence; allowances are seconds.

CREATE TABLE IF NOT EXISTS retainers (
    uuid              TEXT PRIMARY KEY,
    project_uuid      TEXT NOT NULL REFERENCES projects(uuid) ON DELETE CASCADE,
    company_uuid      TEXT,
    contact_uuid      TEXT,
    title             TEXT NOT NULL DEFAULT 'Retainer',
    cadence           TEXT NOT NULL DEFAULT 'monthly',   -- monthly|quarterly
    fee_pence         INTEGER NOT NULL DEFAULT 0,         -- fixed periodic fee
    included_seconds  INTEGER NOT NULL DEFAULT 0,         -- hours allowance, seconds
    overage_rate_pence INTEGER NOT NULL DEFAULT 0,        -- per hour, pence
    currency          TEXT NOT NULL DEFAULT 'GBP',
    status            TEXT NOT NULL DEFAULT 'active',     -- active|paused|ended
    start_date        TEXT NOT NULL,                      -- first period start (Y-m-d)
    next_period_start TEXT NOT NULL,                      -- next period to bill (Y-m-d)
    revision          INTEGER NOT NULL DEFAULT 1,
    created_by        TEXT NOT NULL DEFAULT '',
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_retainers_project ON retainers (project_uuid);
CREATE INDEX IF NOT EXISTS idx_retainers_status ON retainers (status, next_period_start);

CREATE TABLE IF NOT EXISTS retainer_periods (
    uuid             TEXT PRIMARY KEY,
    retainer_uuid    TEXT NOT NULL REFERENCES retainers(uuid) ON DELETE CASCADE,
    period_start     TEXT NOT NULL,
    period_end       TEXT NOT NULL,
    included_seconds INTEGER NOT NULL DEFAULT 0,
    used_seconds     INTEGER NOT NULL DEFAULT 0,
    overage_pence    INTEGER NOT NULL DEFAULT 0,
    fee_pence        INTEGER NOT NULL DEFAULT 0,
    invoice_uuid     TEXT,
    created_at       TEXT NOT NULL,
    UNIQUE (retainer_uuid, period_start)
);
CREATE INDEX IF NOT EXISTS idx_retainer_periods ON retainer_periods (retainer_uuid, period_start);

CREATE TABLE IF NOT EXISTS retainer_events (
    uuid          TEXT PRIMARY KEY,
    retainer_uuid TEXT NOT NULL,
    type          TEXT NOT NULL,
    detail        TEXT NOT NULL DEFAULT '',
    actor         TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_retainer_events ON retainer_events (retainer_uuid, created_at);
