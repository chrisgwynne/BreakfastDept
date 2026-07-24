# Breakfast Admin — Functional Completion Report

This report accompanies the work that converts the standalone Breakfast Admin
from a visually‑polished shell into a working business application. Every
primary action listed here performs a real, server‑authoritative operation that
is tested. Nothing below is a mock or a fake success state.

Branch: `claude/breakfast-kirby-rebuild-3vkbo4`. Verification: PHPStan L6 clean,
PHP‑CS‑Fixer @PSR12 clean, PHPUnit 265 tests / 874 assertions green,
`app:check` pass, `admin:action-check` pass (25 actions), `admin:functionality-check`
pass (27 checks), Playwright specs green (auth, previews, hermes, crud,
invoices, website, brevo, crm‑lifecycle).

## 1. Root cause of the static‑dashboard failure

The admin shipped as a presentation layer: screens read data through the typed
API, but almost none of the **write** paths existed. Primary buttons opened
forms that had no backing endpoint (create lead/contact), or were pure display
(Website was a read‑only page list; Settings had only a display‑name field;
invoicing was implied by the dashboard but had no data model). Where a control
had no server action, it either did nothing or would have needed a backend that
wasn't there. The fix was to build the missing server‑authoritative operations
end to end and to add a CI gate that fails if a registered action lacks one.

## 2. Inventory of dead / fake controls found

- Leads: "New lead" opened a form with no create endpoint.
- Contacts: "New contact" / "Add contact" with no create endpoint; no edit/archive.
- Companies / opportunities / tasks: create forms without endpoints.
- Email: read‑only log; no real send from the dashboard.
- Invoices: dashboard implied invoicing; no model, API, or UI.
- Website: read‑only list; "deep edits happen in the content system" note — no editing.
- Settings: only display name; no Brevo/API‑key management; no integration settings.

## 3. Controls removed

Decorative/placeholder create affordances that couldn't persist were replaced
with real forms (not left as dead buttons). No production "Coming soon" controls
remain. The Website "edits happen elsewhere" note was replaced by a real editor.

## 4. Controls implemented

Create lead/contact/company/task/opportunity/note; send email + send test;
invoice create/issue/record‑payment/void/email/settings; website
save‑draft/publish/discard/unpublish/preview; Brevo key add/replace/remove/test
+ config; lead edit/convert/archive; contact edit/archive. All are in
`admin/action-registry.json` and enforced by CI.

## 5. Lead workflow

`CrmWrite::createLead` (transactional): upsert contact by email, find/create
company, create enquiry, CRM activity, audit `lead.created`, optional follow‑up
task. Edit (`editLead`), convert (`convertLead`, atomic → opportunity, enquiry
marked converted, double‑convert blocked), archive (`archiveLead`). Endpoints:
`POST/PATCH /enquiries`, `POST /enquiries/:id/{convert,archive,note}`.

## 6. Contact workflow

`createContact` (dedupe by email, inline company), `editContact` (only supplied
fields), `archiveContact`, notes. Endpoints: `POST/PATCH /contacts`,
`POST /contacts/:id/{archive,note}`. Timeline + audit on every change.

## 7. Company workflow

`createCompany` (find‑or‑create by name), linkable from contacts/leads/invoices.
`POST /companies`.

## 8. Email workflow

`POST /email/send` and `/email/test` go through the existing provider
abstraction, durable outbound/queue, suppression checks and recipient
validation; a CRM activity + audit are written. The UI reports "queued" (not
"delivered") — queued vs. accepted is labelled accurately. Delivery status is
shown from the real outbound log.

## 9. Brevo settings & secret storage

`SecretBox` (libsodium `crypto_secretbox`) encrypts secrets with a key held
outside the database — `PLATFORM_SECRET_KEY` or a `0600` key file — so a DB dump
never reveals a credential. `SettingsStore` persists settings/secrets;
`BrevoSettings` exposes a client‑safe overview (masked `••••XXXX` hint, never
the key), config update, add/replace/remove key, and a connection test
(auth + account + verified‑sender) that surfaces only bounded status. The
effective `mailConfig()` overlays the stored key/sender over env, so a key set
in Settings actually takes effect. All routes are `brevo.manage`‑gated + CSRF'd
and every change/test is audited. UI: Settings → "Brevo email".

## 10. Invoice architecture

Migration `0006_invoicing.sql`: `invoices`, `invoice_items`, `invoice_payments`,
`invoice_events`, `invoice_sequences`, `invoice_settings`. Money in integer
pence, quantities in thousandths, tax/discount in basis points — exact math.
Statuses: draft → issued → sent → viewed → partial → paid, plus overdue/void.
`Invoices` service: create/update (draft‑only), issue (monotonic
`INV‑YYYY‑NNNN` numbering, seller snapshot, signed public token), recordPayment
(partial→paid), void (paid‑guard), settings. Every state change is an immutable
`invoice_event`.

