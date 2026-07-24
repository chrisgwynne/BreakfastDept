# Phase 2 (Delivery) — Security Review

This review covers the Phase 2 delivery verticals added to the standalone
Breakfast Admin: projects, milestones, tasks, project templates, client
onboarding, the client file library, the credential vault, and change requests
+ scope control. It records the threat model, the controls that are enforced
server-side, and how each is proven. Nothing here relies on the client or the
UI to enforce a rule.

Branch: `claude/breakfast-kirby-rebuild-3vkbo4`.

## 1. Trust boundaries

There are three distinct callers, each with a different level of trust:

1. **Authenticated staff** — a Kirby Panel user with a session cookie. Every
   admin API route requires a valid session, a matching CSRF token on mutating
   verbs, and a `PanelGate` permission check. RBAC is graded: *view* follows CRM
   access; *manage* requires the CRM manage grant; the most sensitive actions
   (vault reveal/rotate, permanent file deletion, applying a change request to a
   project) require the admin role.
2. **The client** — an unauthenticated visitor holding an unguessable, single
   purpose token. Clients reach only three hosted routes: the onboarding form,
   the change-request page, and (from Phase 1) proposal/contract/invoice pages.
   The token is the capability; possession never elevates into staff access.
3. **Hermes / automation** — scoped service calls. Hermes has *no* scope that
   can reach the vault, delete files, or apply a change request; those are
   staff-and-admin only.

## 2. Server-enforced controls by vertical

### Projects, milestones, tasks
- All mutations pass `PanelGate::canManageProjects`; archival/restore is a
  deliberate, separately-gated action.
- State is a real state machine (`STATUSES` + `TRANSITIONS`); illegal
  transitions are rejected server-side, cancellation/blocking require a reason,
  and optimistic concurrency (`revision` + `expectedRevision`) prevents
  lost updates.
- Gated task states (in_progress/review/completed) require the task to be
  *ready* (its dependencies satisfied); readiness is computed on the server, not
  trusted from the client.

### Project templates
- Publishing freezes an immutable, versioned definition; applying a template
  generates milestones/tasks from the frozen version with business-day-resolved
  dates. A template is never mutated after publication.

### Client onboarding
- The hosted form is reached only by an opaque token (`onboarding/<token>`);
  viewing records `viewed`, never `in_progress`, and never approval.
- Answers are validated server-side against the frozen template version;
  required-visible questions must be answered, but questions hidden by
  server-evaluated conditional logic are *not* required (the client cannot
  bypass a required field by hiding it, nor be blocked by a hidden one).
- Submission takes an immutable snapshot. Mapping a submitted answer onto an
  existing CRM value never silently overwrites: a conflict raises a review that
  a human must decide; empty targets are auto-populated and logged.
- Task generation from onboarding is idempotent (deterministic `source_ref`).

### Client file library
- `FileValidator` rejects executables, double extensions, null-byte names,
  MIME/signature mismatches, unsafe SVG, and archive traversal/zip-bombs before
  a byte is stored.
- Files are written outside the webroot under opaque keys with `0600`
  permissions and a stored sha256; every download re-checks the hash (integrity)
  and path containment (no traversal) and records an access event.
- Versions are immutable; legal/immutable documents cannot be replaced or
  deleted through the library. Permanent deletion is admin-only; usage links
  block deletion.

### Credential vault
- Field-level encryption with libsodium `SecretBox` (XSalsa20-Poly1305). The key
  comes from `PLATFORM_SECRET_KEY`/a `0600` key file, **never** the database, and
  is key-versioned to support rotation.
- List/detail responses are masked (a two-character hint only); the ciphertext
  is never serialised to the client.
- Reveal/copy require step-up re-authentication (password re-entry) with a
  short-lived (300s) grant, and are admin-only. The audit log records *that* a
  reveal happened (actor, item, field) but never the secret value.
- Proven with a canary value: the plaintext never appears in the database file,
  in search output, in the audit log, or in exception messages.

### Change requests + scope control
- Draft is editable with optimistic concurrency; a real state machine governs
  the lifecycle.
- **Send freezes** an immutable snapshot (scope + pricing + timeline), renders a
  real PDF from that snapshot (not from live data), stores it `0600` under an
  opaque key with a sha256, records a version, and mints a client token. The
  sent document can never silently change afterwards.
- Pricing is server-computed from *fixed* items only (basis-point discount/tax);
  optional lines are opt-in and excluded from the committed total.
- Client decision is explicit and **evidenced** (name, wording, document hash,
  approved total, hashed IP, user agent, token id). Viewing records `viewed`,
  never approval. Decisions are idempotent.
- **Applying** an approved change is admin-only, transactional and idempotent
  (`applied` guard + deterministic task `source_ref`). It adds the approved value
  to the **project** (`approved_variations`) — it never mutates the accepted
  proposal or signed contract — generates delivery tasks, may adjust the target
  date, and drafts an invoice whose total matches the approved version to the
  penny. Re-applying is rejected.

## 3. Public-route hardening

- The public change and onboarding routes are registered *before* the SPA
  catch-all and avoid the `api/` slug (which Kirby's own API router would
  swallow). They emit `X-Robots-Tag: noindex, nofollow`.
- Tokens are 20 random bytes (hex). An unknown token returns a normal 404 error
  page, never a stack trace or a differentiated "exists but forbidden" response.
- Staff PDF downloads are gated by `PanelGate`, stream with
  `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`, and
  fail closed on any integrity/containment check.

## 4. Auditing & non-repudiation

Every consequential action writes an immutable `hermes_audit` event (send,
approve, reject, apply, reveal, delete) with the actor and metadata, and — where
a client is involved — a CRM activity on the contact timeline. Change-request
and onboarding acceptances additionally persist a dedicated evidence row. The
Phase 2 acceptance journey asserts that the applied change appears in both the
audit log and the client timeline.

## 5. Verification

- **PHPStan L6** clean; **PHP-CS-Fixer** (@PSR12) clean.
- **PHPUnit** — every vertical has an integration suite (Projects, Delivery,
  ProjectTemplates, Onboarding, Files, Vault, ChangeRequests) plus the
  end-to-end **Phase2JourneyTest** that connects them.
- **`admin:action-check`** fails CI if any UI action lacks a real, permissioned,
  persisted backend path.
- **Playwright** — desktop journeys per vertical and a mobile responsiveness
  suite; the change-request desktop journey drives the real hosted client
  approval and the admin apply.

## 6. Residual limitations (honest)

- Client notifications for change requests/onboarding are surfaced as links in
  the admin (copy-to-client); transactional email delivery of those links reuses
  the existing queue but is not auto-sent on every transition.
- The vault holds secrets for staff use; it is not a client-facing password
  manager and intentionally exposes no client route.
- Google Calendar synchronisation remains out of scope for Phase 2.
