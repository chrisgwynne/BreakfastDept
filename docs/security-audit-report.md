# Breakfast Platform — Security, Privacy & Production-Readiness Audit

**Scope:** the whole Breakfast platform — Kirby 5 CMS, the standalone Vue 3
admin SPA and its `/breakfast-admin/api/v1` layer, the passwordless client
portal, the client-preview hosting subsystem, the Hermes machine API, outbound
webhooks, the mail/queue pipeline, payments, and the deploy tooling.

**Method:** implementation was read and adversarially probed (multiple
independent read-only review passes plus targeted inspection). Confirmed
weaknesses were reproduced where safe, fixed, covered by regression tests, and
then re-audited. Every fix ships behind the existing CI gates.

**Standard applied:** no *known* exploitable vulnerability; secure-by-default
config; defence in depth; least privilege; tested trust boundaries; no secret
leakage; no client-data crossover; no unauthorized admin access.

> This report does **not** claim the platform is "100% secure." It records what
> was tested, what was fixed, and the residual risks that remain and how to
> retire them.

---

## 1. Verification results (after fixes)

All gates were run against the post-fix tree:

| Gate | Result |
|------|--------|
| PHPUnit (unit + integration) | **496 tests / 1605 assertions — PASS** |
| PHPStan (static analysis) | **No errors** |
| PHP-CS-Fixer (`--dry-run`) | **Clean (0 of 255 files)** |
| `admin:action-check` (dead-control gate) | **PASS — 138 actions verified** |
| Playwright — security specs (`security-headers`, `admin-portal`, `admin-vault`, `admin-website`) | **PASS** |
| Own + independent re-audit after fixes | **No confirmed exploitable vulnerability remaining** |

Security-specific regression tests added by this audit:

- `tests/Unit/SecurityHardeningTest.php` — SecretBox fails closed in prod, CSP shape.
- `tests/Unit/FileValidatorMimeTest.php` — executable-as-doc rejected; real PDF/OLE accepted.
- `tests/Unit/WebhookSsrfTest.php` — address classifier over internal vs public v4/v6 (incl. mapped/compat/NAT64/6to4).
- `tests/Unit/ConfigHardeningTest.php` — session timeouts, Secure cookies, fail-closed installer, prod debug off.
- `tests/Integration/AdminApiSecurityTest.php` — logout CSRF; vault-reauth throttle ordering.
- `tests/Integration/WebhookTest.php` — SSRF registration + delivery blocking.
- `playwright/tests/security-headers.spec.js` + additions to `admin-portal`/`admin-website` — header/throttle assertions.

---

## 2. Vulnerabilities found and fixed

Committed as five coherent, individually-buildable hardening checkpoints
(`Security audit fix 1/N … 5/N`).

### FIXED-1 — Missing CSP / security headers on standalone HTML (HIGH)
The admin SPA shell and every standalone tokened client page (invoice, proposal,
contract-sign, portal, onboarding, change-request) are rendered **outside** the
public-site layout that emits `SecurityHeaders`, so on a non-Apache deploy they
shipped with no Content-Security-Policy and no frame protection — the
sign/pay/approve flows were clickjackable and the capability token could leak via
`Referer`. Added `breakfast_client_headers()` and `breakfast_admin_shell_headers()`
(strict per-response CSP: `frame-ancestors 'none'`, `object-src 'none'`,
`base-uri 'self'`, `form-action 'self'`, `script-src 'self'` + nonce only;
X-Frame-Options DENY, Referrer-Policy, nosniff, Permissions-Policy, COOP,
`Cache-Control: no-store`, HSTS in prod) applied to every such response.

### FIXED-2 — Panel self-signup installer defaulted on (LOW)
`panel.install` relied on a hostname-loaded override. Now fails closed:
`Env::bool('PANEL_INSTALL', $isProduction === false)` — off in production unless
explicitly forced for a one-off bootstrap.

### FIXED-3 — At-rest crypto silently generated a local key (LOW→MEDIUM)
`SecretBox` generated a local key file when `PLATFORM_SECRET_KEY` was unset; on
ephemeral/container storage that regenerates and orphans all stored ciphertext
(vault secrets become undecryptable). Now throws in production when no env key is
present. Covers the vault key too (VaultCrypto delegates).

### FIXED-4 — Vault step-up re-auth was un-throttled (MEDIUM)
Kirby's login brute-force protection does not cover `validatePassword()`, so an
attacker riding an authenticated admin session could brute-force the vault reveal
grant at unlimited rate. Added a `vault_reauth:` limiter (5 / 15 min per admin)
that runs **before** the password check (429 on limit).

