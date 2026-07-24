-- Phase 2 — Delivery: Projects, the core delivery workspace.
--
-- A project is the operational home for a paid client engagement. It links back
-- to the immutable commercial records (opportunity, proposal, contract) by
-- reference rather than copying their frozen terms, and carries its own mutable
-- delivery state, financial roll-up and a real status state machine. Money is
-- integer pence; time is integer seconds; everything is auditable.

CREATE TABLE IF NOT EXISTS projects (
    uuid                 TEXT PRIMARY KEY,
    number               TEXT NOT NULL DEFAULT '',
    name                 TEXT NOT NULL,
    company_uuid         TEXT REFERENCES companies(uuid) ON DELETE SET NULL,
    contact_uuid         TEXT REFERENCES contacts(uuid) ON DELETE SET NULL,
    opportunity_uuid     TEXT REFERENCES opportunities(uuid) ON DELETE SET NULL,
    proposal_uuid        TEXT,
    contract_uuid        TEXT,
    template_uuid        TEXT,
    template_version     INTEGER NOT NULL DEFAULT 0,
    owner                TEXT NOT NULL DEFAULT '',
    project_type         TEXT NOT NULL DEFAULT '',
    service_category     TEXT NOT NULL DEFAULT '',
    status               TEXT NOT NULL DEFAULT 'draft',
    priority             TEXT NOT NULL DEFAULT 'normal',   -- low|normal|high|urgent
    health               TEXT NOT NULL DEFAULT 'on_track',  -- on_track|at_risk|off_track
    start_date           TEXT,
    target_date          TEXT,
    actual_completion    TEXT,
    quoted_value         INTEGER NOT NULL DEFAULT 0,       -- pence
    approved_variations  INTEGER NOT NULL DEFAULT 0,       -- pence
    estimated_cost       INTEGER NOT NULL DEFAULT 0,       -- pence
    currency             TEXT NOT NULL DEFAULT 'GBP',
    description          TEXT NOT NULL DEFAULT '',
    internal_summary     TEXT NOT NULL DEFAULT '',
    client_summary       TEXT NOT NULL DEFAULT '',
    scope                TEXT NOT NULL DEFAULT '',
    exclusions           TEXT NOT NULL DEFAULT '',
    tags                 TEXT NOT NULL DEFAULT '',          -- JSON array
    -- Measurable awaiting-client / blocked accounting.
    blocked_reason       TEXT NOT NULL DEFAULT '',
    cancel_reason        TEXT NOT NULL DEFAULT '',
    awaiting_since       TEXT,
    awaiting_seconds     INTEGER NOT NULL DEFAULT 0,
    archived             INTEGER NOT NULL DEFAULT 0,
    revision             INTEGER NOT NULL DEFAULT 1,
    created_by           TEXT NOT NULL DEFAULT '',
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL,
    completed_at         TEXT,
    archived_at          TEXT
);
CREATE INDEX IF NOT EXISTS idx_projects_status   ON projects (status);
CREATE INDEX IF NOT EXISTS idx_projects_company  ON projects (company_uuid);
CREATE INDEX IF NOT EXISTS idx_projects_contact  ON projects (contact_uuid);
CREATE INDEX IF NOT EXISTS idx_projects_owner    ON projects (owner);
CREATE INDEX IF NOT EXISTS idx_projects_archived ON projects (archived);

CREATE TABLE IF NOT EXISTS project_sequences (
    prefix     TEXT    NOT NULL,
    year       INTEGER NOT NULL,
    next_seq   INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (prefix, year)
);

CREATE TABLE IF NOT EXISTS project_members (
    uuid          TEXT PRIMARY KEY,
    project_uuid  TEXT NOT NULL REFERENCES projects(uuid) ON DELETE CASCADE,
    user_email    TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'member',   -- lead|member|reviewer
    created_at    TEXT NOT NULL,
    UNIQUE (project_uuid, user_email)
);
CREATE INDEX IF NOT EXISTS idx_project_members_project ON project_members (project_uuid);

CREATE TABLE IF NOT EXISTS project_events (
    uuid          TEXT PRIMARY KEY,
    project_uuid  TEXT NOT NULL,
    type          TEXT NOT NULL,
    detail        TEXT NOT NULL DEFAULT '',
    actor         TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_project_events_project ON project_events (project_uuid, created_at);

-- Link invoices to a project so the project's invoiced/paid roll-up is a real
-- query, not a name match. Non-destructive: existing invoices keep NULL.
ALTER TABLE invoices ADD COLUMN project_uuid TEXT;
CREATE INDEX IF NOT EXISTS idx_invoices_project ON invoices (project_uuid);
