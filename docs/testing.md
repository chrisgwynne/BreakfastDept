# Testing

The build is only "done" when the full suite is green: unit + integration tests,
static analysis, code style and the browser journey. This document explains how
to run each and what they cover.

## Running the tests

### PHPUnit (unit + integration)

```bash
composer test
# or directly:
vendor/bin/phpunit
```

In some sandboxes Composer's dev dependencies are not installed and a bundled
PHPUnit phar is used instead:

```bash
php tools/phpunit.phar
```

Both run the same test suite under `tests/`.

### PHPStan (static analysis)

```bash
composer stan
# or:
vendor/bin/phpstan analyse
```

### PHP-CS-Fixer (code style)

```bash
composer cs-check     # dry-run + diff, changes nothing (CI-friendly)
composer fix          # apply fixes
```

### Playwright (browser tests)

```bash
npx playwright test
```

Playwright drives a running instance of the site, so start the app first
(`php -S localhost:8000 -t public` — the flat-file store needs no setup) or point
the Playwright config at your local URL.

### CLI smoke checks

Two console commands double as fast, secret-free smoke tests and are worth
running in CI and before a release:

```bash
php bin/console app:check     # boots Kirby + platform, renders key routes,
                              # confirms the flat-file store is writable, and that
                              # the Brevo provider + webhook route are wired (never sends)
php bin/console mail:check    # validates mail configuration (provider, sender,
                              # queue store, Brevo key/template presence)
```

### No real email is ever sent in tests

Automated tests never touch a mail provider's network. `MAIL_PROVIDER` defaults
to `fake` outside production, `MailProviderFactory` falls back to
`FakeMailProvider` when Brevo is selected without a key, and the Brevo unit
tests drive `HttpClient::useTransport()` with a scripted transport — so there is
no live HTTP. `FakeMailProvider` records every message and can be scripted to
return specific outcomes to exercise the retry / permanent / unknown paths.

## What is covered

### Unit tests

Fast, isolated tests of the platform's logic (each runs against a fresh temp
flat-file store):

- **Validation** — required / min / max / email / url / tel / `in` rules and
  message resolution (`Forms\Validator`).
- **Sanitisation** — control-character stripping, length caps, and email
  header-injection prevention (`Forms\Sanitizer`).
- **Repositories** — create / find / update / search and JSON round-tripping
  for contacts, companies, enquiries, opportunities, tasks and activities;
  the append-only activity log; atomic reference allocation.
- **Pipeline** — stage validation and won/lost stamping in
  `Crm::moveOpportunity()`.
- **Queue** — push/reserve/complete, lease-based reservation and crash
  recovery, dedupe keys, and backoff/retry on failure.
- **HMAC sign/verify** — the canonical string and constant-time verification
  (`Hermes\Signer`).
- **Replay protection** — timestamp window and single-use nonce rejection
  (`Hermes\Authenticator`).
- **Scopes** — endpoint scope enforcement and denial.
- **Rate limiting** — fixed-window hit counting and over-limit detection
  (`Security\RateLimiter`).
- **Reading time** — word-count → minutes (`Support\ReadingTime`).
- **SEO fallback** — title / description / canonical / robots / OG resolution
  and completeness flags (`Seo\Meta`).
- **Email address** — validation, header-injection rejection (address and
  display name), try-create null path and array round-trip
  (`Mail\EmailAddress`).
- **Brevo provider** — request/payload construction, `messageId` capture on
  accept, `429`/`5xx` mapped to retryable, other `4xx` to permanent, transport
  error (`status 0`) to unknown, template-send uses the template id, and error
  strings **never contain the API key** (`Mail\BrevoApiMailProvider`).
- **Provider factory selection** — smtp / fake selection, Brevo with a key,
  Brevo without a key failing hard in production but falling back to the fake
  provider in development (`Mail\MailProviderFactory`).
- **Suppression separation** — transactional and marketing scopes are
  independent; a hard bounce blocks transactional; an unsubscribe blocks
  marketing only; a soft bounce suppresses nothing; un-suppression is explicit
  and audited (`Mail\SuppressionService`).
- **Brevo admin** — contact sync dry-run maps only approved fields, invalid
  emails are rejected, and webhook registration is idempotent (`Mail\BrevoAdmin`).

### Integration tests

End-to-end exercises of the wired-up pipeline:

- **Form submissions** persist a company, contact, enquiry and activity records
  in one transaction, then enqueue side effects.
