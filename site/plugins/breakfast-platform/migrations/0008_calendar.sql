-- Calendar events for the standalone admin.
--
-- A single events table that links to the same CRM entities as everything else
-- (contact/company/opportunity/enquiry/preview/invoice) so scheduled work shows
-- up on the client timeline and the dashboard. Recurrence is stored as a rule on
-- the master row and expanded on read within the queried range; cancelled
-- occurrences of a series are recorded in `exceptions` (a JSON list of dates).
-- All datetimes are stored as ISO‑8601 strings.

CREATE TABLE IF NOT EXISTS calendar_events (
    uuid                TEXT PRIMARY KEY,
    title               TEXT NOT NULL,
    description         TEXT NOT NULL DEFAULT '',
    starts_at           TEXT NOT NULL,
    ends_at             TEXT NOT NULL,
    all_day             INTEGER NOT NULL DEFAULT 0,
    timezone            TEXT NOT NULL DEFAULT 'Europe/London',
    location            TEXT NOT NULL DEFAULT '',
    meeting_link        TEXT NOT NULL DEFAULT '',
    event_type          TEXT NOT NULL DEFAULT 'meeting',
    status              TEXT NOT NULL DEFAULT 'confirmed',

    -- CRM relationships (all optional, all ON DELETE SET NULL where FK-backed).
    contact_uuid        TEXT REFERENCES contacts(uuid) ON DELETE SET NULL,
    company_uuid        TEXT REFERENCES companies(uuid) ON DELETE SET NULL,
    opportunity_uuid    TEXT REFERENCES opportunities(uuid) ON DELETE SET NULL,
    enquiry_uuid        TEXT,
    preview_uuid        TEXT,
    invoice_uuid        TEXT,

    -- Recurrence rule (empty = single event).
    recurrence          TEXT NOT NULL DEFAULT '',
    recurrence_interval INTEGER NOT NULL DEFAULT 1,
    recurrence_until    TEXT NOT NULL DEFAULT '',
    recurrence_count    INTEGER NOT NULL DEFAULT 0,
    exceptions          TEXT NOT NULL DEFAULT '',

    -- Reminders.
    reminder_minutes    INTEGER NOT NULL DEFAULT 0,
    reminder_sent       INTEGER NOT NULL DEFAULT 0,

    assigned_to         TEXT NOT NULL DEFAULT '',
    created_by          TEXT NOT NULL DEFAULT '',
    source              TEXT NOT NULL DEFAULT 'manual',
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_calendar_starts      ON calendar_events(starts_at);
CREATE INDEX IF NOT EXISTS idx_calendar_contact     ON calendar_events(contact_uuid);
CREATE INDEX IF NOT EXISTS idx_calendar_company     ON calendar_events(company_uuid);
CREATE INDEX IF NOT EXISTS idx_calendar_opportunity ON calendar_events(opportunity_uuid);
CREATE INDEX IF NOT EXISTS idx_calendar_reminder    ON calendar_events(reminder_minutes, reminder_sent);
