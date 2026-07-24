-- Immutable invoice snapshots + generated PDF documents.
--
-- When an invoice is issued it becomes a fixed financial record: we freeze a
-- complete JSON snapshot on the invoice row and generate a PDF from THAT
-- snapshot (never from live, mutable client/settings data). Each generated PDF
-- is recorded in invoice_documents with its hash, size and renderer so it can
-- be served, verified and versioned; the original issued document is preserved
-- when an authorised regeneration happens.

ALTER TABLE invoices ADD COLUMN snapshot TEXT NOT NULL DEFAULT '';
ALTER TABLE invoices ADD COLUMN document_status TEXT NOT NULL DEFAULT 'none';
-- none | pending | generated | failed

CREATE TABLE IF NOT EXISTS invoice_documents (
    uuid             TEXT PRIMARY KEY,
    invoice_uuid     TEXT NOT NULL,
    version          INTEGER NOT NULL DEFAULT 1,
    kind             TEXT NOT NULL DEFAULT 'issued',   -- draft | issued
    storage_key      TEXT NOT NULL,                    -- opaque path under the private store
    filename         TEXT NOT NULL,                    -- human download name, e.g. BREAKFAST-INV-2026-0042.pdf
    mime             TEXT NOT NULL DEFAULT 'application/pdf',
    byte_size        INTEGER NOT NULL DEFAULT 0,
    sha256           TEXT NOT NULL DEFAULT '',
    renderer         TEXT NOT NULL DEFAULT 'dompdf',
    renderer_version TEXT NOT NULL DEFAULT '',
    state            TEXT NOT NULL DEFAULT 'generated', -- generated | superseded | failed
    snapshot_version INTEGER NOT NULL DEFAULT 1,
    created_by       TEXT NOT NULL DEFAULT '',
    created_at       TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_invoice_documents_invoice ON invoice_documents(invoice_uuid, version);
CREATE INDEX IF NOT EXISTS idx_invoice_documents_current ON invoice_documents(invoice_uuid, kind, state);
