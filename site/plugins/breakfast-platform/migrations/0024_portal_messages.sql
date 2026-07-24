-- Phase 3 — Client experience: portal messaging.
--
-- A two-way message thread scoped to (project, client identity). Either side can
-- post; unread state is tracked per side so both the portal and the staff console
-- can surface "new message" counts. Every client message is mirrored onto the
-- CRM timeline. Messages are immutable once sent.

CREATE TABLE IF NOT EXISTS portal_messages (
    uuid           TEXT PRIMARY KEY,
    project_uuid   TEXT NOT NULL REFERENCES projects(uuid) ON DELETE CASCADE,
    identity_uuid  TEXT NOT NULL,
    sender         TEXT NOT NULL,                    -- client|staff
    author_name    TEXT NOT NULL DEFAULT '',
    body           TEXT NOT NULL,
    read_by_client INTEGER NOT NULL DEFAULT 0,
    read_by_staff  INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_portal_msg_thread ON portal_messages (project_uuid, identity_uuid, created_at);
CREATE INDEX IF NOT EXISTS idx_portal_msg_identity ON portal_messages (identity_uuid);
