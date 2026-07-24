-- Phase 2B — Delivery: the client/project file library (versioned + secured).
--
-- One shared file system serving companies, projects, onboarding, tasks and
-- more via a polymorphic link table. Stable file identity lives in client_files;
-- every replacement is a new immutable client_file_versions row (bytes are never
-- overwritten in place). Bytes are stored OUTSIDE the webroot under opaque keys;
-- downloads go through an authenticated/tokened route with an integrity check.

CREATE TABLE IF NOT EXISTS client_file_folders (
    uuid          TEXT PRIMARY KEY,
    name          TEXT NOT NULL,
    parent_uuid   TEXT,
    company_uuid  TEXT,
    project_uuid  TEXT,
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cff_project ON client_file_folders (project_uuid);

CREATE TABLE IF NOT EXISTS client_files (
    uuid            TEXT PRIMARY KEY,
    display_name    TEXT NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    category        TEXT NOT NULL DEFAULT 'general',
    tags            TEXT NOT NULL DEFAULT '[]',
    folder_uuid     TEXT,
    company_uuid    TEXT,
    project_uuid    TEXT,
    current_version INTEGER NOT NULL DEFAULT 1,
    source          TEXT NOT NULL DEFAULT 'upload',   -- upload|onboarding|invoice|contract|proposal|receipt|...
    immutable       INTEGER NOT NULL DEFAULT 0,        -- 1 = protected legal/financial document
    client_visible  INTEGER NOT NULL DEFAULT 0,
    archived        INTEGER NOT NULL DEFAULT 0,
    uploader        TEXT NOT NULL DEFAULT '',
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cf_project ON client_files (project_uuid);
CREATE INDEX IF NOT EXISTS idx_cf_company ON client_files (company_uuid);
CREATE INDEX IF NOT EXISTS idx_cf_archived ON client_files (archived);

CREATE TABLE IF NOT EXISTS client_file_versions (
    uuid           TEXT PRIMARY KEY,
    file_uuid      TEXT NOT NULL REFERENCES client_files(uuid) ON DELETE CASCADE,
    version        INTEGER NOT NULL,
    original_name  TEXT NOT NULL,
    extension      TEXT NOT NULL DEFAULT '',
    mime           TEXT NOT NULL DEFAULT '',
    detected_mime  TEXT NOT NULL DEFAULT '',
    byte_size      INTEGER NOT NULL DEFAULT 0,
    sha256         TEXT NOT NULL DEFAULT '',
    width          INTEGER,
    height         INTEGER,
    storage_key    TEXT NOT NULL,
    thumb_key      TEXT NOT NULL DEFAULT '',
    thumb_state    TEXT NOT NULL DEFAULT 'none',      -- none|processing|ready|failed|unsupported
    change_note    TEXT NOT NULL DEFAULT '',
    uploader       TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL,
    UNIQUE (file_uuid, version)
);
CREATE INDEX IF NOT EXISTS idx_cfv_file ON client_file_versions (file_uuid, version);
CREATE INDEX IF NOT EXISTS idx_cfv_hash ON client_file_versions (sha256);

CREATE TABLE IF NOT EXISTS client_file_links (
    uuid         TEXT PRIMARY KEY,
    file_uuid    TEXT NOT NULL REFERENCES client_files(uuid) ON DELETE CASCADE,
    entity_type  TEXT NOT NULL,   -- project|onboarding_answer|task|milestone|preview|feedback|change_request|proposal|contract|invoice|payment|note|company|contact
    entity_uuid  TEXT NOT NULL,
    created_by   TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL,
    UNIQUE (file_uuid, entity_type, entity_uuid)
);
CREATE INDEX IF NOT EXISTS idx_cfl_file ON client_file_links (file_uuid);
CREATE INDEX IF NOT EXISTS idx_cfl_entity ON client_file_links (entity_type, entity_uuid);

CREATE TABLE IF NOT EXISTS client_file_events (
    uuid        TEXT PRIMARY KEY,
    file_uuid   TEXT NOT NULL,
    type        TEXT NOT NULL,
    detail      TEXT NOT NULL DEFAULT '',
    actor       TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cfe_file ON client_file_events (file_uuid, created_at);

CREATE TABLE IF NOT EXISTS client_file_access_events (
    uuid        TEXT PRIMARY KEY,
    file_uuid   TEXT NOT NULL,
    version     INTEGER NOT NULL DEFAULT 0,
    actor       TEXT NOT NULL DEFAULT '',
    context     TEXT NOT NULL DEFAULT 'staff',   -- staff|onboarding|portal
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cfae_file ON client_file_access_events (file_uuid, created_at);
