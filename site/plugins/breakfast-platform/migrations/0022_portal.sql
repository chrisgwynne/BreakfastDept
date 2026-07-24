-- Phase 3 — Client experience: portal foundation & client identity.
--
-- A separate trust boundary from staff. A portal identity is a client login tied
-- to a CRM contact (and optionally a company). Access is passwordless: the client
-- requests a single-use, short-lived magic link by email; consuming it mints an
-- opaque, server-side session. Raw magic-link and session tokens are NEVER stored
-- (only their sha256 hashes). Access grants scope exactly which projects an
-- identity may see; nothing is visible by default. Every consequential action is
-- an immutable portal event.

CREATE TABLE IF NOT EXISTS portal_identities (
    uuid          TEXT PRIMARY KEY,
    email         TEXT NOT NULL,
    email_key     TEXT NOT NULL,                     -- lowercased email for lookup/uniqueness
    display_name  TEXT NOT NULL DEFAULT '',
    contact_uuid  TEXT,
    company_uuid  TEXT,
    status        TEXT NOT NULL DEFAULT 'active',    -- active|suspended
    last_login_at TEXT,
    revision      INTEGER NOT NULL DEFAULT 1,
    created_by    TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_portal_identity_email ON portal_identities (email_key);
CREATE INDEX IF NOT EXISTS idx_portal_identity_contact ON portal_identities (contact_uuid);

-- Which projects (and, later, other entities) an identity may access. No grant =
-- no visibility. Server-enforced on every portal read.
CREATE TABLE IF NOT EXISTS portal_access_grants (
    uuid          TEXT PRIMARY KEY,
    identity_uuid TEXT NOT NULL REFERENCES portal_identities(uuid) ON DELETE CASCADE,
    entity_type   TEXT NOT NULL DEFAULT 'project',   -- project (extensible)
    entity_uuid   TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'viewer',    -- viewer|approver
    revoked       INTEGER NOT NULL DEFAULT 0,
    created_by    TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL,
    UNIQUE (identity_uuid, entity_type, entity_uuid)
);
CREATE INDEX IF NOT EXISTS idx_portal_grant_identity ON portal_access_grants (identity_uuid);
CREATE INDEX IF NOT EXISTS idx_portal_grant_entity ON portal_access_grants (entity_type, entity_uuid);

-- Single-use, expiring magic links. Only the sha256 hash of the raw token is
-- stored. Consuming sets used_at (one-shot).
CREATE TABLE IF NOT EXISTS portal_magic_links (
    uuid          TEXT PRIMARY KEY,
    identity_uuid TEXT NOT NULL REFERENCES portal_identities(uuid) ON DELETE CASCADE,
    token_hash    TEXT NOT NULL,
    email         TEXT NOT NULL DEFAULT '',
    ip_hash       TEXT NOT NULL DEFAULT '',
    expires_at    TEXT NOT NULL,
    used_at       TEXT,
    created_at    TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_portal_link_hash ON portal_magic_links (token_hash);
CREATE INDEX IF NOT EXISTS idx_portal_link_identity ON portal_magic_links (identity_uuid);

-- Server-side portal sessions. The cookie carries the raw token; only its
-- sha256 hash lives here. Sessions have an absolute expiry and a sliding
-- last-seen; revoking sets revoked = 1.
CREATE TABLE IF NOT EXISTS portal_sessions (
    uuid          TEXT PRIMARY KEY,
    identity_uuid TEXT NOT NULL REFERENCES portal_identities(uuid) ON DELETE CASCADE,
    token_hash    TEXT NOT NULL,
    ip_hash       TEXT NOT NULL DEFAULT '',
    user_agent    TEXT NOT NULL DEFAULT '',
    expires_at    TEXT NOT NULL,
    revoked       INTEGER NOT NULL DEFAULT 0,
    last_seen_at  TEXT NOT NULL,
    created_at    TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_portal_session_hash ON portal_sessions (token_hash);
CREATE INDEX IF NOT EXISTS idx_portal_session_identity ON portal_sessions (identity_uuid);

CREATE TABLE IF NOT EXISTS portal_events (
    uuid          TEXT PRIMARY KEY,
    identity_uuid TEXT NOT NULL,
    type          TEXT NOT NULL,
    detail        TEXT NOT NULL DEFAULT '',
    ip_hash       TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_portal_event_identity ON portal_events (identity_uuid, created_at);
