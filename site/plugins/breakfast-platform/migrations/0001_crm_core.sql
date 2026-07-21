-- Breakfast CRM — core entities.
-- SQLite. All timestamps are ISO-8601 UTC strings. UUID primary keys.

CREATE TABLE companies (
    uuid        TEXT PRIMARY KEY,
    name        TEXT NOT NULL,
    website     TEXT,
    industry    TEXT,
    size_band   TEXT,
    address     TEXT,
    notes       TEXT,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

CREATE TABLE contacts (
    uuid                    TEXT PRIMARY KEY,
    first_name              TEXT,
    last_name               TEXT,
    display_name            TEXT NOT NULL,
    email                   TEXT,
    email_normalised        TEXT,
    phone                   TEXT,
    company_uuid            TEXT REFERENCES companies(uuid) ON DELETE SET NULL,
    role                    TEXT,
    website                 TEXT,
    location                TEXT,
    contact_type            TEXT NOT NULL DEFAULT 'lead',
    lead_source             TEXT,
    marketing_consent       TEXT NOT NULL DEFAULT 'unknown', -- granted | denied | unknown
    marketing_consent_at    TEXT,
    consent_source          TEXT,
    tags                    TEXT, -- JSON array
    owner                   TEXT,
    status                  TEXT NOT NULL DEFAULT 'active',
    notes                   TEXT,
    created_at              TEXT NOT NULL,
    updated_at              TEXT NOT NULL,
    last_contacted_at       TEXT,
    archived_at             TEXT,
    anonymised_at           TEXT
);

CREATE INDEX idx_contacts_email ON contacts(email_normalised);
CREATE INDEX idx_contacts_company ON contacts(company_uuid);
CREATE INDEX idx_contacts_status ON contacts(status);
CREATE INDEX idx_contacts_owner ON contacts(owner);

CREATE TABLE enquiries (
    uuid            TEXT PRIMARY KEY,
    reference       TEXT NOT NULL UNIQUE,       -- human-readable, e.g. ENQ-2026-0001
    form_type       TEXT NOT NULL,              -- contact | start-project
    contact_uuid    TEXT REFERENCES contacts(uuid) ON DELETE SET NULL,
    company_uuid    TEXT REFERENCES companies(uuid) ON DELETE SET NULL,
    payload         TEXT NOT NULL,              -- JSON of sanitised submitted fields
    summary         TEXT,                       -- human-readable one-line summary
    source_page     TEXT,
    referrer        TEXT,
    utm             TEXT,                        -- JSON of utm_* params
    landing_page    TEXT,
    ip_hash         TEXT,                        -- keyed hash, short retention
    status          TEXT NOT NULL DEFAULT 'new', -- new | reviewing | qualified | spam | closed
    owner           TEXT,
    spam_status     TEXT NOT NULL DEFAULT 'unknown', -- clean | suspected | confirmed | unknown
    risk_score      INTEGER NOT NULL DEFAULT 0,
    consent         TEXT,                        -- JSON: consent flags + timestamp
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);

CREATE INDEX idx_enquiries_status ON enquiries(status);
CREATE INDEX idx_enquiries_form ON enquiries(form_type);
CREATE INDEX idx_enquiries_contact ON enquiries(contact_uuid);
CREATE INDEX idx_enquiries_created ON enquiries(created_at);

CREATE TABLE opportunities (
    uuid                TEXT PRIMARY KEY,
    title               TEXT NOT NULL,
    contact_uuid        TEXT REFERENCES contacts(uuid) ON DELETE SET NULL,
    company_uuid        TEXT REFERENCES companies(uuid) ON DELETE SET NULL,
    enquiry_uuid        TEXT REFERENCES enquiries(uuid) ON DELETE SET NULL,
    stage               TEXT NOT NULL DEFAULT 'new',
    estimated_value     INTEGER NOT NULL DEFAULT 0, -- minor units (pence)
    currency            TEXT NOT NULL DEFAULT 'GBP',
    probability         INTEGER NOT NULL DEFAULT 0, -- 0-100
    services            TEXT,                       -- JSON array of service slugs
    expected_close_date TEXT,
    next_action         TEXT,
    next_action_date    TEXT,
    owner               TEXT,
    lost_reason         TEXT,
    won_at              TEXT,
    lost_at             TEXT,
    notes               TEXT,
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);

CREATE INDEX idx_opps_stage ON opportunities(stage);
CREATE INDEX idx_opps_contact ON opportunities(contact_uuid);
CREATE INDEX idx_opps_owner ON opportunities(owner);
CREATE INDEX idx_opps_next_action ON opportunities(next_action_date);

CREATE TABLE tasks (
    uuid                TEXT PRIMARY KEY,
    title               TEXT NOT NULL,
    contact_uuid        TEXT REFERENCES contacts(uuid) ON DELETE SET NULL,
    company_uuid        TEXT REFERENCES companies(uuid) ON DELETE SET NULL,
    opportunity_uuid    TEXT REFERENCES opportunities(uuid) ON DELETE SET NULL,
    assigned_to         TEXT,
    due_date            TEXT,
    priority            TEXT NOT NULL DEFAULT 'normal', -- low | normal | high
    status              TEXT NOT NULL DEFAULT 'open',   -- open | done | cancelled
    notes               TEXT,
    completed_at        TEXT,
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);

CREATE INDEX idx_tasks_status ON tasks(status);
CREATE INDEX idx_tasks_due ON tasks(due_date);
CREATE INDEX idx_tasks_assigned ON tasks(assigned_to);

-- Immutable activity log. Rows are append-only (enforced in the repository layer).
CREATE TABLE activities (
    uuid         TEXT PRIMARY KEY,
    entity_type  TEXT NOT NULL,   -- contact | company | enquiry | opportunity | task
    entity_uuid  TEXT NOT NULL,
    type         TEXT NOT NULL,   -- form.received | note.added | status.changed | ...
    actor_type   TEXT NOT NULL,   -- user | system | hermes
    actor_ref    TEXT,
    summary      TEXT NOT NULL,
    metadata     TEXT,            -- JSON
    created_at   TEXT NOT NULL
);

CREATE INDEX idx_activities_entity ON activities(entity_type, entity_uuid);
CREATE INDEX idx_activities_created ON activities(created_at);
CREATE INDEX idx_activities_actor ON activities(actor_type);

-- Sequence helper for human-readable references (enquiry numbers, etc.)
CREATE TABLE counters (
    name    TEXT PRIMARY KEY,
    value   INTEGER NOT NULL DEFAULT 0
);
