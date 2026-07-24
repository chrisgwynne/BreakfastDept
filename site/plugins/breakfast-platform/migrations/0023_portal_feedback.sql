-- Phase 3 — Client experience: portal feedback & approvals.
--
-- The client's voice, threaded back into delivery. From the portal a client can
-- leave feedback on a project or formally sign off (approve) its current state.
-- Every item is attributed to the portal identity, access-checked at write time,
-- surfaced to staff and mirrored onto the CRM timeline. Approvals are evidenced
-- (name + hashed IP + user agent) and immutable once recorded.

CREATE TABLE IF NOT EXISTS portal_feedback (
    uuid          TEXT PRIMARY KEY,
    project_uuid  TEXT NOT NULL REFERENCES projects(uuid) ON DELETE CASCADE,
    identity_uuid TEXT NOT NULL,
    kind          TEXT NOT NULL DEFAULT 'comment',   -- comment|approval
    body          TEXT NOT NULL DEFAULT '',
    author_name   TEXT NOT NULL DEFAULT '',
    status        TEXT NOT NULL DEFAULT 'open',       -- open|acknowledged|resolved
    -- Approval evidence (kind = approval):
    approved_label TEXT NOT NULL DEFAULT '',          -- what was signed off
    ip_hash       TEXT NOT NULL DEFAULT '',
    user_agent    TEXT NOT NULL DEFAULT '',
    handled_by    TEXT NOT NULL DEFAULT '',
    handled_at    TEXT,
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_portal_feedback_project ON portal_feedback (project_uuid, created_at);
CREATE INDEX IF NOT EXISTS idx_portal_feedback_identity ON portal_feedback (identity_uuid);
CREATE INDEX IF NOT EXISTS idx_portal_feedback_status ON portal_feedback (status);
