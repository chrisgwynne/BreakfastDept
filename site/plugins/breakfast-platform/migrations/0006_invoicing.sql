-- Lightweight invoicing for a small web-design business.
-- All monetary amounts are stored as INTEGER minor units (pence) to avoid any
-- floating-point rounding; the application formats them for display. Nothing
-- here assumes VAT registration — VAT fields default to off/zero.

-- Single-row settings for the seller + invoice defaults (id is always 1).
CREATE TABLE IF NOT EXISTS invoice_settings (
    id                    INTEGER PRIMARY KEY CHECK (id = 1),
    company_legal_name    TEXT    NOT NULL DEFAULT '',
    company_address       TEXT    NOT NULL DEFAULT '',
    company_email         TEXT    NOT NULL DEFAULT '',
    payment_details       TEXT    NOT NULL DEFAULT '',
    vat_registered        INTEGER NOT NULL DEFAULT 0,
    vat_number            TEXT    NOT NULL DEFAULT '',
    default_vat_rate      INTEGER NOT NULL DEFAULT 0,   -- basis points (2000 = 20.00%)
    currency              TEXT    NOT NULL DEFAULT 'GBP',
    invoice_prefix        TEXT    NOT NULL DEFAULT 'INV',
    default_terms_days    INTEGER NOT NULL DEFAULT 14,
    default_notes         TEXT    NOT NULL DEFAULT '',
    updated_at            TEXT    NOT NULL DEFAULT ''
);

-- Per prefix+year monotonic numbering, allocated atomically on issue.
CREATE TABLE IF NOT EXISTS invoice_sequences (
    prefix     TEXT    NOT NULL,
    year       INTEGER NOT NULL,
    next_seq   INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (prefix, year)
);

CREATE TABLE IF NOT EXISTS invoices (
    uuid                TEXT PRIMARY KEY,
    number              TEXT,                 -- NULL until issued
    status              TEXT NOT NULL DEFAULT 'draft',  -- draft|issued|sent|viewed|partial|paid|overdue|void
    contact_uuid        TEXT,
    company_uuid        TEXT,
    project             TEXT NOT NULL DEFAULT '',
    currency            TEXT NOT NULL DEFAULT 'GBP',
    issue_date          TEXT,
    due_date            TEXT,
    bill_to_name        TEXT NOT NULL DEFAULT '',
    bill_to_address     TEXT NOT NULL DEFAULT '',
    bill_to_email       TEXT NOT NULL DEFAULT '',
    -- Snapshot of seller details at issue time so a reissued/edited settings row
    -- never rewrites history on an already-issued invoice.
    seller_name         TEXT NOT NULL DEFAULT '',
    seller_address      TEXT NOT NULL DEFAULT '',
    seller_vat_number   TEXT NOT NULL DEFAULT '',
    vat_registered      INTEGER NOT NULL DEFAULT 0,
    payment_details     TEXT NOT NULL DEFAULT '',
    notes               TEXT NOT NULL DEFAULT '',
    terms               TEXT NOT NULL DEFAULT '',
    subtotal            INTEGER NOT NULL DEFAULT 0,  -- pence, pre-tax, post-line-discount
    discount_total      INTEGER NOT NULL DEFAULT 0,  -- pence
    tax_total           INTEGER NOT NULL DEFAULT 0,  -- pence
    total               INTEGER NOT NULL DEFAULT 0,  -- pence
    amount_paid         INTEGER NOT NULL DEFAULT 0,  -- pence
    public_token        TEXT,                        -- for the signed client view/link
    issued_at           TEXT,
    sent_at             TEXT,
    viewed_at           TEXT,
    paid_at             TEXT,
    voided_at           TEXT,
    created_by          TEXT NOT NULL DEFAULT '',
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_invoices_status  ON invoices (status);
CREATE INDEX IF NOT EXISTS idx_invoices_contact ON invoices (contact_uuid);
CREATE INDEX IF NOT EXISTS idx_invoices_created ON invoices (created_at);
CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_number ON invoices (number) WHERE number IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_token  ON invoices (public_token) WHERE public_token IS NOT NULL;

CREATE TABLE IF NOT EXISTS invoice_items (
    uuid           TEXT PRIMARY KEY,
    invoice_uuid   TEXT NOT NULL,
    position       INTEGER NOT NULL DEFAULT 0,
    description    TEXT NOT NULL DEFAULT '',
    quantity       INTEGER NOT NULL DEFAULT 1000,  -- thousandths (1000 = 1.000) so partial qty is exact
    unit_price     INTEGER NOT NULL DEFAULT 0,     -- pence
    tax_rate       INTEGER NOT NULL DEFAULT 0,     -- basis points
    discount       INTEGER NOT NULL DEFAULT 0,     -- basis points off the line
    line_subtotal  INTEGER NOT NULL DEFAULT 0,     -- pence, post-discount pre-tax
    line_tax       INTEGER NOT NULL DEFAULT 0,     -- pence
    line_total     INTEGER NOT NULL DEFAULT 0      -- pence
);
CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items (invoice_uuid, position);

CREATE TABLE IF NOT EXISTS invoice_payments (
    uuid          TEXT PRIMARY KEY,
    invoice_uuid  TEXT NOT NULL,
    amount        INTEGER NOT NULL,   -- pence
    paid_on       TEXT NOT NULL,
    method        TEXT NOT NULL DEFAULT '',
    reference     TEXT NOT NULL DEFAULT '',
    note          TEXT NOT NULL DEFAULT '',
    created_by    TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_invoice_payments_invoice ON invoice_payments (invoice_uuid);

CREATE TABLE IF NOT EXISTS invoice_events (
    uuid          TEXT PRIMARY KEY,
    invoice_uuid  TEXT NOT NULL,
    type          TEXT NOT NULL,
    detail        TEXT NOT NULL DEFAULT '',
    actor         TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_invoice_events_invoice ON invoice_events (invoice_uuid, created_at);
