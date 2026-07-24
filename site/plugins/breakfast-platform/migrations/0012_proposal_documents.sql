-- Stored PDF for a proposal. The document is rendered from the frozen snapshot
-- and kept outside the webroot under an opaque key; the hash lets us verify it
-- on download. One current document per proposal is sufficient (the snapshot is
-- immutable once sent, so regeneration is byte-stable).

ALTER TABLE proposals ADD COLUMN document_key TEXT NOT NULL DEFAULT '';
ALTER TABLE proposals ADD COLUMN document_hash TEXT NOT NULL DEFAULT '';
ALTER TABLE proposals ADD COLUMN document_bytes INTEGER NOT NULL DEFAULT 0;
