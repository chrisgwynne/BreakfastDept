-- Phase 2 — Delivery: Milestones, delivery tasks and their dependency graphs.
--
-- These are project-owned delivery records, distinct from the lightweight CRM
-- `tasks` table (0001). Real project progress is calculated from these, never a
-- typed-in percentage. Dependencies form a DAG; circular links are rejected in
-- the service layer.

CREATE TABLE IF NOT EXISTS milestones (
    uuid             TEXT PRIMARY KEY,
    project_uuid     TEXT NOT NULL REFERENCES projects(uuid) ON DELETE CASCADE,
    title            TEXT NOT NULL,
    description      TEXT NOT NULL DEFAULT '',
    owner            TEXT NOT NULL DEFAULT '',
    due_date         TEXT,
    completed_date   TEXT,
    status           TEXT NOT NULL DEFAULT 'not_started', -- not_started|active|awaiting_client|blocked|completed|cancelled
    priority         TEXT NOT NULL DEFAULT 'normal',
    client_visible   INTEGER NOT NULL DEFAULT 1,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    progress_method  TEXT NOT NULL DEFAULT 'tasks',       -- tasks|manual
    manual_progress  INTEGER NOT NULL DEFAULT 0,          -- 0-100 when method=manual
    linked_invoice   TEXT,
    linked_preview   TEXT,
    revision         INTEGER NOT NULL DEFAULT 1,
    created_at       TEXT NOT NULL,
    updated_at       TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_milestones_project ON milestones (project_uuid, sort_order);

CREATE TABLE IF NOT EXISTS milestone_dependencies (
    uuid           TEXT PRIMARY KEY,
    project_uuid   TEXT NOT NULL,
    milestone_uuid TEXT NOT NULL,   -- the dependent
    blocked_by     TEXT NOT NULL,   -- the blocker (milestone uuid)
    created_at     TEXT NOT NULL,
    UNIQUE (milestone_uuid, blocked_by)
);
CREATE INDEX IF NOT EXISTS idx_milestone_deps_ms ON milestone_dependencies (milestone_uuid);

CREATE TABLE IF NOT EXISTS project_tasks (
    uuid            TEXT PRIMARY KEY,
    project_uuid    TEXT NOT NULL REFERENCES projects(uuid) ON DELETE CASCADE,
    milestone_uuid  TEXT REFERENCES milestones(uuid) ON DELETE SET NULL,
    title           TEXT NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    owner           TEXT NOT NULL DEFAULT '',
    assignees       TEXT NOT NULL DEFAULT '[]',           -- JSON array of emails
    status          TEXT NOT NULL DEFAULT 'backlog',      -- backlog|ready|in_progress|awaiting_client|blocked|review|completed|cancelled
    priority        TEXT NOT NULL DEFAULT 'normal',
    start_date      TEXT,
    due_date        TEXT,
    completed_date  TEXT,
    estimate_seconds INTEGER NOT NULL DEFAULT 0,
    tracked_seconds  INTEGER NOT NULL DEFAULT 0,
    billable        INTEGER NOT NULL DEFAULT 1,
    client_visible  INTEGER NOT NULL DEFAULT 0,
    labels          TEXT NOT NULL DEFAULT '[]',
    source          TEXT NOT NULL DEFAULT 'manual',
    source_ref      TEXT NOT NULL DEFAULT '',
    sort_order      INTEGER NOT NULL DEFAULT 0,
    archived        INTEGER NOT NULL DEFAULT 0,
    revision        INTEGER NOT NULL DEFAULT 1,
    created_by      TEXT NOT NULL DEFAULT '',
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ptasks_project   ON project_tasks (project_uuid, sort_order);
CREATE INDEX IF NOT EXISTS idx_ptasks_milestone ON project_tasks (milestone_uuid);
CREATE INDEX IF NOT EXISTS idx_ptasks_status    ON project_tasks (status);

CREATE TABLE IF NOT EXISTS task_dependencies (
    uuid         TEXT PRIMARY KEY,
    project_uuid TEXT NOT NULL,
    task_uuid    TEXT NOT NULL,   -- dependent
    blocked_by   TEXT NOT NULL,   -- blocker: 'task:<uuid>' or 'milestone:<uuid>'
    created_at   TEXT NOT NULL,
    UNIQUE (task_uuid, blocked_by)
);
CREATE INDEX IF NOT EXISTS idx_task_deps_task ON task_dependencies (task_uuid);

CREATE TABLE IF NOT EXISTS task_checklist_items (
    uuid        TEXT PRIMARY KEY,
    task_uuid   TEXT NOT NULL REFERENCES project_tasks(uuid) ON DELETE CASCADE,
    label       TEXT NOT NULL,
    done        INTEGER NOT NULL DEFAULT 0,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_checklist_task ON task_checklist_items (task_uuid, sort_order);
