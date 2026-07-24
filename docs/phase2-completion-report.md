# Phase 2 (Delivery) — Completion Report

This report accompanies Phase 2 of the Breakfast agency operating system: the
**delivery** layer that turns an accepted commercial agreement into managed,
client-facing project work. It is built on the same principles as Phase 1 —
every primary action performs a real, server-authoritative, tested operation;
there are no placeholder pages, no fake success states, and no decorative
controls.

Branch: `claude/breakfast-kirby-rebuild-3vkbo4`.

**Verification at time of writing:** PHPStan L6 clean · PHP-CS-Fixer (@PSR12)
clean · PHPUnit **407 tests / 1390 assertions** green · `admin:action-check`
pass (**117 actions**) · Playwright desktop + mobile suites green · admin
type-check + build clean. Money is integer pence throughout; quantities are
thousandths; tax/discount are basis points.

## 1. What Phase 2 delivers

A connected delivery lifecycle attached to the same CRM entities as Phase 1:

> accepted proposal → **project** (by reference) → **template** generates real
> milestones + tasks → **client onboarding** (evidenced, mapped to CRM) →
> **client file library** (versioned, integrity-checked) → **credential vault**
> (encrypted, step-up reveal) → **change request** priced + sent + client-
> approved + **applied** to the project → draft invoice + CRM timeline + audit.

The authoritative proof that these connect (not merely coexist) is
`tests/Integration/Phase2JourneyTest.php`, which walks the entire chain end to
end with no shortcuts and asserts the financial, task, timeline and audit
outcomes.

## 2. Verticals

### 2A.1 Projects
Delivery workspace linked back to the immutable commercial records by reference
(never copying frozen terms into mutable fields). Real state machine with reason
enforcement, awaiting-client/blocked time accounting, reopen/archive/restore,
optimistic concurrency, financial roll-up from linked invoices, and progress
derived from real tasks. Can be created manually or from an accepted proposal /
completed contract / won opportunity.

### 2A.2 Milestones & tasks
Milestones form a dependency DAG (circular dependencies rejected, readiness
gating). Tasks have a board state machine whose progress states are gated on
readiness, task↔task and task↔milestone dependencies, checklists, bulk actions
and archival. Progress percolates up from tasks → milestones → project.

### 2A.3 Project templates
Nine versioned built-ins. Publishing freezes an immutable definition; applying
one generates milestones and tasks with business-day-resolved dates from the
frozen version.

### 2B.1 Client onboarding
Versioned templates with a server-side conditional-visibility engine, a form
builder, tokened client access, durable draft/resume with concurrency,
required-visible validation, immutable submission snapshots, mapping-conflict
review (no silent CRM overwrite) with safe empty auto-population, idempotent
task generation, and readiness gating. Hosted at `onboarding/<token>`.

### 2B.2 Client file library
Upload/replace with immutable versioning, a strict `FileValidator`
(executables, double extensions, null bytes, MIME/signature mismatch, unsafe
SVG, archive traversal/bombs all rejected), private `0600` storage with sha256
integrity checks and access logging, GD thumbnails, duplicate detection by hash,
usage-blocks-deletion, and immutable legal-document protection.

### 2B.3 Credential vault
Field-level libsodium encryption, key-versioned for rotation, key never in the
database. Masked list/detail, reveal/copy behind step-up re-authentication
(300s grant, admin-only), metadata-only auditing. Proven with a canary that
never appears in the DB file, search, audit, or exceptions. Top-level admin
navigation.

### 2B.4 Change requests + scope control
A formal, priced, versioned scope change bridging delivery and commerce. Draft
is editable (optimistic concurrency); **sending freezes** an immutable snapshot
+ real Dompdf document (sha256, `0600`, opaque key) + client token + a version
record. Pricing is server-computed from fixed items only (basis-point
discount/tax). Client decisions are explicit, evidenced and idempotent; viewing
records `viewed`, never approval. **Applying** an approved change is admin-only,
transactional and idempotent: it adds the approved value to the *project*
(never the proposal/contract), generates delivery tasks (deterministic keys),
may adjust the target date, and drafts an invoice whose total matches the
approved version to the penny. Hosted client page at `change/<token>`; staff PDF
download is authenticated and integrity-checked.

## 3. API, permissions, and no-dead-controls

Every delivery resource is a routed admin API handler (`projects`, `milestones`,
`project-tasks`, `project-templates`, `onboarding`, `onboarding-templates`,
`files`, `vault`, `change-requests`) behind session + CSRF + graded
`PanelGate` RBAC. `admin/action-registry.json` maps every primary action to its
resource/method/permission/persistence/audit/Playwright, and `admin:action-check`
fails CI if any action is a placeholder or lacks a real backend path — now
**117 actions** verified.

## 4. Security

See `docs/phase2-security-review.md` for the full threat model and control
inventory: three trust boundaries (staff / tokened client / scoped automation),
server-enforced RBAC and state machines, tokened public routes registered ahead
of the SPA catch-all and outside the `api/` slug, immutable snapshots + hashed
documents, encrypted vault with step-up reveal, and immutable audit + CRM
evidence for every consequential action.

## 5. Tests

| Suite | Proves |
| --- | --- |
| `ProjectsTest` | state machine, reason enforcement, accounting, concurrency, conversion |
| `DeliveryTest` | milestones/tasks, dependency gating, progress roll-up |
| `ProjectTemplatesTest` | versioned publish + business-day application |
| `OnboardingTest` | conditional logic, draft/resume, validation, mapping review, idempotency |
| `FilesTest` | validator rejections, versioning, integrity, usage-blocks-deletion |
| `VaultTest` | encryption at rest, masked responses, step-up reveal, canary containment |
| `ChangeRequestsTest` | pricing, state machine, frozen snapshot + PDF, evidenced/idempotent approval, apply idempotency, invoice-match, proposal untouched |
| **`Phase2JourneyTest`** | **the whole delivery lifecycle end-to-end** |

Browser coverage: desktop journeys per vertical (delivery, templates,
onboarding, files, vault, change requests) and a mobile responsiveness suite
(`admin-mobile.spec.js`) covering drawer navigation and the change-request tab
on a phone. The change-request desktop spec drives the real hosted client
approval and the admin apply.

## 6. Definition of done

- [x] Real persistence, versioned data, explicit state machines.
- [x] Server-enforced permissions (graded staff RBAC; admin-gated sensitive
      actions; scoped automation with no vault/delete/apply reach).
- [x] Secure public/client routes via unguessable tokens, noindexed, outside the
      `api/` slug, failing closed.
- [x] Optimistic concurrency and idempotent operations (deterministic
      `source_ref`, `applied` guards).
- [x] CRM activity + immutable audit records on every consequential action.
- [x] Action-registry coverage for every UI control (117 actions).
- [x] Unit/integration tests per vertical + an end-to-end acceptance journey.
- [x] Playwright proof on desktop and mobile.
- [x] No placeholder pages, fake success states, or decorative controls.
- [x] Each vertical committed as a buildable checkpoint that migrates, passes
      static analysis + action-check + its tests, and preserves prior
      functionality.

## 7. Residual limitations (honest)

- Change-request/onboarding client links are surfaced for the operator to send;
  they are not auto-emailed on every state transition (the transport seam
  exists; auto-send is a Phase 3/4 concern alongside the client portal).
- Company/opportunity-level financial dashboards aggregate at the project level;
  cross-project portfolio reporting is a Phase 4 concern.
- Google Calendar synchronisation remains out of scope.

Phase 2 (Delivery) is complete against its definition of done. Phase 3 (client
experience / portal) follows.
