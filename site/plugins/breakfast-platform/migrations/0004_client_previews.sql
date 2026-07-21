-- Breakfast platform — Client Previews.
-- Static mock-up hosting served from a SEPARATE preview origin. Forward-only.
--
-- Files never live here; only metadata does. Uploaded bytes are validated and
-- stored on disk OUTSIDE the webroot (storage/client-previews/...). No raw IPs
-- are ever stored — access events use keyed hashes only.

-- One row per preview "project". public_slug is unique case-insensitively (it is
-- always stored lowercased). The row points at the CURRENT published version; the
-- full history lives in preview_versions.
CREATE TABLE client_previews (
    uuid                TEXT PRIMARY KEY,
    name                TEXT NOT NULL,                 -- internal name
    client_display_name TEXT,                          -- shown to the client (optional)
    contact_uuid        TEXT REFERENCES contacts(uuid) ON DELETE SET NULL,
    company_uuid        TEXT REFERENCES companies(uuid) ON DELETE SET NULL,
    opportunity_uuid    TEXT REFERENCES opportunities(uuid) ON DELETE SET NULL,

    public_slug         TEXT NOT NULL,                 -- lowercased [a-z0-9-]
    previous_slug       TEXT,                          -- last slug, blocks immediate reuse

    -- Lifecycle: draft|processing|active|disabled|expired|archived|failed_validation
    status              TEXT NOT NULL DEFAULT 'draft',
    -- Access mode when active: public|unlisted|password
    visibility          TEXT NOT NULL DEFAULT 'unlisted',
    -- Search indexability. Default 0 (noindex). Only an admin may flip this on.
    indexable           INTEGER NOT NULL DEFAULT 0,

    note                TEXT,
    entry_file          TEXT NOT NULL DEFAULT 'index.html',
    current_version_uuid TEXT,                         -- -> preview_versions.uuid
    version_count       INTEGER NOT NULL DEFAULT 0,
    bytes               INTEGER NOT NULL DEFAULT 0,    -- current version size
    file_count          INTEGER NOT NULL DEFAULT 0,

    password_protected  INTEGER NOT NULL DEFAULT 0,
    password_hash       TEXT,                          -- password_hash(), never plaintext

    analytics_enabled   INTEGER NOT NULL DEFAULT 1,
    title_override      TEXT,
    favicon_override    TEXT,

    created_by          TEXT,
    updated_by          TEXT,
    published_by        TEXT,

    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL,
    published_at        TEXT,
    expires_at          TEXT,                          -- neutral "expired" page after this
    last_viewed_at      TEXT,
    disabled_at         TEXT,
    archived_at         TEXT,

    view_count          INTEGER NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX idx_previews_slug ON client_previews(public_slug);
CREATE INDEX idx_previews_status ON client_previews(status);
CREATE INDEX idx_previews_contact ON client_previews(contact_uuid);
CREATE INDEX idx_previews_company ON client_previews(company_uuid);
CREATE INDEX idx_previews_opportunity ON client_previews(opportunity_uuid);
CREATE INDEX idx_previews_expires ON client_previews(expires_at);

-- Immutable, versioned uploads. Each successful upload becomes one version; the
-- current one is published, older ones superseded (kept up to a retention cap).
CREATE TABLE preview_versions (
    uuid              TEXT PRIMARY KEY,
    preview_uuid      TEXT NOT NULL REFERENCES client_previews(uuid) ON DELETE CASCADE,
    version_number    INTEGER NOT NULL,
    -- uploaded|validating|validation_failed|ready|published|superseded|archived
    state             TEXT NOT NULL DEFAULT 'uploaded',
    entry_file        TEXT NOT NULL DEFAULT 'index.html',
    source_type       TEXT NOT NULL DEFAULT 'zip',      -- zip|files
    original_filename TEXT,
    checksum          TEXT,                              -- sha256 of the source archive
    bytes             INTEGER NOT NULL DEFAULT 0,        -- extracted total
    file_count        INTEGER NOT NULL DEFAULT 0,
    storage_path      TEXT NOT NULL,                     -- version dir, relative to preview storage root
    manifest_path     TEXT,                              -- relative manifest.json
    validation_report TEXT,                              -- JSON {errors:[],warnings:[]}
    created_by        TEXT,
    created_at        TEXT NOT NULL,
    published_at      TEXT,
    superseded_at     TEXT,
    archived_at       TEXT
);

CREATE UNIQUE INDEX idx_versions_preview_number ON preview_versions(preview_uuid, version_number);
CREATE INDEX idx_versions_preview ON preview_versions(preview_uuid);
CREATE INDEX idx_versions_state ON preview_versions(state);

-- Privacy-conscious access log. NO raw IPs, NO raw user agents: only keyed
-- hashes (see Security\Hash), a coarse referer host, and the sanitised request
-- path. Pruned on a retention schedule (ANALYTICS_RETENTION_DAYS).
CREATE TABLE preview_access_events (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    preview_uuid  TEXT NOT NULL REFERENCES client_previews(uuid) ON DELETE CASCADE,
    version_uuid  TEXT,
    -- view|asset|password_success|password_failure|blocked|expired|disabled
    event_type    TEXT NOT NULL,
    path          TEXT,                                  -- sanitised request path
    status_code   INTEGER,
    ip_hash       TEXT,                                  -- keyed hash, never the raw IP
    ua_hash       TEXT,                                  -- keyed hash of the UA family, optional
    referer_host  TEXT,                                  -- host only, optional
    correlator    TEXT,                                  -- keyed session correlator
    occurred_at   TEXT NOT NULL
);

CREATE INDEX idx_access_preview ON preview_access_events(preview_uuid);
CREATE INDEX idx_access_type ON preview_access_events(event_type);
CREATE INDEX idx_access_at ON preview_access_events(occurred_at);

-- Optional preview lead-capture submissions. Always marked as test leads, never
-- emailed to the client, and bulk-deletable per preview. Off unless explicitly
-- enabled for a preview.
CREATE TABLE preview_form_submissions (
    uuid          TEXT PRIMARY KEY,
    preview_uuid  TEXT NOT NULL REFERENCES client_previews(uuid) ON DELETE CASCADE,
    name          TEXT,
    email         TEXT,
    message       TEXT,
    is_test_lead  INTEGER NOT NULL DEFAULT 1,            -- always a demo/test lead
    ip_hash       TEXT,
    created_at    TEXT NOT NULL
);

CREATE INDEX idx_preview_forms_preview ON preview_form_submissions(preview_uuid);