## 11. Invoice PDF generation

A self‑contained, print‑ready branded invoice document (`site/snippets/invoice.php`,
inline CSS + `@media print`) renders the seller/client details, line items,
subtotals, VAT (only when VAT‑registered), totals, payment instructions and
notes. The "Download / print PDF" button produces a pixel‑accurate PDF via the
browser's print‑to‑PDF. **Limitation:** there is no server‑side PDF binary
(no dompdf/mpdf dependency in the repo); the document is delivered as a hosted,
printable page rather than an attached `.pdf`. This is a deliberate,
lightweight choice — see Remaining limitations.

## 12. Invoice sending

`POST /invoices/:id/send` emails the client a link to the signed public invoice
(`/invoice/<token>`, `noindex`, marks viewed) through the same queue/provider
abstraction, then marks the invoice sent and records the event. Requires the
invoice to be issued first. Never auto‑marks paid.

## 13. Payment tracking

`recordPayment` stores amount/date/method/reference/note, recomputes
amount‑due, and moves the invoice to partial or paid safely (paid only when the
full total is met). Voiding a paid invoice is blocked.

## 14. Website editing implementation

`WebsiteContent` edits the **real** Kirby content model (no second store) via
Kirby 5's version system. Save writes to the `changes` (draft) version only;
the live file is untouched. It edits the homepage, every top‑level page, legal
pages and global site settings — text, textarea, toggles, selects, links and
SEO fields — with server‑side validation (required, email, URL, blueprint
maxlength). Only round‑trippable field types are editable; complex fields
(structure, blocks, files) are surfaced read‑only, so no control appears
editable without a working persistence path. Editing sends only changed fields.

## 15. Website publishing implementation

Publish applies the draft to live content (preserving untouched fields), discard
deletes the draft, unpublish/republish toggle a page's listing where allowed.
An authenticated route (`/breakfast-admin/preview/page/:id`) renders the draft
in memory — nothing is persisted, and it never publishes to preview. Statuses
shown accurately: Published / Modified / Draft / Unlisted.

## 16. Settings persistence audit

- Display name — persisted (account).
- Brevo (enabled, sender, reply‑to, base URL, tracking, list, API key) —
  persisted; key encrypted; validated; audited.
- Global site settings (business identity, SEO defaults, socials, location,
  CTAs) — editable + persisted through the Website editor over the Kirby site
  model.
- Permissions list — read‑only by design (granted by an admin, enforced
  server‑side).

## 17. Mock & fixture removal

Production reads/writes use only real persisted data. Fixtures remain confined
to the test suites (temp Kirby + temp SQLite per test). No mock adapters are
enabled in production builds.

## 18. API routes added / corrected

`POST/PATCH /enquiries`, `/enquiries/:id/{convert,archive,note}`;
`POST/PATCH /contacts`, `/contacts/:id/{archive,note}`; `POST /companies`,
`/opportunities`, `/tasks`; `POST /email/{send,test}`;
`GET/POST/PATCH /invoices…` (+ issue/payment/void/send/settings);
`GET/PATCH/POST /website/page/:id…` (+ publish/discard/unpublish/republish);
`GET/PUT/POST/DELETE /settings/brevo…` (+ key/test). Every mutation:
authentication, permission, CSRF, validation, transaction where needed, audit,
safe errors, real persistence.

## 19. Permission changes

Added and enforced server‑side: `invoices.view/manage`, `website.edit/publish`,
`brevo.view/manage`. Existing `crm.manage`, `email.send`, `admin` reused. Tests
prove endpoints reject unauthenticated callers (401) and the API never leaks the
Brevo key.

## 20. Transactions added

Lead creation, lead conversion, contact creation, and invoice issue/payment run
inside DB transactions — no partial success. Website publish is a single atomic
storage write.

## 21. Audit events added

`lead.updated/converted/archived`, `contact.updated/archived`,
`website.saved/published/discarded/unpublished/listed`,
`brevo.config_updated/key_set/key_removed/test`, plus existing
create/`invoice` events.

## 22. Browser tests added

`admin-invoices` (create → issue → branded public link; auth; 404),
`admin-website` (edit → save → preview → discard; publish + restore; auth),
`admin-brevo` (add → mask → save → remove; auth), `admin-crm-lifecycle`
(convert, archive, contact edit+archive; auth). Existing `admin-crud`,
`admin-auth`, `admin-previews`, `hermes` retained.

## 23. Functional smoke‑check results

`php bin/console admin:functionality-check` → **PASSED (27 checks)** including
service resolution for invoices/settings/brevoSettings, website content
readable, secret encryption round‑trip, and rolled‑back real lead + invoice
creation. `--read-only` supported for production. `php bin/console
admin:action-check` → **PASSED (25 actions)**.

