# Security

This document lists the security controls that are actually implemented in the
codebase, and where each one lives. It is a reference for reviewers and
operators, not a wish list.

## Secrets and configuration

- **Env-only secrets.** SMTP passwords, the Kirby licence, Hermes secrets and
  the webhook signing secret are read from the environment (`Support\Env` /
  `.env`), never hard-coded and never stored in Kirby content. `.env` and
  `.env.*` are gitignored (`.env.example` excepted).
- **Real environment wins.** The `.env` loader only sets a variable if the
  process environment does not already define it, so production hosts can inject
  real secrets and omit the file entirely.

## Sessions and cookies

- Session cookies are configured `SameSite=Lax`.
- In production, cookies are HTTPS-only and `Strict-Transport-Security` is sent
  (see headers below). Kirby's Panel install flow is disabled once the first
  admin exists (`panel.install = false` in production).

## Forms and anti-abuse

The form pipeline (`Forms\FormProcessor`, `Forms\SubmissionGuard`) applies, in
order:

- **CSRF.** The controller validates the token and the processor enforces the
  verdict; a failed check returns an editable error message.
- **Honeypot.** A hidden, `aria-hidden` field; any value means a bot. The
  submission returns a benign "success" so bots don't learn they were caught —
  the lead is simply never created.
- **Timing.** Submissions faster than 3 seconds, or with a missing/tampered
  render timestamp, or older than 1 hour, are treated as bots (benign success).
- **Rate limiting.** Per IP: 5 submissions / 10 minutes. Per email: 3
  submissions / hour. Backed by flat-file fixed windows keyed on **hashes**, never
  raw IPs.
- **Duplicate detection.** A SHA-256 fingerprint of the meaningful content;
  an identical submission within 10 minutes is treated as a (benign) duplicate.

Only after all checks pass is the enquiry persisted, and even then side effects
are queued, not sent inline (see [architecture.md](architecture.md)).

## Upload safety

Uploads (`Forms\UploadHandler`) are **off by default** (`UPLOADS_ENABLED`).
When enabled they are tightly constrained:

- **MIME allow-list**, detected from file content with `finfo` (not the
  client-supplied type): PDF, PNG, JPEG, WebP only.
- **Extension cross-check** against the detected MIME.
- **Size limit** (`UPLOADS_MAX_BYTES`, default 5 MiB).
- **Regenerated filenames** — a UUID plus the safe extension; the original name
  is never used on disk.
- **Stored outside the web root** (`storage/uploads/`), created `0770`, files
  written **non-executable** (`0640`).
- **Scanner interface** — an optional external command
  (`UPLOADS_SCANNER_CMD`, receives the path as `$1`, exit 0 = clean); the
  default is a no-op that records `skipped`. An `infected` result deletes the
  file and rejects the upload.
- **No file contents in email.** Uploads are only referenced; the bytes are
  never embedded in HTML email.
- **Admin-only download.** Files are served solely through
  `admin/download/upload/(:any)`, which requires a Panel user with the CRM
  `access` permission.

## Output escaping and rich text

- Block snippets always escape text; rich text is rendered only through Kirby's
  `->kt()` / `->kti()`. No block accepts raw HTML or inline styles derived from
  user input (see [content-model.md](content-model.md)).
- `excerptSafe()` strips tags and collapses whitespace before truncating.

## Security headers and CSP

`Security\SecurityHeaders` sets, on every response:

| Header | Value |
|---|---|
| `Content-Security-Policy` | see below |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `geolocation=(), camera=(), microphone=(), payment=(), interest-cohort=()` |
| `X-Frame-Options` | `DENY` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (production only) |

The CSP directives:

```
default-src 'self';
base-uri 'self';
script-src 'self' 'nonce-<per-request>' [analytics host];
style-src 'self' 'unsafe-inline';
img-src 'self' data:;
font-src 'self';
connect-src 'self' [analytics host];
form-action 'self';
frame-ancestors 'none';
frame-src 'self';
object-src 'none';
manifest-src 'self'
```

Notes:

- **No `'unsafe-eval'` anywhere**, and **no `'unsafe-inline'` for scripts** —
  inline scripts carry a fresh per-request **nonce** (held in `Support\Runtime`
  so the header and the `<script>` tags agree). `'unsafe-inline'` remains only
  on `style-src`, for the style attributes Kirby may emit; there are no inline
  `<style>` blocks.
- Analytics adds hosts to `script-src` / `connect-src` **only** when a provider
  is configured, and its script tag also carries the nonce.

## Server-side permission enforcement

CRM access is enforced on the server in every path — the Panel menu and buttons
are never treated as access control. `Security\PanelGate` gates `access`,
`manage` and `export`; admins always pass, other roles need the explicit
`breakfast.crm.<action>` role permission. Every Panel API route re-checks
before acting, and the Hermes API enforces per-endpoint scopes independently.
See [crm.md](crm.md) and [hermes-integration.md](hermes-integration.md).

## Header-injection prevention

`Forms\Sanitizer::headerSafe()` strips CR/LF (and their encoded forms `%0a` /
`%0d`) and control characters from any value that could reach an email header
(name, email, subject), neutralising header-injection attempts. `email()`
builds on it and lower-cases the address.

## Log redaction

`Support\Logger` writes structured JSON-lines logs and passes every context
array through a redactor: any key containing `password`, `secret`, `token`,
`authorization`, `signature`, `api_key`, `key`, `credential`, `cookie` or
`session` is masked to `***`. The Hermes audit log applies the same principle,
stripping sensitive keys (including `nonce`) before writing.

## Production hardening

- **Debug off in production.** `APP_DEBUG` defaults off when `APP_ENV=production`
  and `config.production.php` forces `debug => false`, so stack traces and
  paths are never leaked publicly.
- **Storage errors are opaque.** Record writes fail closed with a generic
  message; filesystem paths and internals never surface in a response.

## Protected storage

The flat-file data tree (`storage/data/`), sessions, cache, logs, queue and
uploads all live under `storage/`, **outside the `public/` docroot**, and are
therefore not reachable over HTTP. The web-server configuration additionally
denies access to sensitive directories as defence in depth (see the example
Nginx / Apache config in [deployment.md](deployment.md) and the deploy configs
under `deploy/` and `public/.htaccess`). Storage directories are created group-
writable for the app user and secrets files should be `0600`.

## Dependency audit

Dependencies are version-pinned in `composer.lock`. Run an advisory check
regularly and before each deploy:

```bash
composer audit
```

Update Kirby deliberately (`composer update getkirby/cms`), run the full test
suite, then deploy — see [deployment.md](deployment.md).

## Reporting a security issue

Report suspected vulnerabilities privately to the studio
(`studio@` the site's domain) rather than opening a public issue. Include steps
to reproduce and any relevant request/response detail — but **never** include
live secrets. Rotate any credential you believe may be exposed immediately (see
the rotation procedure in [hermes-integration.md](hermes-integration.md)).

## Breakfast Admin & Client Previews

The Kirby Panel is relocated to a private slug (`BREAKFAST_ADMIN_SLUG`) and the
old `/panel` returns 404 — a discoverability control, **not** a security boundary
(auth, permissions, CSRF and server-side authorisation still apply). See
[breakfast-admin.md](breakfast-admin.md).

Client Previews accept untrusted uploads and serve them from a **separate,
cookie-isolated origin** with no server-side execution, deny-by-default upload
validation, serve-time path containment and a hardened preview CSP. The full
model is in [client-preview-security.md](client-preview-security.md).
