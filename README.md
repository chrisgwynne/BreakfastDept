# Breakfast

Breakfast is a small British web studio. This repository holds the studio's
website **and** its operational platform: a [Kirby 5](https://getkirby.com)
site backed by a bespoke plugin that provides a CRM, a validated enquiry
pipeline, a durable job queue, transactional email, an HMAC-signed integration
API (Hermes) and a hardened security layer.

It is a clean rebuild of an older single-page static site. The brand, copy and
visual identity are preserved; everything else — content models, forms, CRM,
integrations, SEO and security — is new.

## Tech stack

| Concern | Choice |
|---|---|
| CMS / framework | Kirby 5.5.2 |
| Language | PHP 8.3+ |
| Data store | SQLite via PDO (behind repositories) |
| Transactional email | Brevo API (typed client) / SMTP / Fake, via `MAIL_PROVIDER` |
| Unit / integration tests | PHPUnit 11 |
| Static analysis | PHPStan 2 |
| Code style | PHP-CS-Fixer 3 |
| Browser tests | Playwright |

## Repository layout

The web docroot is **`public/`**. Everything sensitive lives above it and is
never directly reachable over HTTP.

```
composer.json / composer.lock   Kirby + dev tooling, versions locked
.env / .env.example             configuration & secrets (env only, never committed)
public/                         << web docroot >>
  index.php                     thin front controller, roots declared explicitly
  assets/                       self-hosted css / js / fonts
  media/                        Kirby-generated responsive images (gitignored)
site/                           blueprints, templates, snippets, config
  config/config.php             base + development config
  config/config.production.php  production hardening overrides
  plugins/breakfast-platform/   the operational platform (PSR-4, namespaced)
content/                        editorial content (Kirby content files)
storage/                        sessions, cache, logs, sqlite, uploads, queue (gitignored)
vendor/                         Composer dependencies, incl. Kirby core
bin/console                     CLI: migrate, queue worker, hermes keys, seed
tests/                          PHPUnit (unit + integration)
playwright/                     browser tests
docs/                           the documentation set
```

The `breakfast-platform` plugin is the spine of the operational side. Its
modules are: **Support** (DB, migrations, config, clock, logger), **Crm**,
**Forms**, **Queue**, **Mail**, **Hermes**, **Security**, **Seo** and
**Analytics**. See [docs/architecture.md](docs/architecture.md).

## Quick start (local development)

Requires PHP 8.3+ (with the `pdo_sqlite`, `mbstring`, `gd`, `dom` and `curl`
extensions), Composer, and — for the browser tests — Node.js. The steps below
are exact and copy-pasteable.

```bash
# 1. Clone
git clone <this-repo-url> breakfast && cd breakfast

# 2. Install PHP dependencies (Kirby core lands in vendor/)
composer install

# 3. Create your local configuration
cp .env.example .env

# 4. Configure development values.
#    Edit .env — at minimum set APP_URL=http://localhost:8000.
#    MAIL_PROVIDER defaults to `fake` in development, so NO real email is sent.
#    Leave HERMES_ENABLED=false and BREVO_* blank to start.

# 5. Create the required runtime directories.
#    They already exist as .gitkeep placeholders under storage/; storage/ and
#    everything under it must be WRITABLE by the PHP process:
mkdir -p storage/{cache,logs,sessions,database,uploads,queue,accounts}

# 6. Set permissions.
#    In development the mkdir above is enough. For a production-style layout use:
bash deploy/permissions.sh.example      # storage writable, code read-only, .env 0640

# 7. Create the SQLite schema
php bin/console migrate

# 8. Create the first Breakfast Admin administrator.
#    Visit http://localhost:8000/breakfast-admin and follow the installer.
#    (Admin lives at the private BREAKFAST_ADMIN_SLUG, default "breakfast-admin";
#     the old /panel path returns 404. The installer runs only when
#     APP_ENV != production; it is enabled in dev.)

# 9. Serve the public/ docroot
php -S localhost:8000 -t public

# 10. Run the queue worker so emails/webhooks are actually delivered.
#     Either a single bounded pass (cron-style)…
php bin/console queue:run
#     …or a long-running supervised worker:
php bin/console queue:work
```

Optionally seed demonstration content and CRM records with
`php bin/console seed`.

### Tests and checks

```bash
# 11. Run all tests and quality gates.
composer test          # PHPUnit (unit + integration)
composer stan          # PHPStan static analysis
composer cs-check      # PHP-CS-Fixer, dry-run + diff (composer fix applies)
npm run lint           # ESLint over the front-end + Panel JS
npx playwright test    # browser journey (start the app first — step 9)
#     In this sandbox PHPUnit is also runnable directly:
php tools/phpunit.phar

# 12. Production build check (no dev deps, then boot + route + config verify).
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/console app:check
```

### Going live with Brevo

```bash
# 13. Configure Brevo. In .env set:
#       MAIL_PROVIDER=brevo
#       BREVO_API_KEY=<key>
#       BREVO_SENDER_EMAIL=<verified sender>  (and BREVO_SENDER_NAME)
#     Then validate configuration and connectivity (prints no secrets):
php bin/console mail:check --connect

# 14. Send a real test email (refuses without both --to and --confirm):
php bin/console mail:test --to=you@example.com --confirm

# 15. Register and verify the Brevo webhook (delivery/bounce events):
php bin/console brevo:webhook:register --confirm
php bin/console brevo:webhook:status
```

See [docs/deployment.md](docs/deployment.md) for the full Brevo pre-launch
checklist (SPF/DKIM/DMARC, templates, webhook auth, key rotation).

## Everyday commands

```bash
composer test     # PHPUnit
composer stan     # PHPStan static analysis
composer cs-check # PHP-CS-Fixer (dry-run + diff); composer fix to apply
npm run lint      # ESLint

php bin/console migrate          # apply pending migrations
php bin/console migrate:status   # show applied / pending migrations
php bin/console queue:run        # process the queue once (cron-friendly)
php bin/console queue:work       # long-running supervised worker
php bin/console app:check        # boot + route + config smoke check
php bin/console mail:check       # validate mail config (--connect probes Brevo)
php bin/console hermes:keys      # manage Hermes integration credentials
```

## Documentation

| Document | Covers |
|---|---|
| [architecture.md](docs/architecture.md) | Big picture, modules, key decisions |
| [breakfast-admin.md](docs/breakfast-admin.md) | The branded admin: private slug, nav, dashboard |
| [client-previews.md](docs/client-previews.md) | Secure static mock-up hosting on a separate origin |
| [client-preview-security.md](docs/client-preview-security.md) | Preview upload/serve security model |
| [client-preview-deployment.md](docs/client-preview-deployment.md) | Preview host DNS/TLS/web-server setup |
| [crm.md](docs/crm.md) | CRM entities, pipeline, permissions, GDPR |
| [hermes-integration.md](docs/hermes-integration.md) | The signed integration API + webhooks |
| [security.md](docs/security.md) | Security controls actually implemented |
| [deployment.md](docs/deployment.md) | Deploying to PHP-FPM/Nginx or Apache |
| [backups.md](docs/backups.md) | What to back up and how |
| [restore.md](docs/restore.md) | Restore and restore-test procedures |
| [operations.md](docs/operations.md) | Day-to-day running of the platform |
| [testing.md](docs/testing.md) | Running and understanding the test suite |
| [manual-release-checklist.md](docs/manual-release-checklist.md) | Manual pre-release QA checklist (site, Panel, CRM, Brevo, Hermes, ops) |
| [editor-guide.md](docs/editor-guide.md) | For non-technical content editors |
| [content-model.md](docs/content-model.md) | The field contract for every page type |

## Licence

Proprietary. Kirby itself requires a licence for production use — buy one at
[getkirby.com](https://getkirby.com) and set `KIRBY_LICENSE` in the environment.
