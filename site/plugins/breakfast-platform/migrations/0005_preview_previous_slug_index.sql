-- Breakfast platform — index the previews' previous_slug.
-- Forward-only. previous_slug is queried by slugTaken() (OR-branch) and by the
-- canonical-redirect lookup (findByPreviousSlug); without an index those force a
-- scan. public_slug is already uniquely indexed.

CREATE INDEX IF NOT EXISTS idx_previews_previous_slug ON client_previews(previous_slug);