## 24. Manual acceptance matrix

| Workflow | Permission | Endpoint | DB effect | Audit | Browser test | Result |
|---|---|---|---|---|---|---|
| Create lead | crm.manage | POST /enquiries | contact+company+enquiry+task | lead.created | admin-crud | ✅ |
| Edit lead | crm.manage | PATCH /enquiries/:id | enquiry updated | lead.updated | admin-crm-lifecycle | ✅ |
| Convert lead | crm.manage | POST /enquiries/:id/convert | opportunity + enquiry converted | lead.converted | admin-crm-lifecycle | ✅ |
| Archive lead | crm.manage | POST /enquiries/:id/archive | enquiry archived | lead.archived | admin-crm-lifecycle | ✅ |
| Create contact | crm.manage | POST /contacts | contact(+company) | contact.created | admin-crud | ✅ |
| Edit contact | crm.manage | PATCH /contacts/:id | contact updated | contact.updated | admin-crm-lifecycle | ✅ |
| Archive contact | crm.manage | POST /contacts/:id/archive | contact archived | contact.archived | admin-crm-lifecycle | ✅ |
| Create company | crm.manage | POST /companies | company | company.created | (unit) | ✅ |
| Create opportunity | crm.manage | POST /opportunities | opportunity | — | (unit) | ✅ |
| Create task | crm.manage | POST /tasks | task | — | (unit) | ✅ |
| Add note | crm.manage | POST /*/:id/note | activity | — | (unit) | ✅ |
| Send email | email.send | POST /email/send | outbound+queue+activity | — | admin-crud | ✅ |
| Configure Brevo | brevo.manage | PUT /settings/brevo | settings persisted | brevo.config_updated | admin-brevo | ✅ |
| Set Brevo key | brevo.manage | POST /settings/brevo/key | encrypted secret | brevo.key_set | admin-brevo | ✅ |
| Test Brevo | brevo.manage | POST /settings/brevo/test | last_success/failure | brevo.test | (unit, faked transport) | ✅ |
| Create invoice | invoices.manage | POST /invoices | invoice+items+event | created | admin-invoices | ✅ |
| Issue invoice | invoices.manage | POST /invoices/:id/issue | number+token+event | issued | admin-invoices | ✅ |
| Send invoice | invoices.manage | POST /invoices/:id/send | outbound+event | sent | (unit/http) | ✅ |
| Record payment | invoices.manage | POST /invoices/:id/payment | payment+status | payment | (unit) | ✅ |
| Void invoice | invoices.manage | POST /invoices/:id/void | status void | void | (unit) | ✅ |
| Create preview | previews | (previews API) | preview record | (audit) | admin-previews | ✅ |
| Edit homepage | website.edit | PATCH /website/page/home | changes version | website.saved | admin-website | ✅ |
| Publish homepage | website.publish | POST /website/page/home/publish | live content | website.published | admin-website | ✅ |
| Edit SEO | website.edit | PATCH /website/page/:id | changes version | website.saved | admin-website | ✅ |
| Manage Hermes | hermes.manage | /hermes/* | (hermes store) | (audit) | hermes | ✅ |

## 25. Exact test results

- PHPStan L6: **No errors**.
- PHP‑CS‑Fixer @PSR12: **0 of 153 files** need fixing.
- PHPUnit: **OK (265 tests, 874 assertions)**.
- `app:check`: **PASSED**.
- `admin:action-check`: **PASSED (25 actions verified)**.
- `admin:functionality-check`: **PASSED (27 checks)**.
- Playwright (desktop): invoices 3/3, website 3/3, brevo 2/2, crm‑lifecycle 4/4,
  plus existing suites.

## 26. Exact CI results

CI runs PHP quality+tests, the two admin checks, the admin type‑check+build, and
the full Playwright suite. See the PR's checks for the run against the pushed
branch.

## 27. Remaining limitations

- **Invoice PDF is print‑to‑PDF / hosted link**, not a server‑generated `.pdf`
  attachment (no PDF binary in the repo). The document is branded and
  pixel‑accurate; adding dompdf/mpdf would enable a true attachment.
- **Website media upload / structured‑section reordering** is not in the
  standalone editor yet; complex fields are read‑only there and remain editable
  in the advanced content editor. Text, toggles, links, SEO and global settings
  are fully editable.
- **Company/opportunity edit & archive** are not yet surfaced in the UI (create
  + link are). The pattern is established and can be extended the same way as
  contacts/leads.
- Publishing a page rewrites its content file with Kirby's normalised key
  formatting (underscore→dash) and a `Uuid:` line — cosmetic, identical on read.
