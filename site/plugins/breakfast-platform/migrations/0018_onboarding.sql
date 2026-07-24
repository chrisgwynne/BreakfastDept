-- Phase 2B — Delivery: client onboarding (versioned form builder + instances).
--
-- Templates are versioned; publishing freezes sections/questions/options/mapping
-- rules. An instance references an exact frozen version, so a later template
-- edit never mutates in-flight client answers. Answers save incrementally
-- (durable, server-authoritative), submissions are snapshotted immutably, and
-- selected answers PROPOSE field updates that require review before overwriting
-- trusted CRM/project data.

CREATE TABLE IF NOT EXISTS onboarding_templates (
    uuid            TEXT PRIMARY KEY,
    slug            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    category        TEXT NOT NULL DEFAULT '',
    project_type    TEXT NOT NULL DEFAULT '',
    current_version INTEGER NOT NULL DEFAULT 0,
    builtin         INTEGER NOT NULL DEFAULT 0,
    archived        INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS onboarding_template_versions (
    uuid          TEXT PRIMARY KEY,
    template_uuid TEXT NOT NULL REFERENCES onboarding_templates(uuid) ON DELETE CASCADE,
    version       INTEGER NOT NULL,
    status        TEXT NOT NULL DEFAULT 'draft',   -- draft|published
    notes         TEXT NOT NULL DEFAULT '',
    published_at  TEXT,
    created_by    TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL,
    UNIQUE (template_uuid, version)
);

CREATE TABLE IF NOT EXISTS onboarding_sections (
    uuid          TEXT PRIMARY KEY,
    version_uuid  TEXT NOT NULL REFERENCES onboarding_template_versions(uuid) ON DELETE CASCADE,
    skey          TEXT NOT NULL,
    title         TEXT NOT NULL,
    description   TEXT NOT NULL DEFAULT '',
    condition     TEXT NOT NULL DEFAULT '',   -- JSON condition group ('' = always)
    sort_order    INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_onb_sections_version ON onboarding_sections (version_uuid, sort_order);

CREATE TABLE IF NOT EXISTS onboarding_questions (
    uuid          TEXT PRIMARY KEY,
    version_uuid  TEXT NOT NULL REFERENCES onboarding_template_versions(uuid) ON DELETE CASCADE,
    section_key   TEXT NOT NULL DEFAULT '',
    qkey          TEXT NOT NULL,
    type          TEXT NOT NULL,   -- short_text|long_text|email|phone|url|number|currency|date|single_choice|multi_choice|yes_no|address|file|image|info|heading
    label         TEXT NOT NULL,
    help          TEXT NOT NULL DEFAULT '',
    placeholder   TEXT NOT NULL DEFAULT '',
    required      INTEGER NOT NULL DEFAULT 0,
    internal_only INTEGER NOT NULL DEFAULT 0,
    client_visible INTEGER NOT NULL DEFAULT 1,
    config        TEXT NOT NULL DEFAULT '',   -- JSON: min/max, allowed_types, etc.
    condition     TEXT NOT NULL DEFAULT '',   -- JSON condition group
    sort_order    INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_onb_questions_version ON onboarding_questions (version_uuid, sort_order);

CREATE TABLE IF NOT EXISTS onboarding_question_options (
    uuid          TEXT PRIMARY KEY,
    question_uuid TEXT NOT NULL REFERENCES onboarding_questions(uuid) ON DELETE CASCADE,
    value         TEXT NOT NULL,
    label         TEXT NOT NULL,
    sort_order    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_onb_options_question ON onboarding_question_options (question_uuid, sort_order);

CREATE TABLE IF NOT EXISTS onboarding_field_mappings (
    uuid          TEXT PRIMARY KEY,
    version_uuid  TEXT NOT NULL REFERENCES onboarding_template_versions(uuid) ON DELETE CASCADE,
    question_key  TEXT NOT NULL,
    target        TEXT NOT NULL,   -- e.g. contact.phone, company.website, project.scope, task, tag, note
    mode          TEXT NOT NULL DEFAULT 'direct', -- direct|normalised|combined|tag|task|note
    config        TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_onb_mappings_version ON onboarding_field_mappings (version_uuid);

CREATE TABLE IF NOT EXISTS onboarding_instances (
    uuid            TEXT PRIMARY KEY,
    template_uuid   TEXT NOT NULL,
    version         INTEGER NOT NULL,
    version_uuid    TEXT NOT NULL,
    project_uuid    TEXT REFERENCES projects(uuid) ON DELETE CASCADE,
    company_uuid    TEXT,
    contact_uuid    TEXT,
    opportunity_uuid TEXT,
    proposal_uuid   TEXT,
    contract_uuid   TEXT,
    status          TEXT NOT NULL DEFAULT 'draft', -- draft|ready|invited|viewed|in_progress|submitted|under_review|needs_clarification|completed|expired|withdrawn|superseded|cancelled
    token_hash      TEXT NOT NULL DEFAULT '',
    expires_at      TEXT,
    invited_at      TEXT,
    viewed_at       TEXT,
    submitted_at    TEXT,
    completed_at    TEXT,
    submission_no   INTEGER NOT NULL DEFAULT 0,
    revision        INTEGER NOT NULL DEFAULT 1,
    created_by      TEXT NOT NULL DEFAULT '',
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_onb_instances_project ON onboarding_instances (project_uuid);
CREATE INDEX IF NOT EXISTS idx_onb_instances_token   ON onboarding_instances (token_hash);

CREATE TABLE IF NOT EXISTS onboarding_answers (
    uuid          TEXT PRIMARY KEY,
    instance_uuid TEXT NOT NULL REFERENCES onboarding_instances(uuid) ON DELETE CASCADE,
    question_key  TEXT NOT NULL,
    value         TEXT NOT NULL DEFAULT '',   -- scalar or JSON (multi_choice/address)
    file_uuid     TEXT,
    updated_at    TEXT NOT NULL,
    UNIQUE (instance_uuid, question_key)
);

CREATE TABLE IF NOT EXISTS onboarding_answer_versions (
    uuid          TEXT PRIMARY KEY,
    instance_uuid TEXT NOT NULL,
    submission_no INTEGER NOT NULL,
    snapshot      TEXT NOT NULL,   -- JSON of all answers at submission
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_onb_answer_versions ON onboarding_answer_versions (instance_uuid, submission_no);

CREATE TABLE IF NOT EXISTS onboarding_invitations (
    uuid          TEXT PRIMARY KEY,
    instance_uuid TEXT NOT NULL REFERENCES onboarding_instances(uuid) ON DELETE CASCADE,
    token_hash    TEXT NOT NULL,
    email         TEXT NOT NULL DEFAULT '',
    expires_at    TEXT,
    revoked       INTEGER NOT NULL DEFAULT 0,
    sent_at       TEXT,
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_onb_invitations_instance ON onboarding_invitations (instance_uuid);

CREATE TABLE IF NOT EXISTS onboarding_events (
    uuid          TEXT PRIMARY KEY,
    instance_uuid TEXT NOT NULL,
    type          TEXT NOT NULL,
    detail        TEXT NOT NULL DEFAULT '',
    actor         TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_onb_events_instance ON onboarding_events (instance_uuid, created_at);

CREATE TABLE IF NOT EXISTS onboarding_mapping_reviews (
    uuid           TEXT PRIMARY KEY,
    instance_uuid  TEXT NOT NULL,
    question_key   TEXT NOT NULL,
    target         TEXT NOT NULL,
    existing_value TEXT NOT NULL DEFAULT '',
    submitted_value TEXT NOT NULL DEFAULT '',
    decision       TEXT NOT NULL DEFAULT 'pending', -- pending|accepted|rejected|merged
    reviewer       TEXT NOT NULL DEFAULT '',
    decided_at     TEXT,
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_onb_reviews_instance ON onboarding_mapping_reviews (instance_uuid, decision);
