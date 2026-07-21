# Architecture

This document describes how Breakfast is put together and, at the end, records
the key architectural decisions and why they were made.

## The big picture

Breakfast is a single Kirby 5 application that serves two audiences:

- **Visitors** get a fast, multi-page marketing site rendered from editable
  Kirby content.
- **The studio** gets an operational platform — CRM, enquiry pipeline, job
  queue, email, integrations and reporting — implemented as one Kirby plugin,
  `breakfast-platform`.

Both share one PHP process and one codebase, but they are cleanly separated:
the public site is cacheable and stateless, while the platform owns all
persistence and side effects.

## Public-folder docroot

The web server's document root is **`public/`**, not the project root. Only
`public/index.php`, `public/assets/` and `public/media/` are reachable over
HTTP. Everything else — `content/`, `site/`, `storage/` (SQLite, sessions,
cache, logs, uploads, queue) and `vendor/` — lives one level up and cannot be
requested directly.

`public/index.php` is a thin front controller that boots Kirby with **every
root declared explicitly**:

```php
new Kirby([
    'roots' => [
        'index'    => __DIR__,          // public/
        'base'     => $base,            // project root
        'site'     => $base . '/site',
        'content'  => $base . '/content',
        'storage'  => $base . '/storage',
        'accounts' => $base . '/storage/accounts',
        'sessions' => $base . '/storage/sessions',
        'cache'    => $base . '/storage/cache',
        'logs'     => $base . '/storage/logs',
        'media'    => __DIR__ . '/media',
        'assets'   => __DIR__ . '/assets',
    ],
]);
```

Declaring the roots removes any ambiguity about where sensitive files live,
regardless of how the host resolves symlinks or aliases. See
[deployment.md](deployment.md) for the matching Nginx / Apache configuration.

## The `breakfast-platform` plugin

