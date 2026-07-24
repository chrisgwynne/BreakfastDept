-- Phase 4 — Operations: automation.
--
-- A scheduled rule engine. Each rule watches a trigger condition evaluated
-- against source-of-truth state (an overdue invoice, a stalled onboarding, an
-- approved change left unapplied) and, when a target first matches, performs its
-- action — creating a follow-up task on the target's project. Firing is
-- idempotent per (rule, target): a rule never acts twice on the same thing, so
-- the runner is safe to schedule.

CREATE TABLE IF NOT EXISTS automation_rules (
    uuid           TEXT PRIMARY KEY,
    name           TEXT NOT NULL,
    trigger_type   TEXT NOT NULL,                    -- invoice_overdue|onboarding_stalled|change_request_unapplied
    threshold_days INTEGER NOT NULL DEFAULT 0,       -- grace period before the rule fires
    enabled        INTEGER NOT NULL DEFAULT 1,
    last_run_at    TEXT,
    fire_count     INTEGER NOT NULL DEFAULT 0,
    revision       INTEGER NOT NULL DEFAULT 1,
    created_by     TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_automation_rules_enabled ON automation_rules (enabled, trigger_type);

CREATE TABLE IF NOT EXISTS automation_fires (
    uuid         TEXT PRIMARY KEY,
    rule_uuid    TEXT NOT NULL REFERENCES automation_rules(uuid) ON DELETE CASCADE,
    target_ref   TEXT NOT NULL,                       -- deterministic idempotency key
    project_uuid TEXT,
    task_uuid    TEXT,
    detail       TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL,
    UNIQUE (rule_uuid, target_ref)
);
CREATE INDEX IF NOT EXISTS idx_automation_fires_rule ON automation_fires (rule_uuid, created_at);
