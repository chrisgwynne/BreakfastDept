# Client Previews — security model

Client Previews accept untrusted uploads and serve them publicly. The design
assumes the uploaded content is hostile and confines it accordingly.

## Origin isolation

Previews are served from a **separate origin** to the admin/main site, never
through an authenticated admin route. Production uses **one origin per preview**.

- **Per-preview subdomain (preferred, built-in).** With
  `CLIENT_PREVIEW_WILDCARD_BASE` set, each preview is served from its own host
  `https://{slug}.preview.breakfastdept.com`. Because every preview has a
  distinct browser origin, the **same-origin policy** — not merely cookie
  scoping — keeps them apart: preview A's JavaScript cannot read preview B's
  responses at all, even for a visitor who has unlocked B. The incoming `Host`
  is validated (`PreviewHost`): exactly one valid slug label under the wildcard
  base is accepted; apex, multi-level and non-slug hosts are neutral 404s, and a
  request arriving under the old slug is **301-redirected** to the canonical
  host so links never silently serve the wrong preview.
- Admin/Kirby cookies are **host-only** on the main host and must never use a
  `Domain=.breakfastdept.com` wildcard, so they are out of scope on every
  preview host.
- The only cookie set on a preview origin is a **host-only** preview
  password-session cookie (name derived per preview, no `Domain` attribute),
  issued by the app — so unlocking one preview cannot unlock another.
- A single shared preview host (`CLIENT_PREVIEW_HOST`) is a legacy fallback; in
  development previews fall back to a path prefix (`/_preview/<slug>/`) on the
  main host. Both share one origin and are therefore **weaker isolation** —
  documented, and not for cross-client use (see below).

Under the preferred per-preview-subdomain mode there is a true origin boundary
between previews. Only the shared-host and dev-prefix fallbacks put previews on
one origin, where one preview's JavaScript could read another same-origin
preview's responses.

## No server-side execution

Nothing uploaded ever executes. The responder streams bytes for allow-listed
static types only; the web server is configured to run no PHP/CGI from preview
content. Blocked at validation: `.php* .phar .cgi .pl .py .rb .sh .bash .exe
.dll .so .jsp .asp* .bat .cmd .ps1 .wasm`, plus `.htaccess`, `.user.ini`,
`web.config`, dotfiles and archives.

## Upload validation

Deny-by-default, performed before any byte is written to a served location:

- **Paths** are normalised; absolute, `..` traversal, backslash, drive-letter,
  UNC and null-byte forms are rejected (`PreviewPathGuard`).
- **Types** must be on the static allow-list; executables/configs/dotfiles are
  rejected explicitly.
- **Limits** (all env-configurable, server caps editors cannot raise): ZIP size,
  total extracted size, per-file size, file count, directory depth, versions.
- **Zip bombs**: suspicious compression ratios and oversized entries are rejected;
  archives are **never** extracted with `ZipArchive::extractTo` — each entry's
  bytes are read and written to a path the app constructs from the normalised
  name, so "zip-slip" is impossible and symlinks are never created.
- **SVG** is active content: every SVG is sanitised — any DOCTYPE/entity
  declaration is rejected (blocks XXE / entity-expansion) and scripts, event
  handlers, `<foreignObject>` and unsafe references are removed. Because the
  preview CSP allows inline scripts (see below), this sanitiser is the **primary**
  control for SVG active content, not a redundant backstop.
- **Scanning**: content is checked for likely secrets (AWS keys, private keys,
  Slack/Google tokens, `api_key=...`), source maps, service-worker files,
  external scripts/forms and trackers → warnings.

Blocking errors fail the upload and cannot be overridden. The version is marked
`validation_failed` with its report; nothing is promoted or published.

## Serve-time containment

Every served path is re-resolved through `PreviewPathGuard::resolveServed`, which
`realpath`s the target and confirms it stays inside the published version's
`files/` directory. Anything else is a generic 404. Disabled and expired previews
return neutral pages that leak no client/CRM detail.

## Response headers (preview origin)

Set on every preview response:

- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer`
- `Permissions-Policy` — geolocation/camera/microphone/etc. all denied
- `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`
- `X-Frame-Options: SAMEORIGIN`
- `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` (unless an admin made a
  public preview indexable), plus a preview `robots.txt` that disallows all
- A preview **CSP**: `object-src 'none'`, `worker-src 'none'` (blocks service
  workers), `frame-ancestors 'self'`, `frame-src 'self'`, `form-action 'self'`
  (demo forms cannot post to Breakfast or external), `base-uri 'self'`,
  `connect-src 'self'`. Inline styles/scripts are allowed so static mock-ups work.

## Client-side script execution (by design)

A preview is a real website mock-up, so **uploaded HTML/JS runs in the visitor's
browser**. The CSP intentionally allows `'unsafe-inline'`/`'unsafe-eval'` scripts;
this is *client-side* execution only — there is no server-side execution (see
above). It is confined by the dedicated origin: no admin/Kirby/CRM cookie is ever
in scope there, `connect-src 'self'` and `form-action 'self'` keep requests on the
preview origin, and framing is same-origin only.

The residual risk is **cross-preview**, and it exists **only when previews share
one origin** — the legacy single-host setup or the dev path prefix. There, a
malicious or public preview's JavaScript can `fetch()` another same-origin
preview and, if the visitor has previously unlocked a password-protected one,
read its content. The built-in per-preview-subdomain mode
(`CLIENT_PREVIEW_WILDCARD_BASE`, see the deployment doc) removes this risk
entirely by giving each preview its own origin. When you must run a shared host,
only share preview links with the intended client, and do not treat a preview
password as protection against a hostile *co-tenant* preview on the same origin.

## Service workers

Service-worker registration files are detected (warned) and, more importantly,
neutralised at serve time: `worker-src 'none'` and no `Service-Worker-Allowed`
header, so a preview cannot register a worker that persists or controls a later
preview at the same path.

## Passwords

Stored only as a `password_hash`. Unlock attempts are rate-limited per
preview+client with a **generic** failure. The session token is HMAC-bound to the
current password hash, so rotating/clearing the password invalidates every
outstanding session. Passwords never appear in URLs, subjects, params or logs —
the audit records only that a link was prepared.

## Privacy

Access events store **only** keyed hashes (never raw IPs or user agents), a
coarse referer host and a sanitised path, pruned on a retention schedule.
Analytics are aggregate and never claim a specific person viewed a page.

## Preview forms

Preview demo forms are, by default, inert. Optional preview lead-capture stores
submissions flagged as **test leads**, never emails the client, and is
bulk-deletable per preview. External form submission is blocked by the CSP
`form-action 'self'`.
