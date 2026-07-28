# Manual release checklist

A human pre-release pass to run **in addition to** the automated suite
(`composer test`, `composer stan`, `composer cs-check`, `npm run lint`,
`npx playwright test`) and the CLI smoke checks (`php bin/console app:check`,
`php bin/console mail:check`). Work through it against a release candidate on a
staging environment that mirrors production (own `.env`, own `storage/data/`
tree, Brevo in a test/sandbox mode where possible).

Tick every box. Anything that cannot be ticked is a release blocker until it is
either fixed or consciously waived and recorded.

## Public website

- [ ] **Every top-level route** loads without error: `/` (home), `/work`,
      `/services`, `/about`, `/journal`, `/contact`, `/start-a-project`,
      `/privacy`.
- [ ] A representative **case study** (e.g. `/work/passo`), **service**
      (`/services/new-website`) and **journal article**
      (`/journal/five-second-test`) render fully.
- [ ] **Mobile navigation** opens, closes, traps focus and links correctly.
- [ ] **Contact form** submits, validates, and shows the success state.
- [ ] **Start-a-project form** submits (including any upload field when enabled)
      and shows the success state.
- [ ] **Case studies / work index** filtering works.
- [ ] **Services** pages are complete and internally linked.
- [ ] **Journal** index and article pages render; reading time is shown.
- [ ] **RSS feed** (`/journal/feed.rss`) is valid and lists current articles.
- [ ] **Sitemap** (`/sitemap.xml`) lists the expected public URLs and no drafts.
- [ ] **Structured data** (JSON-LD) is present and valid on key page types.
- [ ] **Social sharing** — Open Graph / Twitter cards render a correct title,
      description and image when a URL is pasted into a preview tool.
- [ ] **404** — an unknown URL renders the error page (not a stack trace).
- [ ] **Keyboard navigation** — every interactive element is reachable and
      operable; focus is visible.
- [ ] **Reduced motion** — `prefers-reduced-motion` is honoured (no essential
      motion).
- [ ] **320px** narrow viewport — no horizontal scroll, nothing clipped.
- [ ] **Large desktop** — layout holds at wide widths.
- [ ] **Real content** is in place — no lorem ipsum, no `TODO`, no placeholder
      copy in published pages.
- [ ] **Placeholder labels** — form and UI labels are final, not stand-ins.
- [ ] **Broken links** — internal and outbound links resolve (run a crawler).

## Kirby Panel

- [ ] **Editor workflow** — an editor can open a page, edit fields and save.
- [ ] **Media uploads** — images upload and render at the expected srcset sizes.
- [ ] **Publish workflow** — changing a page to listed publishes it and fires the
      `content.published` webhook (if endpoints are configured).
- [ ] **SEO fields** — title, description, canonical and OG fields save and take
      effect on the front end.
- [ ] **Navigation editing** — menu / navigation structures can be edited and
      reflect on the site.
- [ ] **Global settings** — site-wide settings (studio address, defaults) save
      and apply.
- [ ] **Role restrictions** — a non-admin role sees only what its blueprint
      permits; restricted areas are hidden **and** blocked server-side.

## CRM

- [ ] **Enquiry** — a form submission creates an enquiry with a reference and an
      immutable `form.received` activity.
- [ ] **Contact** — the enquiry upserts a contact (by normalised email; no
      duplicate).
- [ ] **Company** — a company is found or created and linked.
- [ ] **Opportunity** — an enquiry can be converted to an opportunity; stage
      moves are recorded.
- [ ] **Task** — a task can be created, updated and marked done (stamps
      `completed_at`).
- [ ] **Email** — a manager can compose, preview and send a one-to-one CRM email
      (Mail area); an analyst can view delivery but **not** send.
- [ ] **Delivery events** — the Brevo webhook updates the outbound row's status
      and adds meaningful contact-timeline entries.
- [ ] **Suppression** — a suppressed recipient is refused with
      `recipient_suppressed`; suppression is visible in the mail status.
- [ ] **Export** — a subject-access export
      (`php bin/console privacy:export-contact <uuid>`) returns the contact,
      company, enquiries, activities and emails.
- [ ] **Anonymisation** —
      `php bin/console privacy:anonymise-contact <uuid> --confirm` scrubs PII,
      scrubs delivery addresses (keeping the hash) and records the action.