### FIXED-5 — Portal magic-link requests un-throttled (MEDIUM)
A known client address could be mail-bombed and the link endpoint abused. Added
per-email (5 / 15 min) and per-IP (20 / 15 min) throttles; over the limit the
page stays neutral (no enumeration) and mints nothing.

### FIXED-6 — Logout CSRF / method (LOW)
`DELETE /session` (admin logout) now requires a valid CSRF token; `portal/logout`
moved `GET → POST` (both snippets use POST forms) so a cross-site navigation
cannot force sign-out.

### FIXED-7 — Upload validator blanket-accepted `application/*` (MEDIUM)
`FileValidator::mimeMatches()` accepted any detected `application/*` MIME for
document/design extensions, so an ELF / Windows-PE executable renamed `.pdf`/
`.doc` passed validation and would be stored and served to clients as a download.
Replaced with an explicit per-extension detected-MIME allow-list plus a
hard-deny list of executable/script content types.

### FIXED-8 — Outbound webhooks had no SSRF protection (MEDIUM)
A webhook endpoint URL could target `169.254.169.254` (cloud metadata),
`127.0.0.1`, or any internal range, and the worker would POST to it. Added a
guard that rejects literal internal IPs / non-http(s) schemes at registration
and, at delivery, resolves the host, **fails closed if any** resolved address is
private/loopback/link-local/CGNAT/reserved (incl. IPv4-mapped, IPv4-compatible,
NAT64 and 6to4 IPv6 forms), pins curl to the validated IP (no DNS rebinding),
disables redirect-following, restricts protocols to http(s), and caps the
response body at 256 KB.

