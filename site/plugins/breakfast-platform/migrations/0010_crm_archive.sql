-- Soft-archive support for companies and opportunities.
--
-- Editing, archiving and restoring these records is done in the admin UI. A
-- soft archive (archived_at timestamp) keeps history and linked records intact
-- — nothing is hard-deleted — and archived records drop out of the active
-- lists and pipeline while remaining restorable. Opportunities also gain an
-- explicit close outcome so "abandoned" is distinct from won/lost.

ALTER TABLE companies ADD COLUMN archived_at TEXT;
ALTER TABLE opportunities ADD COLUMN archived_at TEXT;
ALTER TABLE opportunities ADD COLUMN close_outcome TEXT; -- won | lost | abandoned (null while open)

CREATE INDEX IF NOT EXISTS idx_companies_archived ON companies(archived_at);
CREATE INDEX IF NOT EXISTS idx_opps_archived ON opportunities(archived_at);
