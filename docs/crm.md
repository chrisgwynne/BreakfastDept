# CRM

The CRM is the operational heart of the platform. It stores every enquiry,
contact, company, opportunity, task and activity in SQLite, coordinates them
through a service layer, enforces the sales pipeline and keeps an immutable
audit trail. It is reached from three places — the public form pipeline, the
Panel CRM area, and the Hermes API — all of which go through the same service
layer so the rules live in one place.

## Entities and their fields

The schema is defined in
`site/plugins/breakfast-platform/migrations/0001_crm_core.sql`. All timestamps
are ISO-8601 UTC strings and all primary keys are UUIDs.

### Contacts (`contacts`)

People. Created or enriched automatically from form submissions (upsert by
normalised email so duplicates are avoided).

Key fields: `first_name`, `last_name`, `display_name` (required), `email`,
`email_normalised` (lower-cased, indexed), `phone`, `company_uuid`, `role`,
`website`, `location`, `contact_type` (default `lead`), `lead_source`,
`marketing_consent` (`granted` / `denied` / `unknown`), `marketing_consent_at`,
`consent_source`, `tags` (JSON), `owner`, `status` (default `active`), `notes`,
`created_at`, `updated_at`, `last_contacted_at`, `archived_at`,
`anonymised_at`.

### Companies (`companies`)

Organisations. Created via `findOrCreate()` (matched case-insensitively by
name). Fields: `name` (required), `website`, `industry`, `size_band`,
`address`, `notes`, timestamps.

### Enquiries (`enquiries`)

A single form submission. Fields: `reference` (human-readable, unique, e.g.
`ENQ-2026-0001`), `form_type` (`contact` / `start-project`), `contact_uuid`,
`company_uuid`, `payload` (JSON of the sanitised submitted fields), `summary`,
`source_page`, `referrer`, `utm` (JSON), `landing_page`, `ip_hash` (keyed hash,
short retention — never a raw IP), `status` (default `new`), `owner`,
`spam_status` (default `unknown`), `risk_score`, `consent` (JSON), timestamps.

References are allocated atomically from a `counters` table, per year, e.g.
`ENQ-2026-0001`.

### Opportunities (`opportunities`)

A potential piece of work in the pipeline. Fields: `title`, `contact_uuid`,
`company_uuid`, `enquiry_uuid`, `stage` (default `new`), `estimated_value`
(minor units — pence), `currency` (default `GBP`), `probability` (0–100),
`services` (JSON), `expected_close_date`, `next_action`, `next_action_date`,
`owner`, `lost_reason`, `won_at`, `lost_at`, `notes`, timestamps.

### Tasks (`tasks`)

Follow-ups. Fields: `title`, `contact_uuid`, `company_uuid`,
`opportunity_uuid`, `assigned_to`, `due_date`, `priority` (`low` / `normal` /
`high`), `status` (`open` / `done` / `cancelled`), `notes`, `completed_at`,
timestamps. Marking a task `done` stamps `completed_at` automatically.

### Activities (`activities`)

The immutable audit log — see below.

## Repository and service layers

Each entity has a repository (`ContactRepository`, `CompanyRepository`,
`EnquiryRepository`, `OpportunityRepository`, `TaskRepository`,
`ActivityRepository`) that extends the shared `Crm\Repository`. Repositories:

- use prepared statements only;
- whitelist which columns an `update()` may write (via `assignments()`);
- encode/decode JSON columns transparently on the way in and out.

The `Crm` service (`Crm\Crm`) sits above the repositories. **All meaningful
changes go through it**, because it is responsible for enforcing pipeline
rules and writing the activity trail. Its main operations:

- `addNote(entityType, uuid, note, actorType, actorRef)`
- `classifyEnquiry(...)` — set status / spam status / risk score / summary
- `setEnquiryStatus(...)`
- `createOpportunity(...)`, `moveOpportunity(...)`
- `createTask(...)`
- `dashboard()` — the metrics below

The `Support\Platform` container exposes each repository and the service
(`breakfast()->crm()`, `->enquiries()`, etc.), building each one lazily and
once.

## Pipeline stages and transitions

The default pipeline (`Crm::DEFAULT_STAGES`) is:

```
new → qualified → discovery → proposal → decision → won
                                                   ↘ lost
```

`moveOpportunity($uuid, $stage, ...)` validates the target against the known
stages (throwing on an unknown stage) and stamps outcomes:

