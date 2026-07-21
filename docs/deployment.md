# Deployment

How to deploy Breakfast to conventional PHP hosting. The site is a standard
Kirby 5 application with a `public/` docroot; any host that can run PHP-FPM
behind Nginx, or PHP under Apache, will work.

The web-server configuration files themselves — `deploy/nginx.conf.example`,
`deploy/apache.conf.example` and `public/.htaccess` — are maintained alongside
this document; the blocks below show what they must contain.

## Requirements

- PHP **8.3+** with `pdo_sqlite`, `mbstring`, `gd` (or `imagick`), `dom` and
  `curl`; `intl` and `fileinfo` are also used. `curl` is required for Brevo and
  outbound webhooks.
- Composer (for the build step).
- HTTPS (a valid certificate). Production cookies, HSTS and the Brevo webhook
  all assume TLS.
- A writable `storage/` tree and a way to run the queue (cron or a supervised
  worker).
- A [Brevo](https://www.brevo.com) account with a **verified sending domain**
  and an API key, if `MAIL_PROVIDER=brevo` (see "Brevo setup" below).

The example server, cron and worker configs referenced throughout live under
[`deploy/`](../deploy): `deploy/nginx.conf.example`,
`deploy/apache.conf.example`, `deploy/permissions.sh.example`,
`deploy/cron.example` and `deploy/queue-worker.service.example`.

## Production deployment checklist

Work top to bottom for a fresh production deploy; each item is expanded in a
section below or in the linked document.

- [ ] **PHP 8.3+** with `pdo_sqlite`, `mbstring`, `gd`, `dom`, `curl` (+ `intl`,
      `fileinfo`).
- [ ] Web server **docroot points at `public/`**; everything else is above it.
- [ ] **Environment / `.env`** provided and not web-readable (see below).
- [ ] **SQLite path** `storage/database/crm.sqlite` (outside the docroot,
      `CRM_DB_PATH`).
- [ ] **Backup path** chosen and a pre-deploy backup taken — see
      [backups.md](backups.md).
- [ ] **Directory permissions** applied — `bash deploy/permissions.sh.example`
      (storage writable, code read-only, `.env` `0640`).
- [ ] **Queue runner** installed — per-minute `queue:run` cron
      (`deploy/cron.example`) **or** a supervised `queue:work`
      (`deploy/queue-worker.service.example`), not both.
- [ ] **HTTPS** certificate valid; HTTP → HTTPS redirect in place.
- [ ] **Kirby licence** set (`KIRBY_LICENSE`).
- [ ] **Brevo** domain verified, API key set, sender chosen, templates recorded,
      webhook registered — see "Brevo setup" below.
- [ ] **Hermes** credentials configured **only if used** (`HERMES_ENABLED=true`,
      `HERMES_KEY_*`) — see [hermes-integration.md](hermes-integration.md).
- [ ] **Migrations** applied — `php bin/console migrate` (then `migrate:status`).
- [ ] **Cache cleared** — remove `storage/cache/*` on deploy so the page cache
      rebuilds against the new release.
- [ ] **Smoke tests** green — `php bin/console app:check` and
      `php bin/console mail:check` (add `--connect` once Brevo is configured).
- [ ] **Rollback** plan confirmed (atomic releases; see "Rollback" below).
- [ ] **Backup restoration** rehearsed — see [restore.md](restore.md).

## The docroot MUST be `public/`

This is the single most important deployment rule. Point the web server's
document root at the `public/` directory. Everything else — `content/`,
`site/`, `storage/` (SQLite, sessions, cache, logs, uploads, queue) and
`vendor/` — lives **above** the docroot and must never be served.

### Nginx (PHP-FPM)

```nginx
server {
    listen 443 ssl http2;
    server_name breakfast.example;

    # Docroot is public/, nothing above it.
    root /var/www/breakfast/public;
    index index.php;

    # ssl_certificate / ssl_certificate_key ...

    client_max_body_size 6m;   # a little above UPLOADS_MAX_BYTES

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # Never serve dotfiles or Kirby content/text files.
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(txt|md|yml|yaml|sqlite|sqlite-.*)$ { deny all; }

    # Long-cache fingerprinted assets.
    location /assets/ { try_files $uri =404; expires 30d; access_log off; }
    location /media/  { try_files $uri =404; expires 30d; access_log off; }
}

# Redirect HTTP → HTTPS.
server {
    listen 80;
    server_name breakfast.example;
    return 301 https://$host$request_uri;
}
```

Because the sensitive directories are physically above the docroot, they are
already unreachable; the `deny` rules are defence in depth.

### Apache (`public/.htaccess`)

If the host document root can point at `public/`, do that. Where it cannot,
`public/.htaccess` provides the rewrite to the front controller and denies
sensitive files:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Block direct access to Kirby internals if the docroot is misconfigured.
    RewriteRule ^(content|site|storage|kirby|vendor)/ - [F,L]

    # Front controller.
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

# Never serve dotfiles, databases or content text files.
<FilesMatch "^\.|\.(sqlite|sqlite-.*|md|yml|yaml)$">
    Require all denied
</FilesMatch>
```

## Build

Install production dependencies (no dev tooling on the server):

```bash
composer install --no-dev --optimize-autoloader
```

## Environment

Provide configuration via real environment variables (preferred) or a `.env`
file at the project root that is **not** web-readable. At minimum, for
production:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://breakfast.example
APP_VERSION=<deploy id>
KIRBY_LICENSE=<your licence>
CRM_DB_PATH=storage/database/crm.sqlite

MAIL_PROVIDER=brevo          # brevo | smtp | fake | null
MAIL_FROM=studio@breakfast.example
MAIL_FROM_NAME=Breakfast
MAIL_ENQUIRIES_TO=studio@breakfast.example

# Brevo (only used when MAIL_PROVIDER=brevo). Keys are ENV-ONLY, never in Kirby.
BREVO_ENABLED=true
BREVO_API_KEY=<brevo api key>
BREVO_SENDER_EMAIL=hello@breakfast.example    # must be a VERIFIED sender
BREVO_SENDER_NAME=Breakfast
BREVO_REPLY_TO_EMAIL=hello@breakfast.example
BREVO_REPLY_TO_NAME=Breakfast
BREVO_WEBHOOK_SECRET=<random>                 # sent as X-Breakfast-Webhook-Token
# Optional Brevo-hosted template IDs (blank = the app renders HTML + text):
# BREVO_INTERNAL_ENQUIRY_TEMPLATE_ID= / BREVO_ENQUIRY_ACK_TEMPLATE_ID= / ...
# Tracking is OFF by default; enable deliberately:
# BREVO_TRACK_OPENS=false / BREVO_TRACK_CLICKS=false

# SMTP fallback (only used when MAIL_PROVIDER=smtp):
# MAIL_TRANSPORT=smtp / MAIL_HOST=... / MAIL_PORT=587 / MAIL_SECURITY=tls
# MAIL_USERNAME=... / MAIL_PASSWORD=...

WEBHOOK_SIGNING_SECRET=<random>
# HERMES_ENABLED=true and HERMES_KEY_* only if the integration is used.
```

See [`.env.example`](../.env.example) for the complete, commented list of
`MAIL_*` and `BREVO_*` variables.

`APP_ENV=production` turns off debug, disables the Panel installer and enables
the page cache.

## File permissions

```bash
# Application code — readable, not writable by the web user.
# storage/ must be writable by the PHP process (adjust owner/group to yours).
chown -R www-data:www-data storage
find storage -type d -exec chmod 0770 {} \;
find storage -type f -exec chmod 0660 {} \;

# Secrets are owner-read-only.
chmod 0600 .env
```

The database directory and uploads directory are created `0770` by the
application if missing; uploaded files are written `0640` (non-executable).

## Migrations

Create or update the schema before serving traffic. Migrations never run during
a web request:

```bash
php bin/console migrate
php bin/console migrate:status   # confirm all applied
```

## First admin

With `APP_ENV=production` the installer is disabled, so create the first admin
from the CLI (or temporarily set `APP_ENV=development`, create the admin at the
admin slug — `/breakfast-admin` by default, set via `BREAKFAST_ADMIN_SLUG` — then
switch back). Admin (Panel) user accounts are stored under `storage/accounts/`
and must never be committed. The old `/panel` address is retired and returns 404.

## The queue

Side effects (emails, webhooks) are delivered by the queue worker, not during
the request. Run it one of two ways:

**Cron** — a single bounded pass each minute (simplest, resilient):

```cron
* * * * * php /var/www/breakfast/bin/console queue:run >> /var/www/breakfast/storage/logs/queue-cron.log 2>&1
```

**Supervised worker** — a long-running process for lower latency, under
systemd or supervisor:

```bash
php /var/www/breakfast/bin/console queue:work
```

Use one approach or the other. If you run a supervised `queue:work`, you do not
also need the cron `queue:run` (leases make concurrent workers safe, but there
is no need). See [operations.md](operations.md) for monitoring.

Also install the nightly maintenance crons from `deploy/cron.example`:
`retention:cleanup --confirm` (configurable data retention across CRM,
preview-analytics, queue/webhook/email/audit history and orphaned files —
windows via `RETENTION_*_DAYS`, `0` disables), `privacy:cleanup --confirm`
(GDPR housekeeping) and `previews:cleanup --confirm` (preview expiry + version
cap). See [operations.md](operations.md#data-retention-retention).

## Client previews (separate origin)

Client previews are served from their own origin so no admin cookie is ever in
scope. In production, prefer **one origin per preview** via a wildcard host:
set `CLIENT_PREVIEW_WILDCARD_BASE`, add a wildcard DNS record and a wildcard TLS
certificate (DNS-01), and use the `deploy/nginx-preview.conf.example` /
`deploy/apache-preview.conf.example` vhosts. Existing previews migrate
automatically — hostnames derive from the slug, with no DB edit. Full steps:
[client-preview-deployment.md](client-preview-deployment.md).

## Cache and smoke tests

Clear the page cache on each deploy so it rebuilds against the new release:

```bash
rm -rf storage/cache/*
```

Then run the boot/render and mail smoke checks (both print no secrets and exit
non-zero on failure):

```bash
php bin/console app:check           # boots Kirby + platform, renders key routes
php bin/console mail:check          # validates mail configuration
php bin/console mail:check --connect  # + a Brevo GET /account auth probe
```

## Brevo setup

Transactional email is delivered by Brevo when `MAIL_PROVIDER=brevo`. **None of
the DNS or Brevo-side configuration below is done by this repository** — it must
be carried out in your DNS zone and in the Brevo dashboard, and then verified.
Work through this pre-launch checklist; the CLI commands are real and safe to
run repeatedly.

### Deliverability (DNS) — verify, do not assume

- [ ] **Add the sending domain** in Brevo (Senders, Domains & Dedicated IPs →
      Domains) and start its verification.
- [ ] **SPF** — publish/confirm the TXT record Brevo asks for so Brevo is an
      authorised sender for the domain.
- [ ] **DKIM** — publish the DKIM record(s) Brevo generates and confirm they
      show as verified in the dashboard.
- [ ] **DMARC** — publish a DMARC policy (start at `p=none` with a reporting
      address, tighten to `quarantine`/`reject` once traffic is clean).
- [ ] Confirm the domain shows **fully verified/authenticated** in Brevo before
      sending real mail. (Verify with `dig TXT`/`dig CNAME` and the dashboard —
      do not assume propagation.)

### Senders and templates

- [ ] **Choose a verified sender** and set `BREVO_SENDER_EMAIL` /
      `BREVO_SENDER_NAME` to it. Sending as an unverified address will be
      rejected or land in spam.
- [ ] **Configure the reply-to** (`BREVO_REPLY_TO_EMAIL` /
      `BREVO_REPLY_TO_NAME`). (Note: the enquiry internal-notification email sets
      Reply-To to the *submitter* when their address is valid — see
      `EnquiryMailFactory`.)
- [ ] **Create the transactional templates** you want Brevo to render and record
      each numeric template id into the matching env var:
      `BREVO_INTERNAL_ENQUIRY_TEMPLATE_ID`, `BREVO_ENQUIRY_ACK_TEMPLATE_ID`,
      `BREVO_CRM_EMAIL_TEMPLATE_ID`, `BREVO_TASK_REMINDER_TEMPLATE_ID`,
      `BREVO_DAILY_DIGEST_TEMPLATE_ID`, `BREVO_FAILED_JOB_TEMPLATE_ID`.
      **A blank template id is fine** — the app renders its own HTML + plain-text
      body for that message type. Confirm the mapping with
      `php bin/console brevo:templates:check`.

### Webhook

- [ ] **Create the webhook** pointing at
      `https://<host>/api/breakfast/v1/webhooks/brevo`. Register it from the CLI
      (idempotent — an existing webhook for the same URL is reused):
      `php bin/console brevo:webhook:register --confirm`, then confirm with
      `php bin/console brevo:webhook:status`.
- [ ] **Configure webhook authentication.** The receiver **fails closed** — with
      no auth configured every webhook is rejected `401`. Use either:
    - a **shared secret**: set `BREVO_WEBHOOK_SECRET` and have Brevo send it as
      the `X-Breakfast-Webhook-Token` request header (preferred); or
    - **HTTP Basic**: set `BREVO_WEBHOOK_BASIC_AUTH_USER` /
      `BREVO_WEBHOOK_BASIC_AUTH_PASSWORD` and configure Basic auth on the Brevo
      webhook.

### Test and go live

- [ ] **Test delivery**: `php bin/console mail:test --to=<addr> --confirm`
      queues a real message, runs the worker once, and reports the outbound
      status (expects `accepted`). Omitting `--to` or `--confirm` refuses to
      send.
- [ ] **Test bounce handling**: send to a known-invalid address (Brevo provides
      test/seed addresses) and confirm a `hard_bounce`/`invalid_email` webhook
      arrives, the outbound row moves to a terminal negative state, and a
      **transactional suppression** is created
      (`php bin/console brevo:events:reconcile` shows the local event count).
- [ ] **Move from sandbox to production** in Brevo (leave any test/sandbox mode)
      once delivery, tracking defaults and bounce handling all look correct.

### Key hygiene

- [ ] **Rotate the API key** on a schedule: create a new key in Brevo, update
      `BREVO_API_KEY`, reload the environment, confirm with
      `php bin/console mail:check --connect`, then delete the old key in Brevo.
- [ ] **Revoke a compromised key immediately** in the Brevo dashboard, issue a
      replacement, and update `BREVO_API_KEY`. Because the key lives only in the
      environment, rotation is a config change and a restart — no code deploy.
- [ ] **Review Brevo IP authorisation** — if IP allow-listing is enabled on the
      Brevo account, add the production egress IP(s) so API calls are not
      rejected.

The API key is read only from the environment; it is never written to Kirby
content, the Panel, logs or the outbound record. `mail:check`, `app:check` and
every command above print no secrets.

## How `config.production.php` is used

`site/config/config.php` holds the base/development defaults and reads the
environment; `site/config/config.production.php` hardens them (`debug => false`,
Panel installer off, HTTPS cookies, page cache on). Kirby loads the
host-specific file for a production deployment (map it to the production
hostname per Kirby's multi-environment config convention). Setting
`APP_ENV=production` also flips the relevant toggles in the base config, so the
two are consistent.

## Updating Kirby safely

```bash
composer update getkirby/cms      # update only Kirby
composer test && composer stan    # run the suite
# deploy the updated vendor/ + composer.lock, then:
php bin/console migrate:status    # confirm no surprises
```

Never edit Kirby core in `vendor/`. Because the installer plugin is disabled,
Kirby is loaded from `vendor/` via the explicit roots in `public/index.php`;
production may instead vendor Kirby into a top-level `./kirby` if preferred —
either arrangement works because the roots are declared.

## Rollback

Deploys should be atomic (e.g. symlinked release directories). To roll back:

1. Repoint the `current` symlink (or redeploy the previous artefact).
2. If the rolled-back release predates a migration, restore the database from
   the backup taken **before** that migration ran (see [restore.md](restore.md))
   — SQLite migrations here are forward-only.
3. Bump `APP_VERSION` so the health endpoint reflects the running release.

Always take a fresh backup immediately before deploying (see
[backups.md](backups.md)).
