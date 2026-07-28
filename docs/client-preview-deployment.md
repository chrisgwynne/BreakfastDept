# Client Previews — deployment

Previews are served on their own origin by the same Kirby app. The platform
detects the preview host per request and routes it through the preview
responder. There are three serving modes, in order of isolation strength:

| Mode | Origin per preview | Config | Use |
|------|--------------------|--------|-----|
| **Per-preview subdomain** (preferred) | `https://{slug}.preview.breakfastdept.com` | `CLIENT_PREVIEW_WILDCARD_BASE` | Production |
| Single shared host | `https://preview.breakfastdept.com/{slug}/` | `CLIENT_PREVIEW_BASE_URL` + `CLIENT_PREVIEW_HOST` | Legacy / single-cert hosts |
| Dev path prefix | `http://localhost/_preview/{slug}/` | (neither set) | Local only |

**Per-preview subdomains are the recommended production mode.** Each preview
gets its own browser origin, so the same-origin policy — not just cookie
scoping — separates previews outright: one preview's JavaScript cannot read
another's responses even if a visitor has unlocked it. Hostnames are derived
automatically from the preview slug; there is no per-preview DNS, certificate or
configuration change to make when a preview is created.

## 1. DNS (wildcard)

Point the whole preview zone at the server with a single wildcard record:

```
*.preview.breakfastdept.com.  IN  A     <server ip>
preview.breakfastdept.com.    IN  A     <server ip>   # canonical/apex host
# (AAAA equivalents for IPv6; or CNAME to the main host if it fronts both)
```

No per-preview records are needed — `alpha.preview…`, `beta.preview…` etc. all
resolve through the wildcard.

## 2. TLS (wildcard)

A wildcard hostname needs a wildcard certificate, which requires a **DNS-01**
challenge (HTTP-01 cannot validate `*.`):

```
certbot certonly --preferred-challenges dns \
  -d 'preview.breakfastdept.com' -d '*.preview.breakfastdept.com'
```

Automate renewal with your DNS provider's certbot plugin (e.g.
`certbot-dns-cloudflare`, `certbot-dns-route53`) so the DNS-01 record is written
programmatically. One certificate covers the apex preview host and every
per-preview subdomain.

## 3. Web server

Use the provided examples as a starting point:

- Nginx: [`deploy/nginx-preview.conf.example`](../deploy/nginx-preview.conf.example)
- Apache: [`deploy/apache-preview.conf.example`](../deploy/apache-preview.conf.example)

Both are configured for the wildcard host (`server_name preview.breakfastdept.com
*.preview.breakfastdept.com`). They:

- point the preview zone at the same `public/` docroot,
- route everything to Kirby's front controller (no static mapping into preview
  storage — the app streams validated bytes itself),
- run **no** PHP/CGI from preview content,
- disable directory listing, deny dotfiles/configs/source-maps/archives,
- set conservative fallback security headers (the app sets the full set),
- keep request bodies small (uploads happen on the admin host).

The app validates the incoming `Host` itself (`PreviewHost`): only a single,
valid slug label under the wildcard base is accepted; the apex host, multi-level
labels and non-slug labels are neutral 404s, so a mismatched vhost cannot leak
another origin's content.

## 4. Environment

Per-preview subdomains (preferred):

```
CLIENT_PREVIEW_WILDCARD_BASE=preview.breakfastdept.com
CLIENT_PREVIEW_SCHEME=https
```

Single shared host (legacy fallback — ignored when `WILDCARD_BASE` is set):

```
CLIENT_PREVIEW_BASE_URL=https://preview.breakfastdept.com
CLIENT_PREVIEW_HOST=preview.breakfastdept.com
```

Leave all of them blank in development to use the `/_preview/<slug>/` path prefix
on the main host (insufficient isolation — local only).

Storage lives outside the docroot at `storage/client-previews/` (temporary/ +
versions/{preview}/{version}/{manifest.json, files/}). Ensure it is writable by
the app user and excluded from backups-as-code / git (it already is).

## 5. Migrating existing previews

Nothing to migrate by hand. A preview's public hostname is **derived from its
slug** at request time, so the moment `CLIENT_PREVIEW_WILDCARD_BASE` is set,
every existing preview is reachable at `https://{its-slug}.preview.…/` with no
configuration edit. When a slug changes, the previous slug is remembered
(the `previous_slug` field on the preview record) and the old subdomain issues a
**301 redirect** to the current one, so shared links keep working. CRM deep
links and Brevo invitation links are generated from the same
`PreviewUrlGenerator`, so they automatically use the new origin.

## 6. Cookie scope (important)

Admin/Kirby cookies must be **host-only** on the main site. Do **not** configure
a `Domain=.breakfastdept.com` wildcard cookie anywhere — that would put admin
cookies in scope on every preview host. Each preview origin sets only its own
host-only password-session cookie (name derived per preview, no `Domain`
attribute), so unlocking one preview never unlocks another.

## 7. Scheduled maintenance

Run cleanup regularly (cron or the queue worker, which already expires previews
and prunes access events during housekeeping):

```
# hourly
php /var/www/breakfast/bin/console previews:expire
# nightly — preview + platform-wide retention (see docs/operations.md)
php /var/www/breakfast/bin/console retention:cleanup --confirm
```

## 8. Emergency controls

```
php bin/console previews:disable-all --confirm     # take every preview offline
php bin/console previews:quarantine <uuid> --confirm
php bin/console previews:scan-all                  # re-check all files vs current rules
```

## Weaker modes and their limits

If you cannot run a wildcard host and fall back to a single shared preview host
(or the dev path prefix), all previews share one origin: a preview's JavaScript
can read another same-origin preview's responses, and cookie isolation depends
entirely on host-only admin cookies. This is acceptable for internal mock-ups
but not for previews shared with different clients. Prefer per-preview
subdomains for anything beyond local development — see
[`client-preview-security.md`](client-preview-security.md).
