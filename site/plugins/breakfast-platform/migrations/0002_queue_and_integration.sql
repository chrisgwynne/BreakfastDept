-- Breakfast platform — queue, outbox, webhooks, integration audit, rate limiting.

-- Durable job queue / outbox. External side effects (email, webhooks) go here so
-- form storage never depends on an external service being available.
CREATE TABLE jobs (
    uuid          TEXT PRIMARY KEY,
    type          TEXT NOT NULL,             -- mail.enquiry_internal | mail.ack | webhook.deliver | ...
    payload       TEXT NOT NULL,             -- JSON
    status        TEXT NOT NULL DEFAULT 'pending', -- pending | reserved | done | failed
    attempts      INTEGER NOT NULL DEFAULT 0,
    max_attempts  INTEGER NOT NULL DEFAULT 5,
    available_at  TEXT NOT NULL,             -- earliest run time (ISO-8601)
    reserved_at   TEXT,                      -- lease start
    lease_until   TEXT,                      -- lease expiry, for crash recovery
    last_error    TEXT,
    dedupe_key    TEXT,                      -- optional idempotency key
    created_at    TEXT NOT NULL,
    completed_at  TEXT
);

CREATE INDEX idx_jobs_status ON jobs(status, available_at);
CREATE UNIQUE INDEX idx_jobs_dedupe ON jobs(dedupe_key) WHERE dedupe_key IS NOT NULL;

-- Registered outbound webhook endpoints (non-secret config; signing secret is env).
CREATE TABLE webhook_endpoints (
    uuid              TEXT PRIMARY KEY,
    label             TEXT NOT NULL,
    url               TEXT NOT NULL,
    events            TEXT NOT NULL,          -- JSON array of event types ("*" for all)
    active            INTEGER NOT NULL DEFAULT 1,
    consecutive_fails INTEGER NOT NULL DEFAULT 0,
    disabled_reason   TEXT,
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);

-- Delivery attempts log for each webhook event/endpoint pair.
CREATE TABLE webhook_deliveries (
    uuid           TEXT PRIMARY KEY,
    endpoint_uuid  TEXT NOT NULL REFERENCES webhook_endpoints(uuid) ON DELETE CASCADE,
    event_uuid     TEXT NOT NULL,            -- stable event id for idempotency
    event_type     TEXT NOT NULL,
    schema_version TEXT NOT NULL,
    payload        TEXT NOT NULL,            -- JSON
    status         TEXT NOT NULL DEFAULT 'pending', -- pending | delivered | failed
    attempts       INTEGER NOT NULL DEFAULT 0,
    response_code  INTEGER,
    last_error     TEXT,
    created_at     TEXT NOT NULL,
    delivered_at   TEXT
);

CREATE INDEX idx_deliveries_endpoint ON webhook_deliveries(endpoint_uuid);
CREATE INDEX idx_deliveries_status ON webhook_deliveries(status);
CREATE INDEX idx_deliveries_event ON webhook_deliveries(event_uuid);

-- Immutable Hermes integration audit trail. Never stores secrets or headers.
CREATE TABLE hermes_audit (
    uuid          TEXT PRIMARY KEY,
    credential_id TEXT NOT NULL,
    scope         TEXT,                       -- the scope used for this call
    method        TEXT NOT NULL,
    endpoint      TEXT NOT NULL,
    target_type   TEXT,
    target_uuid   TEXT,
    request_id    TEXT NOT NULL,
    result        TEXT NOT NULL,              -- ok | denied | error | not_found
    status_code   INTEGER,
    metadata      TEXT,                       -- JSON, safe fields only
    created_at    TEXT NOT NULL
);

CREATE INDEX idx_hermes_audit_cred ON hermes_audit(credential_id);
CREATE INDEX idx_hermes_audit_created ON hermes_audit(created_at);

-- Replay-protection nonce store for Hermes HMAC requests. Short retention.
CREATE TABLE hermes_nonces (
    nonce       TEXT PRIMARY KEY,
    seen_at     TEXT NOT NULL,
    expires_at  TEXT NOT NULL
);

CREATE INDEX idx_hermes_nonces_expiry ON hermes_nonces(expires_at);

-- Short-lived rate-limit buckets (form submissions, API auth). Keyed hashes only,
-- never raw IPs. Rows are pruned by the sweeper past their window.
CREATE TABLE rate_limits (
    bucket      TEXT NOT NULL,               -- e.g. form:contact:<ip_hash>
    window_key  TEXT NOT NULL,               -- coarse time window id
    hits        INTEGER NOT NULL DEFAULT 0,
    expires_at  TEXT NOT NULL,
    PRIMARY KEY (bucket, window_key)
);

CREATE INDEX idx_rate_limits_expiry ON rate_limits(expires_at);

-- Duplicate-submission fingerprints (content hash + short window).
CREATE TABLE form_fingerprints (
    fingerprint TEXT PRIMARY KEY,
    form_type   TEXT NOT NULL,
    created_at  TEXT NOT NULL,
    expires_at  TEXT NOT NULL
);

CREATE INDEX idx_fingerprints_expiry ON form_fingerprints(expires_at);

-- Metadata for securely stored uploads (files themselves live outside webroot).
CREATE TABLE uploads (
    uuid          TEXT PRIMARY KEY,
    enquiry_uuid  TEXT REFERENCES enquiries(uuid) ON DELETE SET NULL,
    original_name TEXT NOT NULL,
    stored_name   TEXT NOT NULL,             -- generated identifier on disk
    mime          TEXT NOT NULL,
    size_bytes    INTEGER NOT NULL,
    sha256        TEXT NOT NULL,
    scan_status   TEXT NOT NULL DEFAULT 'pending', -- pending | clean | infected | skipped
    created_at    TEXT NOT NULL
);

CREATE INDEX idx_uploads_enquiry ON uploads(enquiry_uuid);