- [ ] **Deletion / retention** —
      `php bin/console privacy:cleanup --dry-run` reports counts and
      `--confirm` prunes old events, jobs, IP hashes and nonces.
- [ ] **Failed jobs** — the failed-jobs view lists failures and a manager can
      retry one.

## Brevo (transactional email)

- [ ] **Domain verified** in Brevo (fully authenticated, not pending).
- [ ] **SPF** record published and passing.
- [ ] **DKIM** record published and passing.
- [ ] **DMARC** policy published.
- [ ] **Sender verified** — `BREVO_SENDER_EMAIL` is a verified sender.
- [ ] **Test send** — `php bin/console mail:test --to=<addr> --confirm` reports
      `accepted` and the message arrives.
- [ ] **Acknowledgement send** — a public enquiry triggers the visitor
      acknowledgement email (valid recipient only).
- [ ] **Internal notification** — the studio enquiries inbox receives the
      internal notification, with the submitter as Reply-To when valid.
- [ ] **Delivered webhook** — a delivery event advances the outbound row to
      `delivered`.
- [ ] **Bounce webhook** — a hard bounce moves the row to `hard_bounced` and
      suppresses the recipient transactionally.
- [ ] **Invalid-recipient behaviour** — sending to a syntactically invalid
      address is refused before queueing; an `invalid_email` event suppresses.
- [ ] **Suppression** — transactional and marketing suppression are separate; an
      unsubscribe does not block a transactional reply.
- [ ] **API key rotation** — a rotated key still authenticates
      (`php bin/console mail:check --connect`).
- [ ] **Webhook authentication** — the webhook rejects an unauthenticated call
      `401` and accepts the configured shared secret / Basic auth.
- [ ] **Tracking defaults OFF** — `BREVO_TRACK_OPENS` and `BREVO_TRACK_CLICKS`
      are `false` unless deliberately enabled.

## Hermes

- [ ] **Signed request** — a correctly signed request to a scoped endpoint
      succeeds and is audited `ok`.
- [ ] **Scope restriction** — a credential lacking the required scope is refused
      `403` and audited `denied`.
- [ ] **Draft creation** — a `content:draft` call creates an **unpublished**
      draft under the correct parent/template.
- [ ] **Publish denial** — Hermes cannot publish, delete or modify existing
      content, users or permissions.
- [ ] **Audit history** — every request (ok / denied / error) is written to the
      audit log with no secrets, signatures or nonces stored.

## Operations

- [ ] **Queue worker** — `queue:run` (cron) or `queue:work` (supervised) is
      running and draining the queue.
- [ ] **Cron / systemd** — exactly one runner is installed
      (`deploy/cron.example` **or** `deploy/queue-worker.service.example`, not
      both).
- [ ] **Backup** — a fresh backup was taken immediately before deploy (see
      [backups.md](backups.md)).
- [ ] **Restore** — a restore has been rehearsed and verified (see
      [restore.md](restore.md)).
- [ ] **Logs** — `storage/logs/` is being written and rotated; the queue-cron
      log shows successful passes.
- [ ] **Disk permissions** — `storage/` is writable by the web user; code is
      read-only; `.env` is `0640` (`bash deploy/permissions.sh.example`).
- [ ] **Production debug disabled** — `APP_DEBUG=false` / `APP_ENV=production`;
      no stack traces or paths are exposed publicly.
- [ ] **Secrets absent from the repository** — `.env`, `storage/accounts/` and
      the `storage/data/` tree are gitignored and not committed; no API keys or
      HMAC secrets appear in tracked files.

## Breakfast Admin & Client Previews
- [ ] **Admin slug** — `/panel` returns 404; the admin loads at
      `BREAKFAST_ADMIN_SLUG`; the slug is not in `robots.txt`.
- [ ] **Admin permissions** — non-admin roles see only their permitted nav; admin
      routes reject unauthorised users server-side.
- [ ] **Preview origin** — `CLIENT_PREVIEW_HOST` serves previews; the main site
      and admin are unaffected; admin cookies are host-only (no wildcard domain).
- [ ] **Preview upload safety** — a ZIP containing a `.php`/traversal entry is
      rejected (validation_failed); a clean ZIP publishes and loads.
- [ ] **Preview headers** — a served preview carries nosniff, noindex and the
      preview CSP (`object-src 'none'`, `worker-src 'none'`, `form-action 'self'`).
- [ ] **Preview controls** — disable, rollback and password gate work; changing a
      password invalidates old sessions.
