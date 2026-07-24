-- Stripe payments — verified, reconciled invoice payment.
--
-- Card details are NEVER stored. We keep provider references (payment intent /
-- checkout session ids), the server-computed amount, and a status mapped from
-- verified webhook events. Invoice paid/part-paid state is driven only by
-- verified payments reconciled against the invoice — never a redirect.

CREATE TABLE IF NOT EXISTS payments (
    uuid                 TEXT PRIMARY KEY,
    provider             TEXT NOT NULL DEFAULT 'stripe',
    provider_payment_id  TEXT NOT NULL DEFAULT '',   -- payment intent id
    provider_session_id  TEXT NOT NULL DEFAULT '',   -- checkout session id
    invoice_uuid         TEXT NOT NULL,
    contact_uuid         TEXT,
    amount               INTEGER NOT NULL DEFAULT 0,  -- pence, server-computed
    currency             TEXT NOT NULL DEFAULT 'GBP',
    status               TEXT NOT NULL DEFAULT 'pending', -- pending|requires_action|processing|succeeded|failed|cancelled|refunded|partially_refunded|disputed
    method_summary       TEXT NOT NULL DEFAULT '',
    fee                  INTEGER NOT NULL DEFAULT 0,  -- pence, when available
    amount_refunded      INTEGER NOT NULL DEFAULT 0,  -- pence
    failure_code         TEXT NOT NULL DEFAULT '',
    failure_message      TEXT NOT NULL DEFAULT '',
    idempotency_key      TEXT NOT NULL DEFAULT '',
    receipt_number       TEXT NOT NULL DEFAULT '',
    mode                 TEXT NOT NULL DEFAULT 'test', -- test|live
    created_at           TEXT NOT NULL,
    paid_at              TEXT,
    updated_at           TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments (invoice_uuid);
CREATE INDEX IF NOT EXISTS idx_payments_session ON payments (provider_session_id);
CREATE INDEX IF NOT EXISTS idx_payments_intent  ON payments (provider_payment_id);

CREATE TABLE IF NOT EXISTS payment_events (
    uuid           TEXT PRIMARY KEY,
    payment_uuid   TEXT NOT NULL DEFAULT '',
    invoice_uuid   TEXT NOT NULL DEFAULT '',
    type           TEXT NOT NULL,
    detail         TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_payment_events_payment ON payment_events (payment_uuid, created_at);

-- Verified provider webhook receipts, for idempotency + operations health.
CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id             TEXT PRIMARY KEY,          -- Stripe event id (evt_...)
    type           TEXT NOT NULL DEFAULT '',
    status         TEXT NOT NULL DEFAULT 'received', -- received|processed|ignored|error
    detail         TEXT NOT NULL DEFAULT '',
    received_at    TEXT NOT NULL,
    processed_at   TEXT
);
CREATE INDEX IF NOT EXISTS idx_payment_webhook_received ON payment_webhook_events (received_at);

CREATE TABLE IF NOT EXISTS payment_refunds (
    uuid                TEXT PRIMARY KEY,
    payment_uuid        TEXT NOT NULL,
    provider_refund_id  TEXT NOT NULL DEFAULT '',
    amount              INTEGER NOT NULL DEFAULT 0,  -- pence
    reason              TEXT NOT NULL DEFAULT '',
    status              TEXT NOT NULL DEFAULT 'pending', -- pending|succeeded|failed
    created_by          TEXT NOT NULL DEFAULT '',
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_payment_refunds_payment ON payment_refunds (payment_uuid);

CREATE TABLE IF NOT EXISTS payment_receipts (
    uuid            TEXT PRIMARY KEY,
    number          TEXT NOT NULL,
    payment_uuid    TEXT NOT NULL,
    invoice_uuid    TEXT NOT NULL,
    amount          INTEGER NOT NULL DEFAULT 0,
    currency        TEXT NOT NULL DEFAULT 'GBP',
    storage_key     TEXT NOT NULL DEFAULT '',
    sha256          TEXT NOT NULL DEFAULT '',
    created_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_payment_receipts_payment ON payment_receipts (payment_uuid);

CREATE TABLE IF NOT EXISTS payment_sequences (
    prefix     TEXT    NOT NULL,
    year       INTEGER NOT NULL,
    next_seq   INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (prefix, year)
);
