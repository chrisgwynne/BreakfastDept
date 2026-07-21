# Pre-merge audit — PR #14

Audit of the branch `claude/breakfast-kirby-rebuild-3vkbo4` against its reported
claims, performed by inspecting the actual repository (not the summary). Each
finding is classified **Blocker / High / Medium / Low / Verified**. Remediation
follows in the same pass; the "Status" column is updated as items are fixed.

## Method

- Read every module under `site/plugins/breakfast-platform/src`, the migrations,
  `api/crm.php`, `areas/crm.php`, `index.php`, `bin/console`, the config, the
  controllers, the templates/snippets, the tests, and the CI workflow.
- Ran the local suites: `php tools/phpunit.phar` (69 tests) and Playwright (20).
- Grepped for `TODO/FIXME/stub` (none found in `src`).
- Verified the reported claims individually below.

## Verified claims

| # | Claim | Evidence | Status |
|---|---|---|---|
| V1 | Kirby 5.5.2 installed via Composer | `composer.lock` pins `getkirby/cms 5.5.2`; `App::version()` prints 5.5.2 | Verified |
| V2 | 69 PHPUnit tests pass | `php tools/phpunit.phar` → OK (69 tests, 134 assertions) | Verified |
| V3 | 20 Playwright tests pass | `npx playwright test` → 20 passed (desktop+mobile) | Verified |
| V4 | 25/25 pages render | render harness over `site()->index()` → all OK | Verified |
| V5 | Enquiry persisted before side effects | `FormProcessor::persist()` runs in a transaction and returns before `enqueueSideEffects()` | Verified |
| V6 | Forms: CSRF/honeypot/timing/rate-limit/dedup | `FormProcessor` + `SubmissionGuard`, covered by integration tests | Verified |
| V7 | Durable queue with leases/backoff/idempotency | `Queue` + `Worker`; unit tests for dedupe, backoff, retry | Verified |
| V8 | Hermes HMAC + scopes + nonce/replay + audit | `Authenticator`/`Signer`/`Api`; 10 integration tests | Verified |
| V9 | Private uploads outside webroot + admin-only download | `UploadHandler` stores under `storage/uploads`; `admin/download/upload/:uuid` gated by `PanelGate` | Verified |
| V10 | No secrets committed; DB gitignored | `git ls-files` shows no `.env`, `.sqlite`, vendor, storage data | Verified |
| V11 | Permissions enforced server-side | `PanelGate` + `api/crm.php` re-check on every route | Verified |

## Findings requiring remediation

| # | Severity | Finding | Remediation | Status |
|---|---|---|---|---|
| F1 | **High** | PHPStan and PHP-CS-Fixer run with `continue-on-error: true` (advisory). The DoD for this pass requires them blocking. | Remove `continue-on-error`; fix the 15 known PHPStan findings; keep CI blocking. | **Fixed** |
| F2 | **High** | Transactional email sends synchronously via `Kirby::email()` inside the queue worker; no provider abstraction, no delivery tracking, no provider message IDs, no Brevo. | Introduce `MailProviderInterface` (Brevo/SMTP/Null), `MailMessage` DTO, a provider-result model, and route all sends through it via the queue. Add `outbound_messages` tracking. | **Fixed** |
| F3 | **High** | No suppression model; a hard bounce / complaint / unsubscribe could not stop future sends. Marketing vs transactional consent not separated. | Add `suppressions` table + `SuppressionService`; enforce in the send path; separate marketing vs transactional. | **Fixed** |
| F4 | **High** | No inbound delivery-event handling (bounce/block/complaint/open/click). | Add authenticated Brevo webhook receiver + `email_events` + delivery-state machine. | **Fixed** |
| F5 | **Medium** | 15 PHPStan (level 5) findings: Kirby dynamic-typing false positives on `content()->get()->toFile()` and `Site::title()`, one always-false defensive `if`, one generic-template annotation. | Add a narrow, documented Kirby stub + fix the two genuine ones; make level 6 pass. | **Fixed** |
| F6 | **Medium** | No `mail:check` / mail diagnostics / privacy CLI (`privacy:export-contact`, `anonymise`, `cleanup`). Anonymisation existed in the repository but was not exposed operationally. | Add the CLI commands; wire to existing repository methods. | **Fixed** |
| F7 | **Medium** | No CRM one-to-one email capability. | Add `CrmMailService`, template registry, permissioned API routes, Panel mail status area, activity logging. | **Partial** — server-side + status area done; full Vue composer UI is documented as follow-up (see Limitations). |
| F8 | **Medium** | README "Local setup" was a fenced block without exact numbered commands; deployment lacked a Brevo/SPF/DKIM section. | Rewrite README quick-start with exact commands; add `docs/deployment.md` Brevo section + pre-launch checklist. | **Fixed** |
| F9 | **Low** | CI did not run `composer validate --strict`, `composer audit`, migration-from-empty, or upload failure artefacts. | Expand CI. | **Fixed** |
| F10 | **Low** | `mailInternal` used the submitter address only as `replyTo` (correct) but the reply-to was not explicitly validated before use. | Validate reply-to; fall back to the enquiries address when invalid. | **Fixed** |
| F11 | **Low** | Queue payloads carried enquiry UUIDs only (good) but no documented retention/cleanup for jobs/webhook events. | Add retention cleanup (`privacy:cleanup`, event pruning) + docs. | **Fixed** |

## Notes on test quality

- Existing assertions are specific (status codes, record counts, exact fields),
  not smoke-only. The Hermes suite asserts 401/403 distinctions, nonce reuse and
  scope denial. The form suite asserts persistence, dedupe, rate-limit and
  honeypot behaviours. These were judged **adequate** and are extended, not
  replaced, by the new Brevo/suppression/privacy tests.
- The one area with weaker *browser* coverage is the authenticated Panel (login
  + CRM workflows). This is recorded under Limitations.

## Remediation summary

All Blocker/High items and most Medium/Low items are remediated in this pass and
verified by the local PHPUnit suite and CI. Residual scope is listed in the final
report's "Remaining limitations".
