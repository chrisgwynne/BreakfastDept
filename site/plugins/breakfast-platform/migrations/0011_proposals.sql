-- Proposals & quotes — the entry point of the commercial lifecycle.
--
-- Money is stored as INTEGER minor units (pence); quantities as thousandths;
-- tax/discount as basis points — identical conventions to invoicing, so a
-- proposal can convert cleanly into deposit/final invoices. A proposal freezes
-- an immutable snapshot when sent, and acceptance is a deliberate, evidenced
-- act (name/email/hash/selected options) — never a mere page visit.

CREATE TABLE IF NOT EXISTS proposal_sequences (
    prefix     TEXT    NOT NULL,
    year       INTEGER NOT NULL,
    next_seq   INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (prefix, year)
);

CREATE TABLE IF NOT EXISTS proposals (
    uuid                 TEXT PRIMARY KEY,
    number               TEXT,                            -- NULL until sent
    status               TEXT NOT NULL DEFAULT 'draft',   -- draft|ready|sent|viewed|accepted|rejected|expired|superseded|withdrawn
    title                TEXT NOT NULL DEFAULT '',
    contact_uuid         TEXT,
    company_uuid         TEXT,
    opportunity_uuid     TEXT,
    project_uuid         TEXT,
    supersedes_uuid      TEXT,                            -- prior proposal this one replaces
    currency             TEXT NOT NULL DEFAULT 'GBP',
    -- Narrative sections.
    introduction         TEXT NOT NULL DEFAULT '',
    client_problem       TEXT NOT NULL DEFAULT '',
    recommended_solution TEXT NOT NULL DEFAULT '',
    scope                TEXT NOT NULL DEFAULT '',
    deliverables         TEXT NOT NULL DEFAULT '',
    exclusions           TEXT NOT NULL DEFAULT '',
    assumptions          TEXT NOT NULL DEFAULT '',
    timeline             TEXT NOT NULL DEFAULT '',
    payment_schedule     TEXT NOT NULL DEFAULT '',
    terms                TEXT NOT NULL DEFAULT '',
    internal_notes       TEXT NOT NULL DEFAULT '',
    -- Client-facing identity (snapshotted from settings at send).
    client_name          TEXT NOT NULL DEFAULT '',
    client_email         TEXT NOT NULL DEFAULT '',
    seller_name          TEXT NOT NULL DEFAULT '',
    vat_registered       INTEGER NOT NULL DEFAULT 0,
    -- Money (pence). Totals reflect fixed + selected recurring; optional items
    -- are excluded until the client selects them.
    subtotal             INTEGER NOT NULL DEFAULT 0,
    discount_total       INTEGER NOT NULL DEFAULT 0,
    tax_total            INTEGER NOT NULL DEFAULT 0,
    total                INTEGER NOT NULL DEFAULT 0,
    deposit_amount       INTEGER NOT NULL DEFAULT 0,       -- pence, 0 = none
    recurring_total      INTEGER NOT NULL DEFAULT 0,       -- pence per period (indicative)
    owner                TEXT NOT NULL DEFAULT '',
    expiry_date          TEXT,
    public_token         TEXT,
    snapshot             TEXT NOT NULL DEFAULT '',         -- frozen JSON at send
    document_status      TEXT NOT NULL DEFAULT 'none',     -- none|pending|generated|failed
    sent_at              TEXT,
    viewed_at            TEXT,
    accepted_at          TEXT,
    rejected_at          TEXT,
    created_by           TEXT NOT NULL DEFAULT '',
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_proposals_status     ON proposals (status);
CREATE INDEX IF NOT EXISTS idx_proposals_contact    ON proposals (contact_uuid);
CREATE INDEX IF NOT EXISTS idx_proposals_opportunity ON proposals (opportunity_uuid);
CREATE UNIQUE INDEX IF NOT EXISTS idx_proposals_number ON proposals (number) WHERE number IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_proposals_token  ON proposals (public_token) WHERE public_token IS NOT NULL;

CREATE TABLE IF NOT EXISTS proposal_items (
    uuid           TEXT PRIMARY KEY,
    proposal_uuid  TEXT NOT NULL,
    position       INTEGER NOT NULL DEFAULT 0,
    kind           TEXT NOT NULL DEFAULT 'fixed',    -- fixed|optional|recurring
    description    TEXT NOT NULL DEFAULT '',
    quantity       INTEGER NOT NULL DEFAULT 1000,    -- thousandths
    unit_price     INTEGER NOT NULL DEFAULT 0,       -- pence
    tax_rate       INTEGER NOT NULL DEFAULT 0,       -- basis points
    discount       INTEGER NOT NULL DEFAULT 0,       -- basis points
    recurrence     TEXT NOT NULL DEFAULT '',         -- '' | monthly | quarterly | annual (recurring items)
    is_selected    INTEGER NOT NULL DEFAULT 0,       -- optional items chosen by the client
    line_subtotal  INTEGER NOT NULL DEFAULT 0,       -- pence
    line_tax       INTEGER NOT NULL DEFAULT 0,       -- pence
    line_total     INTEGER NOT NULL DEFAULT 0        -- pence
);
CREATE INDEX IF NOT EXISTS idx_proposal_items_proposal ON proposal_items (proposal_uuid, position);

-- Immutable state-change log.
CREATE TABLE IF NOT EXISTS proposal_events (
    uuid           TEXT PRIMARY KEY,
    proposal_uuid  TEXT NOT NULL,
    type           TEXT NOT NULL,
    detail         TEXT NOT NULL DEFAULT '',
    actor          TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_proposal_events_proposal ON proposal_events (proposal_uuid, created_at);

-- Evidenced acceptance — the legal record. One row per genuine acceptance.
CREATE TABLE IF NOT EXISTS proposal_acceptances (
    uuid             TEXT PRIMARY KEY,
    proposal_uuid    TEXT NOT NULL,
    snapshot_version INTEGER NOT NULL DEFAULT 1,
    accepted_name    TEXT NOT NULL DEFAULT '',
    accepted_email   TEXT NOT NULL DEFAULT '',
    ip_hash          TEXT NOT NULL DEFAULT '',      -- salted hash, never the raw IP
    user_agent       TEXT NOT NULL DEFAULT '',      -- summarised
    selected_options TEXT NOT NULL DEFAULT '[]',    -- JSON list of chosen optional item uuids
    total_accepted   INTEGER NOT NULL DEFAULT 0,    -- pence
    wording          TEXT NOT NULL DEFAULT '',      -- the acceptance statement shown
    document_hash    TEXT NOT NULL DEFAULT '',      -- sha256 of the accepted snapshot
    created_at       TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_proposal_acceptances_proposal ON proposal_acceptances (proposal_uuid);
