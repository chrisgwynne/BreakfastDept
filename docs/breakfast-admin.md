# Breakfast Admin

Breakfast Admin is the studio's own branded control panel. It is the Kirby Panel,
relocated to a private slug, rebranded, and given a custom dashboard, navigation
and operations area. Editors never see the generic `/panel` experience.

## The private admin slug

The Panel is moved off the well-known `/panel` path to `BREAKFAST_ADMIN_SLUG`
(default `breakfast-admin`), set in `.env` and applied via Kirby's `panel.slug`
option in `site/config/config.php`.

- The admin lives at `/breakfast-admin` (or whatever slug you configure).
- The old `/panel` and every path beneath it return the branded **404** page
  (a route guard in the platform plugin), consistently.
- The slug is **not** listed in `robots.txt` and the Panel sends its own
  `noindex` headers, so the location is not advertised.

**The slug is a discoverability control, not a security boundary.** Authentication,
role permissions, CSRF, sessions and server-side authorisation are all still
mandatory and enforced on every route — changing the slug only removes the
obvious entry point. Never rely on the slug alone.

URLs into the admin are generated from the configured slug via
`Support\AdminUrl` — never hard-code `/panel` or the slug anywhere.

## Navigation

The default Panel menu is replaced entirely by `Admin\AdminMenu` (wired through
`panel.menu`). Every entry is gated by a server-side permission closure; hiding
an entry is a convenience only — each destination re-checks permission.

**Everyday:** Dashboard · Website · Work · Services · Journal · Leads · CRM ·
Client Previews · Tasks · Email · Media · Forms · Reports · Settings

**Administration (admins only):** Users · Integrations · Brevo · Hermes · Queue ·
System · Audit

Content entries (Website, Work, …) are visible to roles with content access
(editors, admins); CRM entries to roles with `breakfast.platform` access; Client
Previews to roles with `breakfast.previews` view; administration entries to
admins only.

## The dashboard

The dashboard (`Admin\DashboardData`, view `k-breakfast-dashboard`) is the first
screen after login (`panel.home = dashboard`). It shows only **safe summaries**
and is **permission-shaped** — a Writer receives no CRM figures, and only admins
receive system health. It never exposes API keys, credentials, database paths or
environment values.

Panels: a greeting and quick actions; business-overview metrics that link to the
matching filtered views; the lead inbox; a pipeline snapshot; outstanding tasks;
a client-previews summary; recent website changes; and — for admins — a
restricted system-health panel (queue depth, failed jobs, mail provider, version).

## Operations

The admin-only **Operations** area (`areas/ops.php`, view `k-breakfast-ops`)
consolidates Integrations, Brevo/email status, Hermes status, the queue (with
retry) and the audit log, plus **Reports** for any CRM-access role. All figures
come from the permission-guarded `breakfast/admin/*` API and contain no secrets.

## Verifying

`php bin/console app:check` asserts that the admin slug is configured, that the
old `/panel` returns 404, that the custom menu and dashboard home are wired, and
that the previews permission category is registered and denies by default.
