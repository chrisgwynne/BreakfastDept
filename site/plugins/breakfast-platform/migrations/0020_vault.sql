-- Phase 2B — Delivery: the secure credential vault.
--
-- A DISTINCT store (not a styled file library or notes table). Sensitive field
-- values are field-level encrypted with libsodium (authenticated), tagged with a
-- key version for rotation; ciphertext lives here but the key never does. Reveal
-- and copy require step-up re-authentication and are audited. Secrets are
-- excluded from global search, Hermes and (later) the client portal.

CREATE TABLE IF NOT EXISTS vault_items (
    uuid           TEXT PRIMARY KEY,
    label          TEXT NOT NULL,
    item_type      TEXT NOT NULL DEFAULT 'other',   -- registrar|hosting|cms|dns|analytics|...
    company_uuid   TEXT,
    project_uuid   TEXT,
    url            TEXT NOT NULL DEFAULT '',
    account_id     TEXT NOT NULL DEFAULT '',
    username       TEXT NOT NULL DEFAULT '',
    owner          TEXT NOT NULL DEFAULT '',
    status         TEXT NOT NULL DEFAULT 'active',
    tags           TEXT NOT NULL DEFAULT '[]',
    notes          TEXT NOT NULL DEFAULT '',          -- NON-sensitive notes only
    expiry         TEXT,
    last_verified  TEXT,
    archived       INTEGER NOT NULL DEFAULT 0,
    revision       INTEGER NOT NULL DEFAULT 1,
    created_by     TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vault_company ON vault_items (company_uuid);
CREATE INDEX IF NOT EXISTS idx_vault_project ON vault_items (project_uuid);

CREATE TABLE IF NOT EXISTS vault_item_fields (
    uuid         TEXT PRIMARY KEY,
    item_uuid    TEXT NOT NULL REFERENCES vault_items(uuid) ON DELETE CASCADE,
    fkey         TEXT NOT NULL,                       -- password|api_key|token|recovery_code|private_key|db_password|secure_note
    label        TEXT NOT NULL DEFAULT '',
    ciphertext   TEXT NOT NULL,                       -- base64(nonce‖cipher); NEVER plaintext
    key_version  INTEGER NOT NULL DEFAULT 1,
    hint         TEXT NOT NULL DEFAULT '',            -- e.g. last 2 chars, safe to show masked
    sort_order   INTEGER NOT NULL DEFAULT 0,
    updated_at   TEXT NOT NULL,
    UNIQUE (item_uuid, fkey)
);
CREATE INDEX IF NOT EXISTS idx_vault_fields_item ON vault_item_fields (item_uuid);

CREATE TABLE IF NOT EXISTS vault_item_versions (
    uuid         TEXT PRIMARY KEY,
    item_uuid    TEXT NOT NULL,
    version      INTEGER NOT NULL,
    fkey         TEXT NOT NULL,
    ciphertext   TEXT NOT NULL,
    key_version  INTEGER NOT NULL DEFAULT 1,
    editor       TEXT NOT NULL DEFAULT '',
    reason       TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vault_versions_item ON vault_item_versions (item_uuid, version);

CREATE TABLE IF NOT EXISTS vault_item_links (
    uuid         TEXT PRIMARY KEY,
    item_uuid    TEXT NOT NULL REFERENCES vault_items(uuid) ON DELETE CASCADE,
    entity_type  TEXT NOT NULL,
    entity_uuid  TEXT NOT NULL,
    created_at   TEXT NOT NULL,
    UNIQUE (item_uuid, entity_type, entity_uuid)
);

CREATE TABLE IF NOT EXISTS vault_access_events (
    uuid        TEXT PRIMARY KEY,
    item_uuid   TEXT NOT NULL,
    fkey        TEXT NOT NULL DEFAULT '',
    action      TEXT NOT NULL,                        -- reveal|copy|edit_secret|create|archive|restore|rotate
    actor       TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vault_access_item ON vault_access_events (item_uuid, created_at);

CREATE TABLE IF NOT EXISTS vault_reauth_sessions (
    uuid        TEXT PRIMARY KEY,
    user_email  TEXT NOT NULL,
    expires_at  TEXT NOT NULL,
    revoked     INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vault_reauth_user ON vault_reauth_sessions (user_email, expires_at);

CREATE TABLE IF NOT EXISTS vault_key_versions (
    version     INTEGER PRIMARY KEY,
    created_at  TEXT NOT NULL,
    note        TEXT NOT NULL DEFAULT ''
);