### FIXED-9 — Draft-preview route lacked frame/sniff/referrer protection (LOW)
`breakfast-admin/preview/page/*` rendered author-controlled draft HTML with only
`X-Robots-Tag`. It now also sends X-Frame-Options DENY, nosniff, Referrer-Policy
no-referrer and `Cache-Control: no-store` (the CSP itself comes from the rendered
layout's nonce-based `SecurityHeaders`; a second CSP here would break the page's
own nonce'd scripts).

### FIXED-10 — Deploy configs & CI token hardening (LOW)
Added HSTS, Permissions-Policy and an `X-Powered-By` strip (plus `ServerTokens
Prod`) to both `.htaccess` files and the nginx/apache vhost examples; gave the
FTP deploy workflow an explicit least-privilege `permissions: contents: read`.

### FIXED-11 — Session lifetime & cookie flags (LOW)
Admin/Panel sessions relied on framework defaults. Now explicit and env-tunable:
2 h absolute / 30 min inactivity for a normal login, 14 days for "remember me";
cookies are `Secure` in production.

---

## 3. Trust boundaries verified solid (no change required)

- **AuthZ / IDOR.** Portal access (`Portal::portalProject`, `assertFileDownloadable`,
  `submitFeedback`, `postClientMessage`) is gated on a live per-identity grant
  (`canAccessProject`), never on the URL; `accessibleDocuments`/`accessiblePreviews`
  are scoped by `contact_uuid` and the repository enforces the filter — no
  cross-client read by UUID guessing. Every admin API route requires an
  authenticated user + `PanelGate::canAccess` + CSRF on non-GET, and each module
  re-checks a specific capability gate.
- **Hermes machine API.** HMAC-SHA256 over method+path+timestamp+nonce+body-hash
  verified with `hash_equals`; replay window + single-use nonce; per-route scope
  enforced before dispatch; audited on allow and deny. No delete/merge/publish/
  email endpoints exist in the scope surface.
- **Secrets & PII.** No real secrets committed (all `Env::get(...)`; grep hits are
  test fixtures/placeholders). The vault never returns ciphertext/plaintext in
  list/find/search/audit (masked to a last-2-char hint); reveal/copy need step-up
  re-auth, are admin-only and rate-limited; audit records field names, never
  values. Production `debug=false`; the API catch-all returns a generic message
  with no stack trace.
- **Payments / money integrity.** Checkout amount is computed server-side from the
  stored invoice (`total − amount_paid`); the client supplies no price. Invoices
  are marked paid only by a verified webhook using the Stripe object's amount, with
  per-event idempotency. The Stripe webhook is fail-closed (`hash_equals` over the
  raw body, empty secret rejected, timestamp tolerance).
- **Injection.** No raw SQL interpolation of user data — PDO bound params
  throughout; the only interpolations are hardcoded table/column identifiers. No
  `eval`/`system`/`unserialize` on user input (the single `exec` wraps a configured
  scanner in `escapeshellcmd`/`escapeshellarg`). Client-facing output is escaped
  via `htmlspecialchars`; raw output is limited to staff-authored rich text.
- **Preview isolation.** Path traversal blocked (normalise + realpath
  containment); deny-by-default extension allow-list; SVG sanitised; service
  workers blocked; storage outside the docroot; dev-prefix mode refused in
  production. Per-subdomain origins fully isolate previews.
- **Open redirect / host header.** Redirects and capability links are built from
  the server-configured site URL, never a reflected `Host`/`Location`. The preview
  dispatcher reads `HTTP_HOST` only to *match* a configured zone.
- **Upload safety (beyond FIXED-7).** Double-extension, null-byte, path-traversal,
  image-dimension, unsafe-SVG and zip-bomb/traversal checks were already solid.

---

## 4. Residual risks (accepted / operational — not fixed here)

None of these is a confirmed exploitable vulnerability in the default/recommended
configuration; each is recorded with how to retire it.

1. **Single-host preview mode allows cross-preview cookie reads.** The preview
   CSP intentionally allows `unsafe-inline`/`unsafe-eval` (previews are arbitrary
   client mock-ups) and the password-unlock cookie is host-only. In the
   **single-host / dev-prefix** fallback (all previews share one origin) a
   malicious mock-up could read another preview's `bfpv_*` cookie via
   `document.cookie`. Fully mitigated by the **preferred** per-subdomain origin
   mode. *Action: set `CLIENT_PREVIEW_WILDCARD_BASE` (wildcard DNS + TLS) in
   production; never run single-host preview mode there.*

2. **Evidence `ip_hash` salt collapses without `WEBHOOK_SIGNING_SECRET`.** The
   proposal/contract/change-approval evidence IP hash is
   `sha256(WEBHOOK_SIGNING_SECRET . '|' . ip)`; if that env var is unset the salt
   is empty and the IP becomes rainbow-table-recoverable from the stored hash.
   This is signing *evidence* metadata, not an auth control. *Action: set
   `WEBHOOK_SIGNING_SECRET` in production (already required for webhook signing).*

3. **OOXML archives skip the zip-bomb/traversal scan.** `.docx/.xlsx/.pptx/.odt`
   are detected as `application/zip` and accepted, but `assertSafeArchive()` runs
   only for the `.zip` extension, so a decompression-bomb Office file is not
   caught by that specific guard (the 50 MB size cap still applies). Low risk;
   inherent to accepting Office formats. *Action (optional): extend the archive
   scan to the OOXML extensions.*

4. **Magic-link consume has a benign check-then-act race.** Two simultaneous
   requests with the same portal link could both mint a session — same legitimate
   user, no cross-user impact. *Action (optional): make consume atomic
   (`UPDATE … WHERE used_at IS NULL` and check affected rows).*

5. **`psd` uploads accept `application/octet-stream`.** finfo is unreliable for
   Photoshop files, so octet-stream is allowed for `.psd` only. Bounded to that
   extension and served as a `nosniff` attachment; known executables carry
   specific MIMEs that are hard-denied, so this does not reopen FIXED-7.

6. **Defence-in-depth items not pursued:** SHA-pinning third-party GitHub Actions;
   subresource-integrity for the (self-hosted, same-origin) admin bundle; a
   formal secret-rotation runbook. None affects the current trust boundaries.

---

## 5. Standard-by-standard sign-off

| Requirement | Status |
|-------------|--------|
| No known exploitable vulnerability | Met at time of audit (see §2–§3) |
| Secure-by-default configuration | Met (installer/crypto/cookies/session fail closed; §2 FIXED-2/3/6/11) |
| Defence in depth | Met (app CSP **and** server headers; registration **and** delivery SSRF checks) |
| Least privilege | Met (RBAC per route; Hermes scopes; CI token read-only) |
| Tested trust boundaries | Met (regression tests per boundary; §1) |
| No secret leakage | Met (no committed secrets; vault masked; prod debug off) |
| No client-data crossover | Met in the recommended config; §4-1 is the single conditional caveat |
| No unauthorized admin access | Met (server-side auth + CSRF + RBAC on every route) |

**Bottom line:** every confirmed weakness found in this audit has been fixed and
regression-tested, and the fixes are green across the full gate suite. The
platform is in a strong, defensible security posture. It is not claimed to be
"100% secure"; the residual risks in §4 — chiefly requiring per-subdomain preview
origins and a set `WEBHOOK_SIGNING_SECRET` in production — should be closed as
part of the production deployment checklist.
