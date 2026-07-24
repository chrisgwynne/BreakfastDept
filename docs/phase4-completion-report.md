# Phase 4 (Operations) — Completion Report

Phase 4 adds the **operational** layer that turns delivery into a running
business: measuring effort, billing it recurrently, surfacing what needs
attention, reporting on profitability, and automating the chasing. Every figure
is derived from source-of-truth tables so nothing can drift from what the system
actually recorded.

Branch: `claude/breakfast-kirby-rebuild-3vkbo4`.

**Verification at close:** PHPStan L6 clean · PHP-CS-Fixer clean · PHPUnit
**441 tests / 1515 assertions** green · `admin:action-check` **138 actions** ·
Playwright desktop + mobile green. Money is integer pence; time is seconds.

## Verticals

### 4.1 Time tracking
Time logged against projects and tasks with an explicit duration or a live
start/stop timer (at most one running per user). Billable time carries a
per-hour rate; the billable value is always server-computed. Once an entry is
attached to an invoice it is **locked** and immutable through the API. Per-project
rollups split billable / non-billable seconds and billable vs still-unbilled
value.

### 4.2 Retainers & recurring billing
Recurring agreements on a project — a fixed periodic fee plus an included-hours
allowance. Running the scheduler bills each elapsed period **in arrears**: a
draft invoice for the fee plus any time overage (billable time beyond the
allowance, at the overage rate), and it **locks the covered time entries** so
they can never be billed twice. Idempotent per period, with multi-period
catch-up. `retainers:run [--as-of=…]` CLI for scheduled billing.

### 4.3 Notifications & staff inbox
A single **live** to-do derived from source-of-truth state — unread client
messages, open feedback/sign-offs, onboarding to review, approved-but-unapplied
change requests, and retainers due to bill. Each item disappears the instant the
underlying thing is handled at source. Surfaced as a top-level Inbox with a
count badge and per-item deep links into the relevant project tab.

### 4.4 Operational reporting
Read-only rollups: per-project financial + effort position (contract value =
quote + approved variations; invoiced; paid; outstanding; logged and
still-unbilled time), a portfolio summary, and team utilisation over a trailing
window. Folded into the Reports page as a "Delivery & profitability" section.

### 4.5 Automation
A scheduled rule engine. Each enabled rule watches a trigger condition — an
overdue invoice, a stalled onboarding, an approved change left unapplied — and,
when a target first matches after its grace period, raises a follow-up task on
the target's project. Firing is **idempotent per (rule, target)**, so the runner
is safe to schedule. `automation:run [--as-of=…]` CLI; rules managed on the
Operations screen (admin-only).

## Cross-vertical coherence

The phase forms a loop: logged **time** (4.1) flows into **retainer** overage
(4.2) and gets locked on invoicing; unbilled time and AR feed **reporting**
(4.4); the **inbox** (4.3) and **automation** (4.5) both watch the same
source-of-truth state — the inbox to show a human what to do, automation to act
when a human hasn't.

## Permissions

Viewing follows CRM access throughout. Logging time, editing retainers and
running their billing require the CRM 'manage' grant; **automation** (rules +
engine) is admin-only, since it acts studio-wide.

## Tests

| Suite | Proves |
| --- | --- |
| `TimeTrackingTest` | duration guard, rollup splits + value, one-timer-per-user, billed lock |
| `RetainersTest` | fee + overage invoice + time lock, per-period idempotency, catch-up |
| `InboxTest` | live aggregation that clears when handled |
| `ReportingTest` | per-project rollup, portfolio sums, utilisation split |
| `AutomationTest` | grace-gated firing, idempotency, disabled rules never fire |

Browser suites: `admin-time`, `admin-retainers`, `admin-inbox`, `admin-reporting`,
`admin-automation` — each driving the real UI end to end, plus unauthenticated
rejection checks.

## Residual limitations (honest)

- Automation actions are follow-up tasks + audit today; email/portal-notification
  actions reuse the existing transport but are not yet wired as rule actions.
- Reporting cost figures use billing rates, not separate cost rates (no cost-rate
  model yet), so it reports revenue exposure rather than margin.
- Google Calendar synchronisation remains out of scope.

Phase 4 (Operations) is complete. The remaining programme work is Phase 5
(Growth), whose scope is yet to be detailed.
