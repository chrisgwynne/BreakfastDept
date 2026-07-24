-- Phase 2 — Delivery: reusable, versioned project templates.
--
-- A template is a named delivery blueprint. Its milestones/tasks are versioned:
-- publishing freezes a version; creating a project from a template copies the
-- frozen version's rows into project-owned milestones/tasks with resolved dates,
-- and records template_uuid + template_version on the project. Later template
-- edits never mutate existing projects.

CREATE TABLE IF NOT EXISTS project_templates (
    uuid            TEXT PRIMARY KEY,
    slug            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    category        TEXT NOT NULL DEFAULT '',
    project_type    TEXT NOT NULL DEFAULT '',
    current_version INTEGER NOT NULL DEFAULT 0,   -- highest PUBLISHED version
    builtin         INTEGER NOT NULL DEFAULT 0,
    archived        INTEGER NOT NULL DEFAULT 0,
    client_instructions TEXT NOT NULL DEFAULT '',
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS project_template_versions (
    uuid          TEXT PRIMARY KEY,
    template_uuid TEXT NOT NULL REFERENCES project_templates(uuid) ON DELETE CASCADE,
    version       INTEGER NOT NULL,
    status        TEXT NOT NULL DEFAULT 'draft',   -- draft|published
    notes         TEXT NOT NULL DEFAULT '',
    published_at  TEXT,
    created_at    TEXT NOT NULL,
    UNIQUE (template_uuid, version)
);

CREATE TABLE IF NOT EXISTS project_template_milestones (
    uuid          TEXT PRIMARY KEY,
    version_uuid  TEXT NOT NULL REFERENCES project_template_versions(uuid) ON DELETE CASCADE,
    tkey          TEXT NOT NULL,                   -- stable key within the template
    title         TEXT NOT NULL,
    description   TEXT NOT NULL DEFAULT '',
    due_anchor    TEXT NOT NULL DEFAULT 'project_start', -- project_start|project_target
    due_offset    INTEGER NOT NULL DEFAULT 0,      -- business days from the anchor
    client_visible INTEGER NOT NULL DEFAULT 1,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    blocked_by    TEXT NOT NULL DEFAULT '',        -- comma-separated tkeys of blocking milestones
    is_approval   INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tmpl_ms_version ON project_template_milestones (version_uuid, sort_order);

CREATE TABLE IF NOT EXISTS project_template_tasks (
    uuid          TEXT PRIMARY KEY,
    version_uuid  TEXT NOT NULL REFERENCES project_template_versions(uuid) ON DELETE CASCADE,
    tkey          TEXT NOT NULL,
    milestone_key TEXT NOT NULL DEFAULT '',        -- tkey of owning milestone (optional)
    title         TEXT NOT NULL,
    description   TEXT NOT NULL DEFAULT '',
    due_anchor    TEXT NOT NULL DEFAULT 'project_start',
    due_offset    INTEGER NOT NULL DEFAULT 0,
    estimate_seconds INTEGER NOT NULL DEFAULT 0,
    client_visible INTEGER NOT NULL DEFAULT 0,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    blocked_by    TEXT NOT NULL DEFAULT '',        -- comma-separated 'task:<tkey>' or 'milestone:<tkey>'
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tmpl_task_version ON project_template_tasks (version_uuid, sort_order);
