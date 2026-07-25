-- Portfolio & Case Studies module.
--
-- Breakfast Admin is the authoritative editorial interface. A portfolio_record
-- is the internal editorial entity (stable uuid key; the public slug is
-- editable and NEVER a relationship key). Publishing materialises an explicit,
-- public-safe snapshot (portfolio_snapshots) that the public site reads — the
-- internal record, CRM links, private notes and unapproved media never cross
-- that boundary. Status is a real, server-enforced state machine; every change
-- writes an immutable portfolio_event and is audited by the caller.

CREATE TABLE IF NOT EXISTS portfolio_records (
    uuid                 TEXT PRIMARY KEY,
    slug                 TEXT NOT NULL UNIQUE,             -- public slug (editable; not a FK key)
    status               TEXT NOT NULL DEFAULT 'draft',    -- draft|internal_review|ready|changes_requested|scheduled|published|unpublished|archived
    visibility           TEXT NOT NULL DEFAULT 'private',  -- private|preview|public (derived from status, stored for query)

    -- Internal relationships (stable uuids; never exposed publicly).
    company_uuid         TEXT,
    contact_uuid         TEXT,
    project_uuid         TEXT,

    -- Internal editorial fields.
    internal_name        TEXT NOT NULL DEFAULT '',         -- internal reference, never public
    project_title        TEXT NOT NULL DEFAULT '',         -- internal project title

    -- Public editorial fields (explicitly chosen; the only fields ever published).
    client_display_name  TEXT NOT NULL DEFAULT '',         -- public client name (may be anonymised)
    display_title        TEXT NOT NULL DEFAULT '',         -- public project headline
    card_title           TEXT NOT NULL DEFAULT '',         -- short card title
    case_study_headline  TEXT NOT NULL DEFAULT '',         -- case-study page headline
    summary              TEXT NOT NULL DEFAULT '',         -- short public introduction
    project_type         TEXT NOT NULL DEFAULT '',
    industry             TEXT NOT NULL DEFAULT '',
    location             TEXT NOT NULL DEFAULT '',
    website_url          TEXT NOT NULL DEFAULT '',
    link_label           TEXT NOT NULL DEFAULT '',

    -- Dates.
    completion_date      TEXT,
    launch_date          TEXT,
    published_at         TEXT,
    scheduled_at         TEXT,

    -- Presentation + placement.
    featured             INTEGER NOT NULL DEFAULT 0,
    homepage_position    INTEGER,                          -- NULL = not on the homepage
    homepage_treatment   TEXT NOT NULL DEFAULT 'standard',
    homepage_media_uuid  TEXT,                             -- overrides the featured/cover image on the homepage
    work_position        INTEGER,
    cover_media_uuid     TEXT,                             -- primary card / hero image
    template             TEXT NOT NULL DEFAULT 'standard',
    accent               TEXT NOT NULL DEFAULT 'butter',   -- brand accent treatment
    animation            TEXT NOT NULL DEFAULT 'standard', -- standard|split|layered|quiet

    -- SEO / sharing overrides (defaults generated from public fields).
    seo_title            TEXT NOT NULL DEFAULT '',
    seo_description      TEXT NOT NULL DEFAULT '',
    og_title             TEXT NOT NULL DEFAULT '',
    og_description       TEXT NOT NULL DEFAULT '',
    og_media_uuid        TEXT,
    robots               TEXT NOT NULL DEFAULT 'index',    -- index|noindex
    in_sitemap           INTEGER NOT NULL DEFAULT 1,

    -- Bookkeeping.
    revision             INTEGER NOT NULL DEFAULT 1,       -- optimistic concurrency
    created_by           TEXT NOT NULL DEFAULT '',
    updated_by           TEXT NOT NULL DEFAULT '',
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL,
    archived_at          TEXT
);
CREATE INDEX IF NOT EXISTS idx_portfolio_status ON portfolio_records (status, updated_at);
CREATE INDEX IF NOT EXISTS idx_portfolio_company ON portfolio_records (company_uuid);
CREATE INDEX IF NOT EXISTS idx_portfolio_project ON portfolio_records (project_uuid);
CREATE INDEX IF NOT EXISTS idx_portfolio_homepage ON portfolio_records (homepage_position);
CREATE INDEX IF NOT EXISTS idx_portfolio_scheduled ON portfolio_records (status, scheduled_at);