- **CSRF**, **honeypot**, **rate limit** and **duplicate** paths each behave as
  specified (rejections and benign "successes" as appropriate).
- **CRM record creation** from a submission (upsert-by-email, reference
  allocation).
- **Email outbox** — the internal + acknowledgement jobs are enqueued and the
  mailer is invoked via its test transport (no real SMTP).
- **Webhook creation** — a delivery row and `webhook.deliver` job are recorded
  for subscribed endpoints; signing and the injected HTTP client are asserted.
- **API auth / scope denial** — unauthenticated and under-scoped Hermes requests
  are rejected with the right status and audited.
- **Draft creation** — `content:draft` creates an unpublished draft and never
  publishes.
- **Upload** — MIME allow-list, extension cross-check, size limit and rejection
  handling (with uploads enabled).
- **Mail pipeline** — `queue()` creates an outbound row + `mail.send` job
  without sending inline; the worker delivers through the provider and stores
  the provider message id; **exactly-once** application message on a duplicate
  queue; a temporary failure retries then succeeds; a permanent failure does not
  retry; a suppressed recipient is never sent; a duplicate worker run does not
  double-send; and an enquiry **persists and queues both emails even when the
  provider would fail** (the internal-notification sender is the studio, never
  the submitter, with the submitter as a validated Reply-To that falls back to
  the studio address).
- **Brevo webhook** — rejects missing auth, oversized body, wrong content-type
  and malformed JSON; a `delivered` event updates the outbound row; a duplicate
  event is harmless; a hard bounce / spam complaint creates a transactional
  suppression; an unsubscribe does **not** block transactional; a late
  `delivered` does **not** downgrade a hard bounce; and an open neither
  suppresses nor creates timeline noise.
- **CRM email** — a send queues and links to the contact; suppressed and invalid
  recipients and empty subjects are rejected with structured errors; the preview
  escapes HTML (`Mail\CrmMailService`).
- **Privacy** — subject-access export returns structured data; anonymisation
  scrubs PII but keeps delivery history (and the keyed hash); retention cleanup
  dry-run reports counts (`Support\PrivacyService`).

### Browser tests (Playwright)

Runs on two projects — **desktop** (Desktop Chrome) and **mobile** (Pixel 5).
The test server is PHP's single-threaded built-in server, so the suite runs with
`workers: 1` to keep it deterministic under both projects.

The main visitor journey:

- **Navigation** between pages.
- **Forms** — the contact and start-a-project forms submit and show the success
  state.
- **Filtering** — the work index filters.
- **404** — the error page renders for unknown URLs.
- **Conversion path** — the end-to-end journey from landing to a submitted
  enquiry.

The Breakfast Admin + Client Previews surface:

- **Admin relocation** — the old `/panel` and descendants are `404`; the admin
  responds at its configured slug; public pages carry no hard-coded `/panel`
  links.
- **Preview origin** — a published preview serves with the full security header
  set and cross-origin isolation headers (no CORS grant, `same-origin`
  COOP/CORP, preview CSP); traversal and unknown slugs are neutral `404`s; the
  main site is unaffected by the preview routes.
- **Authenticated admin** (`admin-auth.spec.js`) — seeds a real admin and a
  limited writer (dev-only `user:seed`, resetting Kirby's login throttle first),
  then logs in through the **actual Panel login form** and asserts:
  the login page renders; an invalid password is rejected and stays on the login
  screen; a valid admin login (`200`) reveals the custom Breakfast Admin nav and
  logout invalidates the session; and — the key guarantee — a **writer's
  forbidden calls are refused by the server (`403`), not merely hidden**:
  `GET /api/breakfast/previews` and `GET /api/breakfast/admin/ops` both return
  `403` with a valid session + CSRF header. This proves authorization is
  enforced server-side, not just in the UI.

Note on origin isolation: the browser same-origin boundary between per-preview
subdomains needs real wildcard DNS, which the `php -S` test server cannot
provide; that guarantee is proven at the integration level
(`PreviewSubdomainTest`) instead — host validation, canonical `301` redirect,
per-preview host-only cookies and cross-preview isolation.

## Continuous integration

CI should run **all** of the above — PHPUnit, PHPStan, PHP-CS-Fixer and
Playwright — on every change. A change is not complete until they all pass:
both forms persist to the CRM before any external delivery, permissions are
enforced server-side, Hermes authenticates/enforces scopes/stays draft-only,
webhooks are signed and queued, no secrets are committed, static analysis and
style pass, and the main journey is browser-tested green.
