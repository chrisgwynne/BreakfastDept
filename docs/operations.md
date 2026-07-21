# Operations

Day-to-day running of the Breakfast platform: the queue, health checks, logs,
housekeeping and how to recover from the common failures.

## The queue worker

All external side effects — enquiry notification emails, visitor
acknowledgements and outbound webhook deliveries — are processed by the queue
worker, not during the web request. Run it one of two ways:

**Cron (bounded pass).** `queue:run` calls `Worker::runOnce()`, which processes
up to 25 jobs and returns. Ideal for a per-minute cron:

```cron
* * * * * php /var/www/breakfast/bin/console queue:run
```

**Supervised worker (long-running).** `queue:work` calls `Worker::work()`,
which loops, processing batches and sleeping ~5 seconds between empty polls.
Run it under systemd or supervisor for lower delivery latency.

Jobs are reserved under a **120-second lease**, so a crashed worker's in-flight
job becomes available again automatically (crash recovery) and multiple workers
never process the same job. Failed jobs retry with exponential backoff
(10s → 60s → 300s → 900s → 3600s) up to `max_attempts` (default 5); after that
the job is marked `failed` and a `alert.job_failed` job is enqueued.

## Monitoring failed jobs

Two views onto queue health:

- **Panel — CRM dashboard.** The dashboard shows `failed_jobs` and
  `queue_depth`. The failed-jobs list is available at the Panel API route
  `breakfast/crm/jobs/failed`, and a failed job can be requeued via
  `breakfast/crm/jobs/{uuid}/retry` (requires the `manage` permission).
- **Health endpoint.** `GET /api/breakfast/v1/health` returns
  `queue_depth` (pending + reserved) and `failed_jobs`. It is the only
  unauthenticated Hermes route, but note the whole Hermes surface returns
  `503` unless `HERMES_ENABLED=true` — if Hermes is disabled, rely on the Panel
  dashboard instead.

A steadily rising `queue_depth` usually means the worker is not running; a
rising `failed_jobs` means deliveries are erroring (see recovery below).

## Log locations

Structured JSON-lines logs are written under `storage/logs/`, one file per day:

```
storage/logs/app-YYYY-MM-DD.log
```

Each line is a JSON object with `ts`, `level` (`info` / `warning` / `error`),
`channel` (`forms`, `queue`, `hermes`, …), `message` and a redacted `context`.
Secrets, tokens and authorization values are masked to `***` before writing
(see [security.md](security.md)). Tail errors with, for example:

```bash
grep '"level":"error"' storage/logs/app-$(date +%F).log
```

## Housekeeping / pruning

Several short-lived tables are swept so they don't grow unbounded:

- **Rate-limit buckets** (`rate_limits`) — pruned **opportunistically on every
  queue pass**: `Worker::runOnce()` calls `RateLimiter::prune()` after handling
  jobs, deleting expired windows. So running the queue also keeps this table
  tidy.
- **Hermes nonces** (`hermes_nonces`) — pruned **lazily on each authentication**:
  the authenticator deletes expired nonces before inserting the current one.
  Nonces are retained for `2 × HERMES_REPLAY_WINDOW` seconds.
- **Duplicate-submission fingerprints** (`form_fingerprints`) — expire after
  their window (~10 min); `SubmissionGuard::pruneFingerprints()` deletes expired
  rows and is intended to be run periodically (e.g. from a maintenance task).
- **Enquiry IP hashes** — `EnquiryRepository::pruneIpHashes($days)` nulls the
  `ip_hash` on enquiries older than the retention window (default 30 days). Run
  it on a schedule to enforce the retention policy (see [crm.md](crm.md)).

The rate-limit sweep needs no scheduling of its own as long as the queue runs.
The fingerprint and IP-hash prunes have explicit methods; wire them into a
periodic maintenance command or cron as your data-retention policy requires.

## Data retention (`retention:*`)