- moving to **`won`** sets `won_at` and `probability = 100`;
- moving to **`lost`** sets `lost_at`, `probability = 0` and records the
  supplied `lost_reason`.

Every move writes a `stage.changed` activity with the from/to stages. The stage
set is configurable via the platform's `pipelineStages` option; the seven
stages above are the default.

Opportunities are considered **open** when their stage is neither `won` nor
`lost`; open opportunities drive the pipeline value and "stale opportunity"
reporting.

## The immutable activity log

`activities` is append-only. `ActivityRepository` deliberately exposes no
`update` or `delete` method — once written, a record cannot be changed. Every
row captures `entity_type`, `entity_uuid`, `type` (e.g. `form.received`,
`note.added`, `status.changed`, `stage.changed`, `enquiry.classified`,
`task.created`), `actor_type` (`user` / `system` / `hermes`), `actor_ref`
(e.g. the user's email or the Hermes credential id), a human-readable `summary`
and safe JSON `metadata`.

This gives a complete, tamper-evident history of who did what to every record —
whether the actor was a Panel user, the system (a form submission) or Hermes.

## Dashboard metrics

`Crm::dashboard()` returns:

- `new_enquiries`, `qualified_enquiries`, `total_enquiries`
- `open_opportunities`, `pipeline_value` (sum of open estimated values),
  `pipeline_by_stage`
- `overdue_tasks`, `upcoming_tasks` (next 7 days)
- `contacts`, `companies`
- `by_source` (enquiries grouped by the `heard_about` payload field)
- `recent_activity` (latest 15 activity records)

The Panel dashboard route additionally attaches `failed_jobs` and
`queue_depth` from the queue so operators see delivery health in one place.

## The Panel CRM area and its permission model

The CRM is a custom Panel area (`areas/crm.php`) with views for the dashboard,
enquiries, an enquiry detail, contacts, the pipeline board and tasks. Its Vue
components read from the Panel API routes in `api/crm.php`.

Access control is enforced **server-side, everywhere** — hiding a menu item or
a button is never treated as a control. Two layers apply:

- The Panel area only shows its menu entry, and each view re-checks access, via
  `Security\PanelGate::canAccess()`.
- Every API route re-checks the permission: **read** routes require `access`,
  **mutating** routes require `manage`, and export requires `export`
  (`PanelGate::canAccess` / `canManage` / `canExport`).

`PanelGate` resolves a permission as: admins always pass; any other role must
have the custom `breakfast.crm.<action>` permission granted in its role
blueprint.

| Role | `access` | `manage` | `export` | Effect |
|---|---|---|---|---|
| **admin** | yes | yes | yes | Full CRM access (always). |
| **crm-manager** | yes | yes | — | Read and modify CRM records; move the pipeline. |
| **analyst** | yes | — | — | Read-only: can view enquiries, contacts, pipeline and tasks but not change them. |
| **editor** | — | — | — | No CRM access. |
| **writer** | — | — | — | No CRM access. |

Because the checks are server-side, an analyst hitting a mutating route
directly is rejected with a permission error rather than silently succeeding.

## CRM email

The CRM can send **one-to-one operational email** — a reply to an enquiry, a
follow-up on an opportunity — from the Panel CRM **Mail** area and its backing
API routes (`breakfast/crm/email/*` in `api/crm.php`). This is deliberately
**not** a bulk marketing tool: one recipient per message, an approved sender
identity, suppression enforced, and every send routed through the durable
outbox — it is never delivered inline. The work is done by
`Mail\CrmMailService`, which builds a provider-agnostic `MailMessage` and hands
it to `MailService::queue()`.

### Permissions

Access is enforced server-side on every route (hiding a button is never a
control):

| Route | Method | Gate | Grant needed |
|---|---|---|---|
| `breakfast/crm/email/preview` | POST | `PanelGate::canManage` | CRM **manage** |
| `breakfast/crm/email/send` | POST | `PanelGate::canSendEmail` | CRM **manage** |
| `breakfast/crm/email/test` | POST | `PanelGate::canSendEmail` | CRM **manage** |
| `breakfast/crm/email/deliveries` | GET | `PanelGate::canViewEmailDelivery` | CRM **access** |
| `breakfast/crm/mail/status` | GET | `PanelGate::canViewEmailDelivery` | CRM **access** |

`canSendEmail()` resolves to the CRM **manage** grant and
`canViewEmailDelivery()` to the CRM **access** grant, so composing and sending
require a manager while a read-only analyst can view delivery history but never
send. `CrmMailService::send()` additionally refuses an invalid recipient
(`invalid_recipient`), an empty subject (`subject_required`), and a
transactionally-suppressed recipient (`recipient_suppressed`) — returning a
structured error rather than throwing.

### The email tables

Introduced by
`migrations/0003_brevo_and_email_tracking.sql` (forward-only, provider-agnostic):

- **`outbound_messages`** — one durable row per outbound transactional message:
  the CRM correlation UUIDs (contact/company/enquiry/opportunity/task), sender,
  a keyed `recipient_hash` (for correlation without exposing the address
  alongside the retained `recipient_email`), a redacted `params_snapshot`, the
  provider message id, the `idempotency_key` (unique), and the delivery
  `status` plus per-state timestamps.
- **`email_events`** — the individual delivery/engagement events received from
  the Brevo webhook, reduced to the minimum needed for diagnosis, audit and
  idempotency (a `dedupe_fingerprint`, unique) — **not** the raw provider
  payload.
- **`email_suppressions`** — the suppression list, keyed by an
  `(email_hash, scope)` pair (see below).

### The delivery-state machine

`outbound_messages.status` moves through:

```
queued → processing → accepted → delivered
```

`MailService` sets `queued` on enqueue, `processing` when the worker picks the
job up, and `accepted` when the provider accepts it (capturing the provider
message id). The Brevo webhook then advances the row from later events, via
`OutboundMessageRepository::applyEventState()`, into any of:

`soft_bounced`, `hard_bounced`, `blocked`, `invalid_recipient`,
`spam_complaint`, `unsubscribed`, `delivered` — plus `suppressed` (set before
sending when the recipient is already suppressed) and `permanent_failure` /
`temporary_failure` from a failed provider send.

The transition logic is **terminal-safe and out-of-order-safe**: the terminal
negative states — `hard_bounced`, `blocked`, `invalid_recipient`,
`spam_complaint` — are **never downgraded** by a late or duplicated positive
event (a `delivered`/`opened`/`clicked` arriving after a hard bounce is ignored
for status purposes). Duplicate events are dropped by the dedupe fingerprint, so
replays are harmless. Opens and clicks update engagement timestamps only and are
never written to the CRM timeline (they would be noise); only meaningful states
(`delivered`, `hard_bounce`, `blocked`, `spam`, `unsubscribed`,
`invalid_email`) record a contact activity.

### Suppression: marketing vs transactional

Suppression is modelled with two **separate scopes**, so the two kinds of
consent never bleed into each other (`SuppressionService`):

- **`marketing`** — set by an **unsubscribe**. It stops marketing only; a lawful
  one-to-one transactional reply may still be sent to a marketing-suppressed
  contact.
- **`transactional`** — set by a **hard bounce**, **spam complaint / block**, or
  **invalid recipient**. It stops transactional delivery: `MailService` and
  `CrmMailService` both check `canSendTransactional()` and refuse to send.

Removal is always an **explicit, audited** action (`unsuppress()`); a CRM edit
can never silently resubscribe someone. Both suppression and un-suppression
write an immutable contact activity when a contact is known.

## GDPR and data minimisation

The CRM is built to hold personal data responsibly.

- **No raw IP addresses.** Enquiries store only a keyed HMAC hash of the IP
  (`Security\Hash::ip()`, truncated), used for rate limiting and abuse
  correlation — never the address itself.
- **Short IP-hash retention.** `EnquiryRepository::pruneIpHashes($days)` nulls
  the `ip_hash` on enquiries older than the retention window (default 30 days),
  so even the hash is not kept indefinitely.
- **Contact archival.** `ContactRepository::archive()` soft-archives a contact
  (`status = archived`, `archived_at` set) without deleting history.
- **Erasure / anonymisation.** `ContactRepository::anonymise()` irreversibly
  clears personal fields (name, email, phone, website, location, notes, tags),
  sets `display_name` to "Anonymised contact", `marketing_consent` to `denied`,
  `status` to `anonymised` and stamps `anonymised_at`. The row is kept for
  referential integrity and audit, but no longer identifies a person.
- **Consent is recorded, not assumed.** Marketing consent, its timestamp and
  its source are stored per contact and per enquiry; the form pipeline only
  records `granted` when the visitor actively ticks the consent box.