-- Structured case-study story: a fixed set of named sections, each optional.
CREATE TABLE IF NOT EXISTS portfolio_story_sections (
    uuid          TEXT PRIMARY KEY,
    record_uuid   TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    section_key   TEXT NOT NULL,                           -- client|problem|objective|approach|work|outcome|client_response|next
    heading       TEXT NOT NULL DEFAULT '',                -- heading override
    body          TEXT NOT NULL DEFAULT '',                -- sanitised rich text (no scripts/embeds)
    visible       INTEGER NOT NULL DEFAULT 1,
    layout        TEXT NOT NULL DEFAULT 'standard',
    sort_order    INTEGER NOT NULL DEFAULT 0,
    UNIQUE (record_uuid, section_key)
);
CREATE INDEX IF NOT EXISTS idx_portfolio_story_record ON portfolio_story_sections (record_uuid, sort_order);

-- Modular page-builder blocks. No arbitrary code — `type` is an allow-listed
-- block type and `content`/`options` are validated JSON.
CREATE TABLE IF NOT EXISTS portfolio_blocks (
    uuid          TEXT PRIMARY KEY,
    record_uuid   TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    type          TEXT NOT NULL,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    visible       INTEGER NOT NULL DEFAULT 1,
    content       TEXT NOT NULL DEFAULT '{}',              -- JSON, sanitised
    options       TEXT NOT NULL DEFAULT '{}',              -- JSON layout/accessibility options
    media_refs    TEXT NOT NULL DEFAULT '[]',              -- JSON list of portfolio_media uuids
    deleted       INTEGER NOT NULL DEFAULT 0,              -- soft-delete so a block can be restored
    revision      INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_portfolio_blocks_record ON portfolio_blocks (record_uuid, sort_order);

-- Portfolio media. Sits on top of the shared secure file system: `file_uuid`
-- references the stored, validated original; public variants are generated at
-- publish time. Nothing is public until approved_for_public = 1 with a recorded
-- approver + rights note.
CREATE TABLE IF NOT EXISTS portfolio_media (
    uuid                TEXT PRIMARY KEY,
    record_uuid         TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    file_uuid           TEXT,                              -- shared file library reference (original)
    source_file_uuid    TEXT,                              -- approved project file this came from, if any
    kind                TEXT NOT NULL DEFAULT 'image',     -- image|video|embed|asset
    role                TEXT NOT NULL DEFAULT 'gallery',   -- cover|gallery|before|after|logo|desktop|mobile|og
    device              TEXT NOT NULL DEFAULT 'none',      -- none|desktop|mobile|tablet|laptop
    alt                 TEXT NOT NULL DEFAULT '',
    decorative          INTEGER NOT NULL DEFAULT 0,        -- explicitly decorative (alt not required)
    caption             TEXT NOT NULL DEFAULT '',
    focal_x             REAL NOT NULL DEFAULT 0.5,
    focal_y             REAL NOT NULL DEFAULT 0.5,
    background          TEXT NOT NULL DEFAULT '',
    treatment           TEXT NOT NULL DEFAULT 'auto',      -- auto|light|dark
    embed_url           TEXT NOT NULL DEFAULT '',          -- approved external embed (video)
    downloadable        INTEGER NOT NULL DEFAULT 0,
    width               INTEGER,
    height              INTEGER,
    variants            TEXT NOT NULL DEFAULT '{}',        -- JSON: generated public variant paths

    -- Rights / public-use approval (required before any public exposure).
    approved_for_public INTEGER NOT NULL DEFAULT 0,
    approved_by         TEXT NOT NULL DEFAULT '',
    approved_at         TEXT,
    rights_source       TEXT NOT NULL DEFAULT '',
    rights_note         TEXT NOT NULL DEFAULT '',
    consent_note        TEXT NOT NULL DEFAULT '',
    sensitive_flag      INTEGER NOT NULL DEFAULT 0,        -- editorial "may contain sensitive data" warning

    sort_order          INTEGER NOT NULL DEFAULT 0,
    archived            INTEGER NOT NULL DEFAULT 0,
    created_by          TEXT NOT NULL DEFAULT '',
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_portfolio_media_record ON portfolio_media (record_uuid, sort_order);

-- Genuine results. Numeric claims require a source/verification note.
CREATE TABLE IF NOT EXISTS portfolio_results (
    uuid          TEXT PRIMARY KEY,
    record_uuid   TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    value         TEXT NOT NULL DEFAULT '',                -- may be empty for qualitative outcomes
    label         TEXT NOT NULL DEFAULT '',
    note          TEXT NOT NULL DEFAULT '',
    is_numeric    INTEGER NOT NULL DEFAULT 0,
    source        TEXT NOT NULL DEFAULT '',                -- required when is_numeric = 1
    verified      INTEGER NOT NULL DEFAULT 0,
    visible       INTEGER NOT NULL DEFAULT 1,
    sort_order    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_portfolio_results_record ON portfolio_results (record_uuid, sort_order);

-- One optional genuine testimonial. Never public until approved = 1.
CREATE TABLE IF NOT EXISTS portfolio_testimonials (
    uuid           TEXT PRIMARY KEY,
    record_uuid    TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    quote          TEXT NOT NULL DEFAULT '',
    person_name    TEXT NOT NULL DEFAULT '',
    person_role    TEXT NOT NULL DEFAULT '',
    company        TEXT NOT NULL DEFAULT '',
    photo_media_uuid TEXT,
    approved       INTEGER NOT NULL DEFAULT 0,
    approved_by    TEXT NOT NULL DEFAULT '',
    approved_at    TEXT,
    source         TEXT NOT NULL DEFAULT '',
    visible        INTEGER NOT NULL DEFAULT 1,
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_portfolio_testimonials_record ON portfolio_testimonials (record_uuid);

-- Taxonomy: stable ids with editable labels + slugs, so relabelling never
-- breaks URLs. Services reference the real Kirby service pages by slug.
CREATE TABLE IF NOT EXISTS portfolio_taxonomies (
    uuid          TEXT PRIMARY KEY,
    kind          TEXT NOT NULL,                           -- service|industry|project_type|category
    label         TEXT NOT NULL,
    slug          TEXT NOT NULL,
    service_slug  TEXT NOT NULL DEFAULT '',                -- for kind=service: the Kirby service page slug
    sort_order    INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL,
    UNIQUE (kind, slug)
);

CREATE TABLE IF NOT EXISTS portfolio_record_taxonomies (
    record_uuid   TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    taxonomy_uuid TEXT NOT NULL REFERENCES portfolio_taxonomies(uuid) ON DELETE CASCADE,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (record_uuid, taxonomy_uuid)
);

-- Service-page placement overrides (include/exclude/order/featured image) keyed
-- by the Kirby service slug.
CREATE TABLE IF NOT EXISTS portfolio_service_overrides (
    record_uuid   TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    service_slug  TEXT NOT NULL,
    mode          TEXT NOT NULL DEFAULT 'auto',            -- auto|include|exclude
    sort_order    INTEGER,
    media_uuid    TEXT,
    PRIMARY KEY (record_uuid, service_slug)
);

-- Slug history → safe 301 redirects. A slug is retired here on change.
CREATE TABLE IF NOT EXISTS portfolio_redirects (
    old_slug      TEXT PRIMARY KEY,
    record_uuid   TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    created_at    TEXT NOT NULL
);

-- Versioned public snapshot. Publishing writes a new snapshot then flips
-- is_current in one transaction (pointer swap); the previous snapshot is kept
-- as last-known-good. The public site reads ONLY is_current = 1 rows.
CREATE TABLE IF NOT EXISTS portfolio_snapshots (
    uuid          TEXT PRIMARY KEY,
    record_uuid   TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    slug          TEXT NOT NULL,
    revision      INTEGER NOT NULL DEFAULT 1,
    payload       TEXT NOT NULL,                           -- public-safe JSON (the ONLY thing served publicly)
    is_current    INTEGER NOT NULL DEFAULT 0,
    created_by    TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_portfolio_snapshots_current ON portfolio_snapshots (is_current, slug);
CREATE INDEX IF NOT EXISTS idx_portfolio_snapshots_record ON portfolio_snapshots (record_uuid, created_at);

-- Pre-publication privacy review checklist (editorial safeguard).
CREATE TABLE IF NOT EXISTS portfolio_privacy_reviews (
    uuid            TEXT PRIMARY KEY,
    record_uuid     TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    checklist_version TEXT NOT NULL DEFAULT '1',
    decisions       TEXT NOT NULL DEFAULT '{}',            -- JSON: item => bool
    notes           TEXT NOT NULL DEFAULT '',
    reviewer        TEXT NOT NULL DEFAULT '',
    reviewed_at     TEXT NOT NULL,
    passed          INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_portfolio_privacy_record ON portfolio_privacy_reviews (record_uuid, reviewed_at);

-- Immutable editorial events (portfolio history). Summaries only — never the
-- private content body or sensitive media detail.
CREATE TABLE IF NOT EXISTS portfolio_events (
    uuid          TEXT PRIMARY KEY,
    record_uuid   TEXT NOT NULL REFERENCES portfolio_records(uuid) ON DELETE CASCADE,
    type          TEXT NOT NULL,
    detail        TEXT NOT NULL DEFAULT '',
    actor         TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_portfolio_events_record ON portfolio_events (record_uuid, created_at);