The plugin is PSR-4 autoloaded under `Breakfast\Platform\` and boots a single
service container (`Support\Platform`) once per request. The container is
constructed from the **non-secret** options Kirby resolves in
`site/config/config.php` plus secrets read straight from the environment, and
it lazily builds every service (database, repositories, queue, mailer, webhook
dispatcher, and so on).

### Module responsibilities

| Module | Responsibility |
|---|---|
| **Support** | The plumbing: `Platform` container, `Database` (PDO/SQLite wrapper), `Migrator` (forward-only SQL migrations), `Env` (.env loader), `Clock` (injectable time), `Logger` (redacting JSON-lines log), `Uuid`, `ReadingTime`, `Runtime` (per-request CSP nonce holder), `RetentionService` (one configurable data-retention policy across every table + orphaned files; read-only plan vs opt-in apply). |
| **ClientPreviews** | Static client mock-up hosting on an isolated origin: upload validation (`PreviewPathGuard`, `PreviewSvgSanitizer`, zip-slip/zip-bomb defence), immutable versioned storage outside the docroot, publishing/rollback, the serve-time responder, and — for host isolation — `PreviewHost` (slug↔host mapping + host validation) and `PreviewUrlGenerator` (per-preview subdomain / single-host / dev-prefix URL modes). Access events store keyed hashes only. |
| **Admin** | The branded Breakfast Admin surface: `DashboardData` (safe, non-secret read-only summaries) behind the relocated Panel slug, plus the previews/ops Panel API. |
| **Crm** | SQLite persistence behind repositories (`Contact`, `Company`, `Enquiry`, `Opportunity`, `Task`, `Activity`) and a `Crm` service that coordinates them, enforces pipeline rules and writes the immutable activity log. |
| **Forms** | The submission pipeline: `Validator`, `Sanitizer`, `SubmissionGuard` (honeypot, timing, rate limiting, duplicate detection), `UploadHandler`, and `FormProcessor` which ties it all together. |
| **Queue** | Durable, SQLite-backed outbox (`Queue`, `Job`, `Worker`) with leases, retries with backoff and idempotency keys. |
| **Mail** | Provider-agnostic transactional email: a `MailMessage` value object, a `MailProvider` boundary (Brevo API / SMTP / Fake) selected by `MailProviderFactory`, `MailService` (queue + suppression + tracking), the `OutboundMessageRepository`, `SuppressionService`, `TemplateRegistry`, the enquiry/CRM message factories and the `BrevoWebhook` receiver. Every send is dispatched via the queue. See "Transactional email (Brevo)" below. |
| **Hermes** | The integration boundary: a versioned, HMAC-authenticated API (`Api`, `Authenticator`, `Signer`, `Scopes`, `Credential(Store)`), the outbound `WebhookDispatcher`, `DraftFactory` and the `AuditLog`. |
| **Security** | `SecurityHeaders` (CSP + headers), `RateLimiter`, `Hash` (keyed IP hashing), `PanelGate` (server-side CRM permission checks). |
| **Seo** | `Meta` (per-page metadata resolution with fallbacks) and `StructuredData`; sitemap / robots / RSS are served from plugin routes. |
| **Analytics** | Privacy-first, pluggable analytics abstraction (none / Plausible / GA4) that also declares the CSP hosts it needs. |

### What the plugin registers

`site/plugins/breakfast-platform/index.php` wires the plugin into Kirby:

- **Front-end routes:** the Hermes API (`api/breakfast/v1/(:all?)`, matched
  before Kirby's own API), `sitemap.xml`, `robots.txt`, `journal/feed.rss`, a
  permission-guarded upload download route (`admin/download/upload/(:any)`) and
  the client-preview dispatcher — a catch-all that, per request, serves the
  preview responder when the `Host`/path identifies a preview origin and
  otherwise falls through (`Route::next()`) to the normal site.
- **Panel API routes** (`api/crm.php`) backing the CRM Vue views — each one
  re-checks the CRM permission server-side.
- **A custom Panel area** (`areas/crm.php`): the CRM dashboard, enquiries,
  contacts, pipeline and tasks.
- **Page/site/field methods:** `seoMeta()`, `readingTime()`, `analytics()`,
  `excerptSafe()`.
- **Hooks:** publishing or updating a listed page dispatches
  `content.published` / `content.updated` webhooks.

## The outbox / queue pattern

The single most important behavioural rule in the platform:

> **A valid enquiry is persisted locally before any external side effect is
> attempted, and side effects are only ever _enqueued_, never performed inline.**

In `Forms\FormProcessor::process()` the ordering is deliberate:

1. CSRF check.
2. Bot signals (honeypot, timing) — a caught bot gets a benign "success".
3. Server-side validation.
4. Rate limiting (per IP and per email).
5. Duplicate detection.
6. **Persist** the company, contact, enquiry and immutable activity records in
   one database transaction.
7. **Enqueue** the side effects: internal notification email, visitor
   acknowledgement email, and the `enquiry.created` webhook fan-out.

Because steps 6 and 7 are both local SQLite writes, a temporarily unavailable
SMTP server or webhook endpoint can never lose a lead. The queue worker
(`Queue\Worker`, run from the CLI or cron) later performs the actual delivery
with retries and exponential backoff. See [operations.md](operations.md).

## SQLite behind repositories

All operational data lives in a single SQLite database
(`storage/database/crm.sqlite`, outside the docroot). Every table is reached
only through a repository that extends `Crm\Repository` or through the
`Support\Database` wrapper, which:

- forces `PRAGMA foreign_keys = ON`, WAL journalling and a busy timeout;
- exposes only prepared-statement helpers (`run`, `one`, `all`, `scalar`,
  `transaction`) so callers cannot interpolate values into SQL;
- whitelists updatable columns in each repository's `assignments()` helper.

SQLite is a deliberate choice for a small studio: zero operational overhead,
transactional integrity and easy backups. Because all access is funnelled
through repositories, moving to MySQL or Postgres later is a driver swap plus
dialect adjustments, not an application rewrite.

## Hermes as an integration identity

Hermes is the studio's automation/integration counterpart. It is **not** a
Kirby Panel user and does **not** use Kirby's REST API or KQL. Instead it talks
to a dedicated, versioned surface (`/api/breakfast/v1/...`) where:

- every request is authenticated with an HMAC-SHA256 signature over a canonical
  string (method, path, timestamp, nonce, body hash);
- every endpoint declares and enforces exactly one scope;
- every call is written to an immutable audit trail;
- writes are strictly limited: Hermes can create **unpublished drafts** and add
  CRM notes / tasks / classifications, but can never publish, delete, touch
  users or permissions, read secrets, or export the full CRM (that needs the
  privileged `crm:export` scope).

Full detail in [hermes-integration.md](hermes-integration.md).

## CSP and the request nonce

`Security\SecurityHeaders` builds a nonce-based Content Security Policy. On each
request a fresh random nonce is generated (held in `Support\Runtime` so the
response header and any inline `<script>` tags agree on it). The script policy
is `'self'` plus `'nonce-…'` — there is no `'unsafe-inline'` for scripts and no
`'unsafe-eval'` anywhere. The small amount of inline bootstrap script the site
needs (and any analytics snippet) carries the nonce. See
[security.md](security.md) for the full directive list.

## Transactional email (Brevo)

Transactional email is sent through [Brevo](https://www.brevo.com) (the artist
formerly known as Sendinblue), but the application is never coupled to it.

### The decision: a hand-rolled typed client, not the official SDK

We deliberately did **not** use the official `getbrevo/brevo-php` SDK. Instead
the platform ships a small, typed HTTP client (`Mail\HttpClient` +
`Mail\HttpResponse`) and a purpose-built `Mail\BrevoApiMailProvider` that speaks
directly to the one documented endpoint we need — `POST /v3/smtp/email`.

Why:

- **Weight.** The SDK pulls a heavy tree of transitive dependencies (a full
  Guzzle stack and friends) for what is, in our case, a handful of JSON `POST`s.
- **Testability.** Our `HttpClient` exposes a transport seam
  (`HttpClient::useTransport()`) so tests can script exact HTTP responses with
  no network — the SDK's client is far harder to fake cleanly.
- **Sandbox reality.** The SDK could not be installed in the build sandbox at
  all; a dependency-free curl client can.

The trade-off is that we own the request/response mapping for the few endpoints
we call (send, `GET /account` health check, and the webhook/contact admin calls
in `BrevoAdmin`). That surface is tiny and fully covered by unit tests.

### Layering

Nothing in the application talks to Brevo directly. The flow is strictly
one-directional through the provider boundary:

```
application workflow (form pipeline / CRM email command)
        │  builds a
        ▼
