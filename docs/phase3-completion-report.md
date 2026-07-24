# Phase 3 (Client experience) — Completion Report

Phase 3 adds a **client portal**: a passwordless, self-service experience that is
a wholly separate trust boundary from the staff console. A client signs in with a
single-use email link, sees exactly what they've been granted, and interacts —
feedback, sign-off, messaging — with every action mirrored to the CRM timeline
and audit log. No staff session is ever consulted on a portal route.

Branch: `claude/breakfast-kirby-rebuild-3vkbo4`.

**Verification at close:** PHPStan L6 clean · PHP-CS-Fixer clean · PHPUnit
**420 tests / 1445 assertions** green · `admin:action-check` **125 actions** ·
Playwright desktop + mobile green.

## Verticals

| # | Vertical | Client capability | Server-enforced guarantees |
| - | -------- | ----------------- | -------------------------- |
| 3.1 | Foundation & identity | Sign in via a single-use magic link | Passwordless; hashed magic-link + session tokens; access grants; no email enumeration; suspension kills live sessions |
| 3.2 | Project experience | View a granted project's progress, milestones, tasks; download shared files | Access checked against live grants, not the URL; only client-visible tasks/files exposed; download guarded by visibility + grant |
| 3.3 | Feedback & approvals | Comment and formally sign off a project | Evidenced approvals (name, hashed IP, UA); attributed; mirrored to CRM; staff triage (open → acknowledged → resolved) |
| 3.4 | Preview invitations | See live design previews in the portal | Scoped to the client's own contact and active previews only |
| 3.5 | Messaging | Two-way project message thread with staff | Per-(project, identity) thread; per-side read tracking; client messages mirrored to CRM |
| 3.6 | Documents | View own proposals, contracts, invoices | Scoped to the client's contact and sent statuses only; drafts/internal states never appear |

## Trust boundary

- Portal auth is a dedicated `bf_portal` httpOnly, `Path=/portal`, `SameSite=Lax`
  cookie carrying an opaque token stored only as a sha256 hash — never the Kirby
  staff session.
- Every portal read/write re-checks the session and the relevant access grant and
  fails closed. Hidden resources 404 rather than reveal their existence.
- All portal routes are `noindex, nofollow`, registered ahead of the SPA
  catch-all, and outside the `api/` slug.

## Staff side

The project workspace gains a **Client access** tab: invite a client (create
identity + grant + mint a sign-in link), revoke access, triage feedback and
sign-offs, and hold a per-client message thread — all behind graded `PanelGate`
RBAC, with 8 portal actions in the action registry.

## Tests

`PortalTest` (14) is the authoritative domain proof: identity uniqueness, no
enumeration, single-use + expiring links, session validate/revoke, suspension,
grant-gated project/file/feedback/message access, client-visible-only
projections, preview + document scoping, evidenced sign-off, two-way messaging
with read tracking, and that no raw token is ever stored. `admin-portal.spec.js`
drives the full browser journey: staff invite → client signs in from a clean
context → sees only the granted project → comments, signs off, and messages →
staff see and respond → one-shot replay blocked → logout.

## Residual limitations (honest)

- Transactional email of sign-in links goes through the existing queue
  best-effort; outside production the link is also shown on-page for
  demonstrability.
- Document viewing links to the existing tokened hosted pages rather than
  re-implementing document rendering inside the portal.
- Google Calendar synchronisation remains out of scope.

Phase 3 (Client experience) is complete. Phase 4 (Operations) follows.