Longer-lived history — CRM activities, preview analytics, completed queue jobs,
delivered webhooks, email events, the audit log, and orphaned upload/preview
files — is governed by a single configurable retention policy
(`RetentionService`) with one CLI:

```bash
php bin/console retention:check              # read-only: what WOULD be removed
php bin/console retention:cleanup --dry-run  # same counts, deletes nothing
php bin/console retention:cleanup --confirm  # actually delete
```

`retention:check` and `--dry-run` are strictly read-only; nothing is ever
deleted without `--confirm`. Each category's window is set in the `retention`
config block (env-overridable):

| Category | Env var | Default | Basis |
|----------|---------|---------|-------|
| CRM activities | `RETENTION_ACTIVITIES_DAYS` | 730 | `created_at` |
| Preview analytics | `RETENTION_PREVIEW_ANALYTICS_DAYS` | 180 | `occurred_at` |
| Completed queue jobs | `RETENTION_QUEUE_DAYS` | 30 | `completed_at`, `status='done'` |
| Delivered webhooks | `RETENTION_WEBHOOK_DAYS` | 90 | `delivered_at`, `status='delivered'` |
| Email events | `RETENTION_EMAIL_EVENTS_DAYS` | 90 | `event_at` |
| Audit log | `RETENTION_AUDIT_DAYS` | 365 | `created_at` |
| Orphaned uploads / preview dirs | `RETENTION_ORPHAN_GRACE_HOURS` | 24 | file mtime vs grace |

**A window of `0` (or blank) DISABLES that category** — it is never pruned, so
leaving a value empty can never silently wipe history. Orphaned-file scans only
consider files older than the grace period, so an in-flight upload or validation
is never mistaken for an orphan. Run nightly (see `deploy/cron.example`):

```cron
0 3 * * * php /var/www/breakfast/bin/console retention:cleanup --confirm
```

## Common failures and recovery

### SMTP is down

Enquiry emails are queue jobs. If SMTP is unavailable, `mailer()->send()`
throws, the queue records the failed attempt and **reschedules with backoff**.
No lead is lost — the enquiry is already persisted. When SMTP recovers, the
pending jobs deliver on their next attempt. Check `storage/logs` (`queue`
channel) and the failed-jobs list if delivery keeps failing past
`max_attempts`; fix the transport and retry the job from the Panel.

### A webhook endpoint is disabled

After **10 consecutive delivery failures**, an endpoint is auto-disabled
(`active = 0`, `disabled_reason = repeated_failures`) so it stops generating
work. To recover once the receiver is fixed:

1. Re-enable the endpoint (`active = 1`, clear `consecutive_fails` /
   `disabled_reason`).
2. **Redeliver** the affected deliveries — `WebhookDispatcher::redeliver()`
   resets a delivery to `pending` and re-enqueues the `webhook.deliver` job.
3. Watch the deliveries move to `delivered` (2xx) and `consecutive_fails` reset.

See [hermes-integration.md](hermes-integration.md) for the webhook mechanics.

### Disk full

A full disk breaks SQLite writes and log writes. Symptoms: form submissions and
Panel saves fail, the queue stalls, `PRAGMA` errors in logs. Recovery:

1. Free space — rotate/remove old `storage/logs/*.log`, clear
   `storage/cache/*` (safe; Kirby rebuilds it), remove old thumbnails under
   `public/media/*` (regenerated on demand).
2. Confirm the database is intact: `sqlite3 storage/database/crm.sqlite
   "PRAGMA integrity_check;"` (expect `ok`).
3. Restart the queue worker and verify `queue_depth` drains.
4. Add disk-usage alerting so it doesn't recur. WAL files
   (`crm.sqlite-wal`) can grow under load — a periodic
   `PRAGMA wal_checkpoint(TRUNCATE);` keeps them in check.

### The queue is not draining

Almost always the worker isn't running. Confirm the cron entry exists (or the
supervised process is up), run `php bin/console queue:run` by hand and watch it
process jobs, and check the `queue` log channel for handler errors.
