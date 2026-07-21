# Client Previews

Client Previews is a secure system for uploading and publishing basic **static**
mock-up websites at custom public URLs, on a **separate origin** from the studio
site and admin. It is built for sharing design work with clients — not for
running applications: nothing server-side ever executes.

See also: [client-preview-security.md](client-preview-security.md) and
[client-preview-deployment.md](client-preview-deployment.md).

## Concepts

- **Preview** — a named project with a public slug, a status, a visibility and a
  current published version. Linked optionally to a CRM contact/company/opportunity.
- **Version** — one immutable upload. Uploading creates a new version; publishing
  points the preview at it and supersedes the rest. Older versions are retained
  up to `CLIENT_PREVIEW_MAX_VERSIONS` and can be rolled back to.
- **Statuses:** draft · processing · active · disabled · expired · archived ·
  failed_validation.
- **Version states:** uploaded · validating · validation_failed · ready ·
  published · superseded · archived.
- **Visibility:** public (indexable only if an admin opts in) · unlisted
  (default, noindex) · password.

## Lifecycle

1. **Create** a preview (draft) with a valid slug.
2. **Upload** a ZIP (or files). The upload is validated into a scratch area and,
   only if it passes, promoted to an immutable version marked *ready*. A fresh
   upload is **never** made live automatically.
3. **Publish** a *ready* version → the preview becomes *active* and the version
   becomes the current one. Publishing/rollback is an atomic pointer switch.
4. **Share** the public URL (and, separately, the password if protected). From
   the preview detail you can prepare a CRM email **draft** for human review.
5. **Expire / disable / archive / delete** as the engagement ends.

## Uploading & validation

Uploads are validated deny-by-default (see the security doc for the full list).
Validation produces **blocking security errors** (which the operator cannot
override — the upload fails) and **non-blocking quality warnings** (external
scripts, trackers, external forms, source maps, likely secrets, missing
title/viewport, …). A package must contain an entry `index.html`.

Only an allow-list of static types is accepted: `.html .htm .css .js .mjs .json
.txt .xml .svg .png .jpg .jpeg .gif .webp .avif .ico .woff .woff2 .ttf .otf`
(`.map` too; `.mp4/.webm` only when `CLIENT_PREVIEW_ALLOW_VIDEO=true`). SVG is
sanitised. Executables, server configs, dotfiles and archives are rejected.

## Visibility & passwords

- **Unlisted** (default) — reachable only by the exact URL; `noindex`.
- **Public** — same, but an **admin** may additionally flip on indexability
  (with explicit confirmation).
- **Password** — a strong hash is stored; the gate is rate-limited with a generic
  failure; a successful unlock issues a short-lived, host-only session cookie
  bound to the current password hash, so changing/clearing the password
  invalidates outstanding sessions immediately. The password is shown/sent
  **separately** — never in the invitation email, subject, URL or logs.

## CRM & email

Previews can be linked to a contact/company/opportunity. From the detail screen
you can build a **CRM email draft** using the logical `client_preview_invitation`
template. It is only a draft: it is handed to the normal permissioned CRM email
composer for human review and sending through the queue + provider. The password
is never included; the audit records that a link was prepared, not the password.

## Hermes

Hermes may, with the appropriate scope, list/read/summarise/analyse previews and
create an **unpublished draft** (`previews:summary|read|analyse|draft`). It can
never upload, publish, change a URL, reveal/disable a password, delete, override
validation, enable indexing or send an invitation — there are no such endpoints.

## Permissions

Granular `breakfast.previews` actions (view, create, upload, validate, publish,
changeSlug, managePassword, manageExpiry, viewAnalytics, rollback, disable,
archive, delete, emailDraft, systemManage) are checked server-side by
`Security\PreviewGate` on every route. Defaults: editors build/upload; CRM
managers create + draft email + manage expiry; analysts view + analytics;
writers none; publishing, slug changes, passwords and deletion stay with admins.

## Operations (CLI)

```
php bin/console previews:expire                  # expire past-expiry previews
php bin/console previews:cleanup [--confirm]     # expire + prune temp/events/versions
php bin/console previews:validate <uuid>         # re-scan a live version's files
php bin/console previews:scan-all                # re-scan every preview
php bin/console previews:storage                 # storage usage summary
php bin/console previews:quarantine <uuid> --confirm     # emergency-disable one
php bin/console previews:disable-all --confirm           # emergency-disable all
php bin/console previews:enable-all --confirm            # re-enable all disabled
```

Expiry and access-event retention also run opportunistically from the queue
worker's housekeeping.

## Analytics

First-party and privacy-preserving: aggregate view counts and a **coarse**
unique estimate (distinct keyed IP hashes). No third-party trackers, no raw IPs,
no claim that a specific person viewed a page. Retention is
`CLIENT_PREVIEW_ANALYTICS_RETENTION_DAYS`.
