# Content model

This is the authoritative field contract for the Breakfast site. Blueprints,
templates, seed content and the SEO/structured-data resolvers all follow the
field names here. If you change a field name, change it in the blueprint, the
template and any seed content together.

Conventions:

- Every public page type has a **SEO** tab that spreads `fields/seo` (plugin
  field group). It provides: `seo_title`, `meta_description`, `canonical_url`,
  `no_index`, `og_image`, `social_title`, `social_description`,
  `structured_data_type`, `sitemap_include`.
- Blueprints use tabs: **Content**, **Media**, **Relationships**, **SEO**,
  **Settings** (only the tabs a type needs).
- Relationships use `pages`/`users` fields storing UUIDs, not URLs.
- Rich text uses the `blocks` field with the curated block set (see below).

## Site (`site/blueprints/site.yml`) — global settings

| Field | Type | Purpose |
|---|---|---|
| `title` | text | Studio name |
| `legal_name` | text | Registered/legal name (structured data, footer) |
| `tagline` | text | Short strapline |
| `email` | email | Main email |
| `enquiry_email` | email | Where enquiries are sent (mirrors `MAIL_ENQUIRIES_TO`) |
| `phone` | tel | Telephone |
| `address` | textarea | Postal address |
| `availability_text` | text | e.g. "Taking projects for Q3" |
| `social` | structure (`platform`, `url`) | Social links |
| `nav` | structure (`label`, `link`) | Primary navigation |
| `footer_nav` | structure (`label`, `link`) | Footer navigation |
| `footer_copy` | textarea | Footer sign-off copy |
| `cta_heading` | text | Global CTA heading |
| `cta_text` | textarea | Global CTA body |
| `cta_primary_label` / `cta_primary_link` | text / text | Primary CTA button |
| `cta_secondary_label` / `cta_secondary_link` | text / text | Secondary CTA button |
| `meta_description` | textarea | Default meta description |
| `default_social_image` | files (1) | Default OG image |
| `announcement_enabled` / `announcement_text` | toggle / text | Announcement banner |
| `maintenance_enabled` / `maintenance_text` | toggle / textarea | Maintenance mode |
| `analytics_provider` | select (none/plausible/ga4) | Mirrors env (display only) |
| `cookie_message` | textarea | Consent banner text (shown only if provider needs it) |

## Home (`home` template)

Discrete, individually-toggleable sections (each has an `enable` toggle):

- **Hero:** `hero_eyebrow`, `hero_headline`, `hero_highlight`, `hero_text`,
  `hero_primary_label`/`hero_primary_link`, `hero_secondary_label`/`hero_secondary_link`,
  `hero_availability`, `hero_image` (optional).
- **Proposition:** `proposition_enabled`, `proposition_heading`, `proposition_text`,
  `proposition_points` (structure: `title`, `text`).
- **Selected work:** `work_enabled`, `work_heading`, `work_intro`,
  `work_projects` (pages → project), `work_count` (number).
- **Services:** `services_enabled`, `services_heading`, `services_intro`,
  `services_list` (pages → service).
- **Problems:** `problems_enabled`, `problems_heading`, `problems_items` (structure: `title`, `text`).
- **Process:** `process_enabled`, `process_heading`, `process_steps` (structure: `title`, `text`).
- **Proof:** `proof_enabled`, `proof_heading`, `testimonials` (structure: `quote`, `name`, `role`, `company`), `logos` (structure: `name`, `category`).
- **Studio statement:** `statement_enabled`, `statement_heading`, `statement_text`.
- **Journal:** `journal_enabled`, `journal_heading`, `journal_count` (number).
- **Final CTA:** `final_cta_enabled`, uses site CTA fields as fallback, plus optional `final_cta_heading`, `final_cta_text`.

## Work index (`work` template)

`title`, `intro` (textarea), `filters_enabled` (toggle). Children: `project`.

## Project (`project` template)

