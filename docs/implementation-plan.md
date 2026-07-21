# Implementation plan — Breakfast (Kirby 5 rebuild)

_Status: living document. Written during the initial audit, kept current as the build progresses._

## 1. Audit of the existing project

The starting point is a **single static page** deployed over FTP to a shared-host docroot:

- `index.html` — one long homepage (hero, trust logos, work/case studies, "how we think",
  services, process, "why", pricing, a lead-magnet form, FAQ, final CTA, footer, sticky CTA).
- `styles.css` — a complete design system already expressed in CSS custom properties:
  editorial neobrutalism, cream background, hard offset shadows, 3px ink borders.
- `script.js` — accessible mobile menu, sticky CTA, scroll-reveal, footer year.
- `.github/workflows/ftp-deploy.yml` + `ftp-pull.yml` — two-way FTP mirror to the live host.
- `public_html/` (and a nested `public_html/public_html/`) — duplicated copies produced by the
  FTP pull job. These are build/sync artefacts, not source, and are removed in the rebuild.

### What is worth keeping

- **Brand personality and voice.** Direct, warm, British, plainspoken, anti-jargon. Every line of
  copy is carried forward as _editable seed content_, not hard-coded.
- **Visual identity.** The egg/yolk logo, the palette (`--primary #fdc800` yellow,
  `--secondary #432dd7` purple, `--text #1c293c` ink, `--bg #f5efe3` cream), the type stack
  (Inter / JetBrains Mono / Caveat), hard shadows and thick borders. Migrated into a token layer.
- **Information architecture.** The homepage sections map cleanly onto the required IA.
- **Accessibility patterns.** Skip link, `aria-expanded` menu, focus management, reduced-motion.

### Weaknesses the rebuild fixes

- Everything is hard-coded — no editor can change a word. → Kirby fields / site config / blocks.
- No real work, services, journal or contact system — all faked in HTML. → Full content models.
- `mailto:` "forms" that lose every submission. → Server-side validated forms + CRM + queue.
- No SEO system, sitemap, RSS, structured data. → SEO field group + generators.
- No security headers, CSP, CSRF, rate limiting, upload safety. → Security module.
- No backend, no CRM, no integration boundary. → `breakfast-platform` plugin.
- Fonts loaded from Google (privacy + performance). → self-hosted, documented.

### Clean rebuild, not a migration

Kirby is **not** already installed. This is a clean rebuild that preserves identity and copy.
The static files are replaced; their content becomes seed content in Kirby.

## 2. Target architecture

Public-folder layout (docroot = `public/`, everything sensitive above it):

```
composer.json / composer.lock      Kirby 5.5.2 + dev tooling, versions locked
.env / .env.example                secrets (env only, never committed)
public/                            << web docroot >>
  index.php                        thin front controller, explicit roots
  .htaccess                        Apache rules + security headers
  assets/                          self-hosted css, js, fonts
  media/                           Kirby-generated responsive images (gitignored)
site/                              blueprints, templates, snippets, controllers, models, config
  plugins/breakfast-platform/      the operational platform (PSR-4, namespaced)
content/                           editorial content (Kirby content files)
storage/                           sessions, cache, logs, sqlite, private uploads, queue (gitignored)
kirby/  (vendor)                   Kirby core via Composer
bin/                               CLI (migrate, queue worker, hermes keys, seed)
tests/                             PHPUnit (unit + integration)
playwright/                        browser tests
docs/                              full documentation set
```

The `breakfast-platform` plugin is the spine. It owns:

- **Crm/** — SQLite persistence behind repositories (Contacts, Companies, Enquiries,
  Opportunities, Activities, Tasks). PDO, prepared statements, transactions, FK pragmas.
- **Forms/** — validation, sanitisation, CSRF, honeypot, timing, rate limiting, dedup, uploads.
- **Queue/** — durable SQLite job queue with leases, retries, idempotency.
- **Mail/** — templated internal + acknowledgement emails, dispatched via the queue.
- **Hermes/** — versioned API (`/api/breakfast/v1`), HMAC auth, scopes, webhooks, audit log.
- **Security/** — headers, CSP, rate-limit store, hashing, input helpers.
- **Seo/** — meta resolution, sitemap, RSS, structured data.
- **Analytics/** — pluggable, privacy-first analytics abstraction.
- **Support/** — DB connection, migrations runner, config, UUID, clock, logger.

### Key architectural decisions (recorded in `docs/architecture.md`)

1. **Public-folder docroot** for least-privilege file exposure. Documented for Nginx + Apache.
2. **SQLite via PDO behind repositories** so a future MySQL/Postgres move is a driver swap.
3. **Outbox/queue for all external side effects** — a valid enquiry is persisted before any email
   or webhook is attempted, and never lost if SMTP or Hermes is down.
4. **Hermes is an integration identity, not a Panel user** — HMAC-signed, scope-limited, draft-only.
5. **Composer installer plugin disabled** (sandbox), so Kirby loads from `vendor/` via explicit
   roots rather than a copied `./kirby` — documented so production can use either.

## 3. Build sequence

1. Skeleton, composer install, config (dev + production), env, gitignore. ✅
2. Platform Support layer: DB, migrations + runner, UUID, clock, logger, config. 
3. CRM schema migrations + repositories + services + pipeline rules.
4. Queue + Mail + outbox; CLI worker.
5. Forms pipeline (contact + start-a-project) wired to CRM + queue.
6. Hermes: HMAC auth, scopes, routes, webhooks, audit.
7. Security: headers, CSP, rate limiting; Panel roles/permissions.
8. Blueprints + content models for every page type; curated block system.
9. Templates, snippets, controllers; the design system CSS/JS (from existing tokens).
10. SEO: sitemap, robots, RSS, structured data, meta.
11. CRM Panel area (dashboard, lists, detail, pipeline, tasks).
12. Seed content for every page and component.
13. Tests (unit + integration + Playwright), PHPStan, CS-Fixer, ESLint.
14. Documentation set + deployment configs.

## 4. Definition of done

Tracked against section 32 of the brief. The build is complete when the site is genuinely
multi-page, Kirby controls all meaningful copy, both forms persist to the CRM before any external
delivery, the CRM Panel area works, permissions are enforced server-side, Hermes authenticates and
is scope-limited and draft-only, webhooks are signed/queued/retryable, no secrets are committed,
tests and static analysis pass, and the main journey is browser-tested.
