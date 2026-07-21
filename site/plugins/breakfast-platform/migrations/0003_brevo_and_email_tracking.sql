-- Breakfast platform — transactional email tracking, events and suppression.
-- Forward-only. Provider-agnostic (Brevo today; the columns fit any provider).

-- One row per outbound transactional message. The durable record of what the
-- application asked to send, its provider message id, and its delivery state.
CREATE TABLE outbound_messages (
    uuid              TEXT PRIMARY KEY,
    job_uuid          TEXT,                       -- the queue job that sends it
    provider          TEXT NOT NULL DEFAULT 'null',
    provider_message_id TEXT,                     -- e.g. Brevo messageId
    message_type      TEXT NOT NULL,              -- enquiry_internal | enquiry_ack | crm_reply | ...
    contact_uuid      TEXT REFERENCES contacts(uuid) ON DELETE SET NULL,
    company_uuid      TEXT REFERENCES companies(uuid) ON DELETE SET NULL,
    enquiry_uuid      TEXT REFERENCES enquiries(uuid) ON DELETE SET NULL,
    opportunity_uuid  TEXT REFERENCES opportunities(uuid) ON DELETE SET NULL,
    task_uuid         TEXT REFERENCES tasks(uuid) ON DELETE SET NULL,
    sender_email      TEXT NOT NULL,
    recipient_email   TEXT NOT NULL,              -- kept for operational diagnosis + retry
    recipient_hash    TEXT NOT NULL,              -- keyed hash for correlation without exposure
    subject           TEXT,                       -- snapshot (safe, no secrets)
    template_id       TEXT,
    params_snapshot   TEXT,                       -- JSON, redacted where necessary
    tags              TEXT,                       -- JSON array
    idempotency_key   TEXT,
    status            TEXT NOT NULL DEFAULT 'queued', -- queued|processing|accepted|delivered|temporary_failure|permanent_failure|soft_bounced|hard_bounced|blocked|invalid_recipient|spam_complaint|unsubscribed|suppressed
    attempts          INTEGER NOT NULL DEFAULT 0,
    last_response_code INTEGER,
    last_error        TEXT,
    queued_at         TEXT,
    sent_at           TEXT,
    delivered_at      TEXT,
    first_opened_at   TEXT,
    last_opened_at    TEXT,
    first_clicked_at  TEXT,
    last_clicked_at   TEXT,
    soft_bounced_at   TEXT,
    hard_bounced_at   TEXT,
    blocked_at        TEXT,
    spam_at           TEXT,
    unsubscribed_at   TEXT,
    failed_at         TEXT,
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);

CREATE INDEX idx_outbound_provider_msg ON outbound_messages(provider_message_id);
CREATE INDEX idx_outbound_contact ON outbound_messages(contact_uuid);
CREATE INDEX idx_outbound_enquiry ON outbound_messages(enquiry_uuid);
CREATE INDEX idx_outbound_opportunity ON outbound_messages(opportunity_uuid);
CREATE INDEX idx_outbound_status ON outbound_messages(status);
CREATE INDEX idx_outbound_type ON outbound_messages(message_type);
CREATE UNIQUE INDEX idx_outbound_idempotency ON outbound_messages(idempotency_key) WHERE idempotency_key IS NOT NULL;

-- Individual delivery/engagement events received from the provider webhook.
-- We retain only the minimum needed for diagnosis/audit/idempotency — NOT the
-- full raw payload.
CREATE TABLE email_events (
    uuid               TEXT PRIMARY KEY,
    outbound_uuid      TEXT REFERENCES outbound_messages(uuid) ON DELETE SET NULL,
    provider           TEXT NOT NULL,
    provider_event_id  TEXT,
    provider_message_id TEXT,
    event_type         TEXT NOT NULL,             -- canonical: sent|delivered|soft_bounce|hard_bounce|blocked|invalid_email|deferred|error|opened|clicked|spam|unsubscribed
    recipient_hash     TEXT NOT NULL,             -- keyed hash, never the raw address
    metadata           TEXT,                      -- JSON, safe fields only
    event_at           TEXT,
    received_at        TEXT NOT NULL,
    dedupe_fingerprint TEXT NOT NULL
);

CREATE INDEX idx_events_outbound ON email_events(outbound_uuid);
CREATE INDEX idx_events_provider_msg ON email_events(provider_message_id);
CREATE INDEX idx_events_type ON email_events(event_type);
CREATE INDEX idx_events_at ON email_events(event_at);
CREATE UNIQUE INDEX idx_events_dedupe ON email_events(dedupe_fingerprint);

-- Suppression list. Marketing and transactional suppression are modelled
-- separately: `scope` distinguishes them. A hard bounce / complaint / block
-- suppresses transactional too; an unsubscribe suppresses marketing only.
CREATE TABLE email_suppressions (
    uuid          TEXT PRIMARY KEY,
    email_hash    TEXT NOT NULL,                  -- keyed hash of the address
    email         TEXT,                           -- kept for operator diagnosis
    scope         TEXT NOT NULL,                  -- transactional | marketing
    reason        TEXT NOT NULL,                  -- hard_bounce | spam_complaint | blocked | invalid_email | unsubscribe | manual
    contact_uuid  TEXT REFERENCES contacts(uuid) ON DELETE SET NULL,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    UNIQUE (email_hash, scope)
);

CREATE INDEX idx_suppressions_hash ON email_suppressions(email_hash);
CREATE INDEX idx_suppressions_scope ON email_suppressions(scope);
