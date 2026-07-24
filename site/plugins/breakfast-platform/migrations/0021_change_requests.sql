-- Phase 2B — Delivery: change requests + scope control.
--
-- A formal, priced, versioned scope change bridging delivery and commerce. The
-- sent version is frozen (immutable snapshot + real PDF + hash + client token);
-- client approval is explicit + evidenced; applying an approved change to the
-- project is a controlled, idempotent action that never mutates the original
-- proposal or signed contract. Money is integer pence; tax/discount basis points.

CREATE TABLE IF NOT EXISTS change_requests (
    uuid              TEXT PRIMARY KEY,
    number            TEXT NOT NULL DEFAULT '',
    title             TEXT NOT NULL,
    project_uuid      TEXT NOT NULL REFERENCES projects(uuid) ON DELETE CASCADE,
    company_uuid      TEXT,
    contact_uuid      TEXT,
    requester         TEXT NOT NULL DEFAULT '',
    source            TEXT NOT NULL DEFAULT 'staff',   -- staff|client|feedback|onboarding|review|hermes
    linked_feedback   TEXT,
    linked_preview    TEXT,
    description       TEXT NOT NULL DEFAULT '',
    reason            TEXT NOT NULL DEFAULT '',
    scope_impact      TEXT NOT NULL DEFAULT '',
    exclusions        TEXT NOT NULL DEFAULT '',
    assumptions       TEXT NOT NULL DEFAULT '',
    time_impact       TEXT NOT NULL DEFAULT '',
    target_date_impact TEXT,                             -- new/adjusted project target date
    currency          TEXT NOT NULL DEFAULT 'GBP',
    discount_bps      INTEGER NOT NULL DEFAULT 0,        -- basis points
    tax_bps           INTEGER NOT NULL DEFAULT 0,
    deposit_pence     INTEGER NOT NULL DEFAULT 0,
    subtotal          INTEGER NOT NULL DEFAULT 0,        -- pence, server-computed
    tax_total         INTEGER NOT NULL DEFAULT 0,
    total             INTEGER NOT NULL DEFAULT 0,
    internal_notes    TEXT NOT NULL DEFAULT '',
    client_notes      TEXT NOT NULL DEFAULT '',
    owner             TEXT NOT NULL DEFAULT '',
    status            TEXT NOT NULL DEFAULT 'draft',
    -- Frozen-on-send:
    snapshot          TEXT NOT NULL DEFAULT '',          -- JSON immutable copy at send
    public_token      TEXT NOT NULL DEFAULT '',
    document_key      TEXT NOT NULL DEFAULT '',
    document_sha256   TEXT NOT NULL DEFAULT '',
    document_status   TEXT NOT NULL DEFAULT 'none',      -- none|pending|generated|failed
    applied           INTEGER NOT NULL DEFAULT 0,        -- idempotency guard for project impact
    expiry_date       TEXT,
    revision          INTEGER NOT NULL DEFAULT 1,
    created_by        TEXT NOT NULL DEFAULT '',
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL,
    sent_at           TEXT,
    decided_at        TEXT
);
CREATE INDEX IF NOT EXISTS idx_cr_project ON change_requests (project_uuid);
CREATE INDEX IF NOT EXISTS idx_cr_status ON change_requests (status);
CREATE INDEX IF NOT EXISTS idx_cr_token ON change_requests (public_token);

CREATE TABLE IF NOT EXISTS change_request_items (
    uuid            TEXT PRIMARY KEY,
    change_request_uuid TEXT NOT NULL REFERENCES change_requests(uuid) ON DELETE CASCADE,
    position        INTEGER NOT NULL DEFAULT 0,
    kind            TEXT NOT NULL DEFAULT 'fixed',       -- fixed|optional
    description     TEXT NOT NULL DEFAULT '',
    quantity        INTEGER NOT NULL DEFAULT 1000,       -- thousandths
    unit_price      INTEGER NOT NULL DEFAULT 0,          -- pence
    line_total      INTEGER NOT NULL DEFAULT 0,
    estimate_seconds INTEGER NOT NULL DEFAULT 0,
    milestone_hint  TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_cri_cr ON change_request_items (change_request_uuid, position);

CREATE TABLE IF NOT EXISTS change_request_versions (
    uuid            TEXT PRIMARY KEY,
    change_request_uuid TEXT NOT NULL,
    version         INTEGER NOT NULL,
    snapshot        TEXT NOT NULL,
    document_sha256 TEXT NOT NULL DEFAULT '',
    created_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_crv_cr ON change_request_versions (change_request_uuid, version);

CREATE TABLE IF NOT EXISTS change_request_acceptances (
    uuid            TEXT PRIMARY KEY,
    change_request_uuid TEXT NOT NULL,
    decision        TEXT NOT NULL,                       -- approved|rejected
    name            TEXT NOT NULL DEFAULT '',
    email           TEXT NOT NULL DEFAULT '',
    wording         TEXT NOT NULL DEFAULT '',
    document_hash   TEXT NOT NULL DEFAULT '',
    approved_total  INTEGER NOT NULL DEFAULT 0,
    ip_hash         TEXT NOT NULL DEFAULT '',
    user_agent      TEXT NOT NULL DEFAULT '',
    token_id        TEXT NOT NULL DEFAULT '',
    trace_id        TEXT NOT NULL DEFAULT '',
    comment         TEXT NOT NULL DEFAULT '',
    created_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cra_cr ON change_request_acceptances (change_request_uuid);

CREATE TABLE IF NOT EXISTS change_request_events (
    uuid        TEXT PRIMARY KEY,
    change_request_uuid TEXT NOT NULL,
    type        TEXT NOT NULL,
    detail      TEXT NOT NULL DEFAULT '',
    actor       TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cre_cr ON change_request_events (change_request_uuid, created_at);

CREATE TABLE IF NOT EXISTS change_request_task_links (
    uuid        TEXT PRIMARY KEY,
    change_request_uuid TEXT NOT NULL,
    task_uuid   TEXT NOT NULL,
    source_ref  TEXT NOT NULL,                           -- deterministic idempotency key
    created_at  TEXT NOT NULL,
    UNIQUE (source_ref)
);

CREATE TABLE IF NOT EXISTS change_request_sequences (
    prefix     TEXT    NOT NULL,
    year       INTEGER NOT NULL,
    next_seq   INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (prefix, year)
);
