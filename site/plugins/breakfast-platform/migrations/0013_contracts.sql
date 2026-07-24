-- Contracts & electronic signature — the commercial commitment step that
-- follows an accepted proposal.
--
-- A contract freezes an immutable version when SENT (merge fields resolved,
-- party + clause text frozen, unsigned PDF hashed). Signing is a deliberate,
-- evidenced act per party; a page visit only records "viewed". After all
-- required parties sign, a separate immutable signed PDF (body + signature
-- certificate) is produced. Money is integer pence, mirroring proposals/invoices.

CREATE TABLE IF NOT EXISTS contract_sequences (
    prefix     TEXT    NOT NULL,
    year       INTEGER NOT NULL,
    next_seq   INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (prefix, year)
);

CREATE TABLE IF NOT EXISTS contracts (
    uuid                     TEXT PRIMARY KEY,
    number                   TEXT,                             -- NULL until sent
    status                   TEXT NOT NULL DEFAULT 'draft',    -- draft|ready|sent|viewed|signed|declined|expired|void|superseded|withdrawn
    title                    TEXT NOT NULL DEFAULT '',
    template_key             TEXT NOT NULL DEFAULT 'general',
    company_uuid             TEXT,
    contact_uuid             TEXT,
    opportunity_uuid         TEXT,
    proposal_uuid            TEXT,
    project_uuid             TEXT,
    supersedes_uuid          TEXT,
    owner                    TEXT NOT NULL DEFAULT '',
    currency                 TEXT NOT NULL DEFAULT 'GBP',
    contract_value           INTEGER NOT NULL DEFAULT 0,       -- pence
    deposit_amount           INTEGER NOT NULL DEFAULT 0,       -- pence
    effective_date           TEXT,
    expiry_date              TEXT,
    revision_limit           INTEGER NOT NULL DEFAULT 0,
    -- Structured clause sections (JSON list of {key,heading,body,optional,enabled}).
    sections                 TEXT NOT NULL DEFAULT '[]',
    governing_law            TEXT NOT NULL DEFAULT '',
    signature_wording        TEXT NOT NULL DEFAULT '',
    internal_notes           TEXT NOT NULL DEFAULT '',
    seller_name              TEXT NOT NULL DEFAULT '',
    client_name              TEXT NOT NULL DEFAULT '',
    client_email             TEXT NOT NULL DEFAULT '',
    -- Frozen at send.
    snapshot                 TEXT NOT NULL DEFAULT '',
    snapshot_version         INTEGER NOT NULL DEFAULT 1,
    public_token             TEXT,
    unsigned_key             TEXT NOT NULL DEFAULT '',
    unsigned_hash            TEXT NOT NULL DEFAULT '',
    signed_key               TEXT NOT NULL DEFAULT '',
    signed_hash              TEXT NOT NULL DEFAULT '',
    document_status          TEXT NOT NULL DEFAULT 'none',     -- none|unsigned|signed|failed
    sent_at                  TEXT,
    viewed_at                TEXT,
    signed_at                TEXT,      -- client signed
    completed_at             TEXT,      -- all required parties signed
    created_by               TEXT NOT NULL DEFAULT '',
    created_at               TEXT NOT NULL,
    updated_at               TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contracts_status   ON contracts (status);
CREATE INDEX IF NOT EXISTS idx_contracts_company  ON contracts (company_uuid);
CREATE INDEX IF NOT EXISTS idx_contracts_proposal ON contracts (proposal_uuid);
CREATE UNIQUE INDEX IF NOT EXISTS idx_contracts_number ON contracts (number) WHERE number IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_contracts_token  ON contracts (public_token) WHERE public_token IS NOT NULL;

-- Required signatories (Breakfast + client(s)). Ordered signing supported.
CREATE TABLE IF NOT EXISTS contract_parties (
    uuid           TEXT PRIMARY KEY,
    contract_uuid  TEXT NOT NULL,
    role           TEXT NOT NULL DEFAULT 'client',   -- breakfast|client|client2
    name           TEXT NOT NULL DEFAULT '',
    email          TEXT NOT NULL DEFAULT '',
    signing_order  INTEGER NOT NULL DEFAULT 1,
    required       INTEGER NOT NULL DEFAULT 1,
    status         TEXT NOT NULL DEFAULT 'pending',  -- pending|signed|declined
    signed_at      TEXT,
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contract_parties_contract ON contract_parties (contract_uuid, signing_order);

-- Immutable state-change log (staff-facing).
CREATE TABLE IF NOT EXISTS contract_events (
    uuid           TEXT PRIMARY KEY,
    contract_uuid  TEXT NOT NULL,
    type           TEXT NOT NULL,
    detail         TEXT NOT NULL DEFAULT '',
    actor          TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contract_events_contract ON contract_events (contract_uuid, created_at);

-- Signature evidence — the legal record. One row per signing party.
CREATE TABLE IF NOT EXISTS contract_signatures (
    uuid             TEXT PRIMARY KEY,
    contract_uuid    TEXT NOT NULL,
    party_uuid       TEXT NOT NULL DEFAULT '',
    snapshot_version INTEGER NOT NULL DEFAULT 1,
    signer_name      TEXT NOT NULL DEFAULT '',
    signer_email     TEXT NOT NULL DEFAULT '',
    signer_role      TEXT NOT NULL DEFAULT 'client',
    contact_uuid     TEXT,
    method           TEXT NOT NULL DEFAULT 'typed',   -- typed|drawn
    wording          TEXT NOT NULL DEFAULT '',
    ip_hash          TEXT NOT NULL DEFAULT '',         -- salted/keyed hash, never a raw IP
    user_agent       TEXT NOT NULL DEFAULT '',
    token_id         TEXT NOT NULL DEFAULT '',
    document_hash    TEXT NOT NULL DEFAULT '',         -- sha256 of the signed version snapshot
    signed_at        TEXT NOT NULL,
    created_at       TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contract_signatures_contract ON contract_signatures (contract_uuid);