Content: `title`, `client`, `summary` (textarea), `challenge`, `approach`,
`strategy`, `design_explanation`, `build_explanation`, `outcome` (all textarea/blocks),
`metrics` (structure: `value`, `label`, `context`, `note`),
`testimonial_quote`, `testimonial_name`, `testimonial_role`,
`credits` (structure: `role`, `name`), `body` (blocks, flexible sections).
Media: `hero_image` (files 1), `card_image` (files 1), `gallery` (files),
`before_image`/`after_image` (files 1 each).
Relationships: `services` (pages → service), `industries` (tags),
`technology` (tags), `related_projects` (pages → project),
`related_services` (pages → service), `project_url` (url).
Settings: native `status` (draft/unlisted/listed), `featured` (toggle),
`confidential` (toggle), `date` (date). Plus SEO tab.

## Services index (`services` template)

`title`, `intro`. Children: `service`.

## Service (`service` template)

`title`, `short_name`, `summary`, `introduction` (blocks), `suitable_for` (textarea),
`problems` (structure: `text`), `deliverables` (structure: `text`),
`process` (structure: `title`, `text`), `outcomes` (structure: `text`),
`pricing_guidance` (textarea), `timescale` (text),
`faqs` (structure: `question`, `answer`),
`featured_projects` (pages → project), `related_articles` (pages → article),
`cta_heading`, `cta_text`, `cta_label`, `cta_link`. SEO tab.

## About (`about` template)

`title`, `intro` (blocks), `principles` (structure: `title`, `text`),
`team` (structure: `name`, `role`, `bio`, `photo` [file]),
`working_style` (textarea), `location` (text),
`client_fit` (structure: `text`), `not_a_fit` (structure: `text`),
`tools` (tags), `timeline` (structure: `year`, `title`, `text`),
`testimonials` (structure: `quote`, `name`, `role`). SEO tab.

## Journal index (`journal` template)

`title`, `intro`, `featured_article` (pages → article, max 1). Children: `article`.

## Article (`article` template)

`title`, `date` (datetime), `updated` (date), `author` (text),
`excerpt` (textarea — the standfirst), `cover` (files 1),
`categories` (tags), `tags` (tags), `toc_enabled` (toggle),
`body` (blocks — the article blocks), `related_articles` (pages → article),
`related_projects` (pages → project), `cta_heading`, `cta_text`, `cta_label`, `cta_link`.
SEO tab (adds `structured_data_type` = Article by default).

## Contact (`contact` template) & Start a project (`start-project` template)

`title`, `intro` (blocks), and **editable form strings**:
`form_name_label`, `form_email_label`, … (all labels, placeholders, help text,
the consent text, the submit label, and the success/error messages) live in a
`form` structure/fields group so no form copy is hard-coded. Start-a-project adds
the extra fields' labels. SEO tab.

## Legal pages (`legal` template) — privacy, cookies, terms, accessibility

`title`, `body` (blocks), `updated` (date). SEO tab (defaults to `no_index` off).

## Thank you (`thank-you` template)

`title`, `heading`, `text` (blocks), `back_label`, `back_link`. SEO tab (`no_index` on).

## Error (`error` template) — 404

`title`, `heading`, `text`, `cta_label`, `cta_link`.

## Curated block set (`site/blueprints/blocks/*` + `site/snippets/blocks/*`)

`hero`, `intro`, `text` (rich text), `image`, `image-text`, `gallery`,
`project-grid`, `service-grid`, `testimonials`, `logos`, `stats`, `process`,
`faq`, `quote`, `pullquote`, `team`, `journal-feed`, `cta`, `divider`,
`video`, `embed`, `comparison`, `related`, `callout`, `code`, `columns`.

Each block has a blueprint (constrained options only) and a snippet
(`site/snippets/blocks/<name>.php`) that renders semantic markup. Text is always
escaped; rich text renders through Kirby's `->kt()`/`->kti()`. No block accepts
raw HTML or inline styles derived from user input.