Mail\MailMessage            provider-agnostic value object (CRM correlation
        │  handed to        UUIDs, subject, html/text OR template id + params)
        ▼
Mail\MailService::queue()   persists an outbound_messages row, then pushes a
        │                   durable `mail.send` queue job — never sends inline
        ▼
Queue\Worker → MailService::deliver()
        │  calls
        ▼
Mail\MailProvider  ── BrevoApiMailProvider | SmtpMailProvider | FakeMailProvider
```

`MailProviderFactory::make()` chooses the implementation from `MAIL_PROVIDER`.
In development and tests the factory returns `FakeMailProvider` (and does so
even if `brevo` is selected without an API key) so **no real email is ever sent
by accident**. In production, selecting `brevo` without `BREVO_API_KEY` is a
hard error. Templates, controllers, the Panel, Hermes and the public form
handlers all go through `MailService`; none of them constructs a provider or
holds the API key.

### The provider-result model

`MailProvider::send()` returns a `Mail\MailResult`, never a bare boolean and
never a generic exception for expected failures. The result carries one of four
outcomes (`Mail\MailOutcome`):

| Outcome | Meaning | Brevo HTTP shape | Queue behaviour |
|---|---|---|---|
| `Accepted` | Brevo accepted the message for delivery | 2xx (`messageId` captured) | job completes; `outbound_messages.status = accepted` |
| `TemporaryFailure` | transient, safe to retry | `429` or `5xx` | job throws → retried with backoff |
| `PermanentFailure` | client error, retrying is pointless | other `4xx` | job **completes**; status `permanent_failure` for an operator |
| `UnknownOutcome` | we don't know if Brevo received it (timeout/DNS) | transport error (`status 0`) | treated as retryable-with-care |

Failures are **not** collapsed into a single exception because the worker has to
make different decisions for each case: retry a `5xx`, give up on a `4xx`
without pointlessly hammering the API, and re-attempt an unknown transport
failure idempotently (the durable outbox and idempotency key make a
double-attempt safe). Reducing everything to "it threw" would lose exactly the
information the queue needs.

### Delivery events and suppression

Brevo reports what happened after acceptance (delivered, bounced, blocked,
complaint, …) to an independently-authenticated webhook
(`Mail\BrevoWebhook`, route `POST /api/breakfast/v1/webhooks/brevo`). Events are
deduplicated, drive a terminal-safe delivery-state machine on
`outbound_messages`, and feed the `SuppressionService`. See
[crm.md](crm.md) for the state machine and marketing-vs-transactional
suppression, and [hermes-integration.md](hermes-integration.md) for why the
webhook's authentication is separate from Hermes.

## Key architectural decisions

1. **Public-folder docroot.** The web root is `public/`; content, config, the
   SQLite database, storage and `vendor/` all live above it and are
   unreachable over HTTP. Roots are declared explicitly in the front
   controller. This gives least-privilege file exposure and an unambiguous
   layout across hosts.

2. **SQLite via PDO behind repositories.** All persistence goes through a thin
   PDO wrapper and a repository per entity, with prepared statements,
   transactions and foreign-key pragmas. A future move to MySQL/Postgres
   becomes a driver swap rather than an application rewrite.

3. **Outbox / queue for all external side effects.** A valid enquiry is written
   to the CRM (with an immutable activity record) before any email or webhook
   is attempted. Side effects are enqueued as durable jobs and delivered by a
   worker with retries and backoff, so a lead is never lost to a flaky SMTP
   server or endpoint.

4. **Hermes is an HMAC boundary, not a Panel user.** Integrations authenticate
   with a signed, replay-protected request against a scope-limited, draft-only,
   fully audited API — never with a Panel session, Kirby's REST API or KQL.

5. **Composer installer plugin disabled; Kirby loaded from `vendor/`.**
   `getkirby/composer-installer` is disabled in `composer.json`
   (`allow-plugins`), so Kirby core is not copied into a top-level `./kirby`
   directory. Instead the front controller loads it from `vendor/` via the
   explicit roots. This keeps the sandbox/install deterministic; production may
   use either arrangement (documented in [deployment.md](deployment.md)).

6. **Hand-rolled Brevo client behind a provider boundary, not the official
   SDK.** Transactional email goes through a `MailProvider` interface (Brevo /
   SMTP / Fake) fed by a durable `mail.send` queue job; the Brevo implementation
   is a small typed HTTP client against `POST /v3/smtp/email`, chosen over the
   official Brevo PHP SDK because the SDK pulls heavy transitive dependencies, is
   hard to fake in tests, and could not be installed in the build sandbox. The
   provider returns a four-state `MailResult` (Accepted / TemporaryFailure /
   PermanentFailure / UnknownOutcome) rather than a boolean or a generic
   exception, so the queue can retry, give up or re-attempt idempotently as
   appropriate. No template, controller, Panel view, Hermes endpoint or public
   handler ever calls Brevo directly.

7. **Client previews get one browser origin each.** The preferred production mode
   serves every preview from its own host `{slug}.preview.<base>` derived from
   the slug, so the browser same-origin policy — not just cookie scoping —
   isolates previews from each other and from the admin/main site. The preview
   host is decided **per request** (from the `Host` header) rather than at plugin
   load, since a plugin's `index.php` runs once per worker and freezes any
   load-time host decision. `PreviewHost` validates the incoming host (single
   valid slug label only) and a stale slug 301-redirects to its canonical host;
   hostnames need no per-preview DNS/cert/DB change. Single-shared-host and
   dev-path-prefix modes remain as weaker fallbacks. See
   [client-preview-security.md](client-preview-security.md).

8. **One retention policy, read-only by default.** Every "delete old rows /
   orphaned files" concern is consolidated in `RetentionService` with a single
   config source and a strict split between a read-only `plan()` (backing
   `retention:check` / `--dry-run`) and an opt-in `run(apply: true)`
   (`--confirm`). A window of `0` disables a category outright, so no data is
   ever deleted unexpectedly and history can never be wiped by a blank value.
