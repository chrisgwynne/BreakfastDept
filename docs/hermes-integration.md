# Hermes integration

Hermes is the studio's automation counterpart — a server-to-server integration
that reads site and CRM data, triages enquiries, files notes and tasks, and
prepares draft content. This document is the contract for that boundary.

Hermes is **not** a Kirby Panel user and does not use Kirby's REST API or KQL.
It talks only to a dedicated, versioned surface at `/api/breakfast/v1/…`, where
every request is HMAC-signed, every endpoint enforces one scope, and every call
is audited.

## Enabling Hermes

Hermes is off by default. Enable it in the environment:

```dotenv
HERMES_ENABLED=true
HERMES_REPLAY_WINDOW=300      # seconds of allowed clock skew (default 300)
```

When `HERMES_ENABLED` is not `true`, every request to `/api/breakfast/v1/…`
(except `/health`) returns `503 { "error": "hermes_disabled" }`.

## Credentials

Each credential is a single environment variable:

```
HERMES_KEY_<id>=<scopes>|<base64-secret>
```

- `<id>` — the credential identifier, sent in the `X-Hermes-Key` header. The
  part after `HERMES_KEY_` is the id (e.g. `HERMES_KEY_hermes-primary` → id
  `hermes-primary`).
- `<scopes>` — a comma-separated list of scope names (see below). Unknown
  scope names are silently dropped; a credential with no valid scopes is
  ignored entirely.
- `<base64-secret>` — the shared HMAC secret, base64-encoded. It is decoded to
  raw bytes before signing. An empty or invalid secret means the credential is
  ignored.

Example:

```dotenv
HERMES_KEY_hermes-primary=content:read,content:draft,crm:summary,crm:read,crm:notes,crm:tasks,crm:classify,webhooks:test|c2VjcmV0LWJ5dGVz...
```

Secrets live **only** in the environment. Kirby content stores at most a
credential id and an enabled/disabled indication — never the secret. The
`Credential` object refuses to expose its secret in debug output.

## The HMAC signing scheme

Signing is implemented in `src/Hermes/Signer.php`; verification and replay
protection in `src/Hermes/Authenticator.php`.

### Canonical string

The signature is computed over a canonical string built from five components,
newline-separated, with the body hashed so large/binary bodies do not bloat it:

```
canonical = METHOD "\n" PATH "\n" TIMESTAMP "\n" NONCE "\n" sha256(BODY)
```

- `METHOD` — upper-cased HTTP method (e.g. `POST`).
- `PATH` — the request path **as the server normalises it**: a leading slash,
  no trailing slash, e.g. `/crm/enquiries/{uuid}/note`. (The router trims and
  re-prefixes the path before both matching and signature verification, so the
  client must sign the same normalised form.)
- `TIMESTAMP` — Unix seconds, as sent in `X-Hermes-Timestamp`.
- `NONCE` — the unique per-request value, as sent in `X-Hermes-Nonce`.
- `sha256(BODY)` — hex SHA-256 of the raw request body (the empty string for
  GET requests with no body).

### Signature

```
signature = hex( HMAC_SHA256( secret, canonical ) )
```

sent in the `X-Hermes-Signature` header.

### Required headers

| Header | Meaning |
|---|---|
| `X-Hermes-Key` | Credential id |
| `X-Hermes-Timestamp` | Unix seconds |
| `X-Hermes-Nonce` | Unique per request |
| `X-Hermes-Signature` | Hex HMAC-SHA256 of the canonical string |

If any of the four is missing the request is rejected `401`
(`missing_auth_headers`).

### Verification, replay window and single-use nonce

The authenticator, in order:

1. Resolves the credential by id; unknown id → `401 unknown_credential`.
2. Requires a numeric timestamp; otherwise `401 bad_timestamp`.
3. Enforces the **replay window**: `abs(now − timestamp)` must be within
   `HERMES_REPLAY_WINDOW` seconds (default 300); otherwise
   `401 timestamp_out_of_window`.
4. Verifies the signature with `hash_equals()` — a **constant-time** compare —
   over the recomputed canonical string; mismatch → `401 bad_signature`.
5. Consumes the **nonce**: it is inserted into `hermes_nonces` (primary key).
   A duplicate insert means the nonce was already used → `401 nonce_reused`.
   Nonces are retained for `2 × replayWindow` seconds and expired ones are
   pruned opportunistically on each authentication.

Together, the timestamp window plus single-use nonce prevent replay: a captured
request cannot be re-sent (nonce reused), and cannot be held and replayed later
(timestamp out of window).

## Scopes

Every endpoint declares exactly one scope, enforced after authentication.

| Scope | Grants |
|---|---|
| `content:read` | Read site content summaries, changed pages, journal, projects |
| `content:draft` | Create unpublished drafts (journal / project / page) |
| `crm:summary` | Read the CRM dashboard summary metrics |
| `crm:read` | Read enquiries, a contact, opportunities, tasks |
| `crm:notes` | Add notes to enquiries / contacts / opportunities |
| `crm:tasks` | Create and update tasks |
| `crm:classify` | Classify an enquiry (status / spam / risk / summary) |
| `crm:export` | Full CRM export (privileged) |
| `webhooks:test` | Trigger a test webhook event |

`crm:export` is the privileged scope that would gate a full CRM export. It is
defined and validated, but **no endpoint in the current route table grants a
bulk export** — the read endpoints return only bounded, presented subsets. A
credential without `crm:export` therefore cannot export the CRM.

## Endpoint table

From `Api::routeTable()`. All paths are relative to `/api/breakfast/v1`.

| Method | Path | Scope | Purpose |
|---|---|---|---|
| GET | `/health` | _(none)_ | Liveness + queue depth. The only unauthenticated endpoint. |
| GET | `/site-summary` | `content:read` | Counts of projects / articles / services |
| GET | `/content/changed` | `content:read` | Recently modified pages (up to 50) |
| GET | `/journal` | `content:read` | Listed journal articles |
| GET | `/projects` | `content:read` | Listed projects |
| GET | `/crm/summary` | `crm:summary` | Dashboard metrics |
| GET | `/crm/enquiries` | `crm:read` | Recent enquiries (up to 50) |
| GET | `/crm/enquiries/{uuid}` | `crm:read` | A single enquiry (full) |
| GET | `/crm/contacts/{uuid}` | `crm:read` | A single contact (summary) |
| GET | `/crm/opportunities` | `crm:read` | Open opportunities (up to 50) |
| GET | `/crm/tasks` | `crm:read` | Open tasks (up to 50) |
| POST | `/crm/enquiries/{uuid}/classify` | `crm:classify` | Set status / spam / risk / summary |
| POST | `/crm/enquiries/{uuid}/note` | `crm:notes` | Add a note to an enquiry |
| POST | `/crm/contacts/{uuid}/note` | `crm:notes` | Add a note to a contact |
| POST | `/crm/opportunities/{uuid}/note` | `crm:notes` | Add a note to an opportunity |
| POST | `/crm/tasks` | `crm:tasks` | Create a task |
| PATCH | `/crm/tasks/{uuid}` | `crm:tasks` | Update a task |
| POST | `/drafts/journal` | `content:draft` | Create an unpublished article draft |
| POST | `/drafts/project` | `content:draft` | Create an unpublished project draft |
| POST | `/drafts/page` | `content:draft` | Create an unpublished page draft |
| POST | `/webhooks/test` | `webhooks:test` | Dispatch a `webhooks.test` event |

Every response includes a `request_id`. Authentication failures return `401`
with `{ "error": "unauthorized" }`; a valid credential lacking the required
scope returns `403` with `{ "error": "forbidden", "required_scope": "…" }`.
`/health` returns `status`, `time`, `queue_depth` and `failed_jobs`.

## What Hermes may and may not do

**May:**

- read bounded site content and CRM summaries/records;
- add notes, create/update tasks and classify enquiries;
- create **unpublished drafts** (`DraftFactory`) under the correct parent and
  template — the draft is never listed or published, and a human must review
  and publish it in the Panel.

**May not:**

- publish or delete any page — `DraftFactory` only ever creates drafts and
  whitelists a small set of content fields (`title`, `excerpt`, `summary`,
  `standfirst`, `meta_description`, `seo_title`, and a single rich-text body
  block); everything else in the request is ignored, and no existing content is
  modified;
- modify existing content, users or permissions;
- read secrets (they are never exposed by any endpoint);
- export the full CRM without the `crm:export` scope (not wired to any
  endpoint).

Classifying an enquiry as spam only sets flags — the record is never deleted or
silently discarded.

## The Brevo webhook is not a Hermes endpoint

The Brevo transactional webhook (`POST /api/breakfast/v1/webhooks/brevo`) shares
the `/api/breakfast/v1/…` path prefix but is **authenticated independently of
Hermes**. It is verified by a shared secret (the `X-Breakfast-Webhook-Token`
header matched against `BREVO_WEBHOOK_SECRET`) **or** HTTP Basic auth — **not**
by an HMAC signature and **not** by a Panel session. Its route is registered
before the Hermes catch-all so it matches first, and it works **even when
`HERMES_ENABLED=false`**. Conversely, Hermes has **no access to the Brevo API
key**, cannot grant or enable marketing consent, and cannot override or clear
email suppression — those remain the platform's own concern. See
[crm.md](crm.md) for the webhook's delivery-state and suppression behaviour.

## Outbound webhooks

Breakfast also **sends** signed events to registered endpoints
(`src/Hermes/WebhookDispatcher.php`).

### Events

| Event | Fired when |
|---|---|
| `enquiry.created` | A valid enquiry is persisted (from the form pipeline) |
| `content.published` | A page is changed to listed status |
| `content.updated` | A listed page is updated |
| `webhooks.test` | The `/webhooks/test` endpoint is called |

### Delivery is always queued

`dispatch()` never performs HTTP inline. For each active endpoint subscribed to
the event (an endpoint's `events` is a JSON array, or `["*"]` for all), it
records a `webhook_deliveries` row with a signed, versioned payload and pushes
a `webhook.deliver` queue job. The queue worker later calls `deliver()`, which
performs the HTTP POST. This means a slow or down endpoint can never block or
lose a form submission.

### Payload and signature

The POST body is the JSON event envelope:

```json
{
  "id": "<event uuid>",
  "type": "enquiry.created",
  "schema_version": "1",
  "created_at": "2026-07-21T09:30:00+00:00",
  "data": { "…": "…" }
}
```

Signature and headers on each delivery:

```
X-Breakfast-Signature = hex( HMAC_SHA256( WEBHOOK_SIGNING_SECRET, timestamp "." body ) )
```

| Header | Value |
|---|---|
| `X-Breakfast-Event` | Event type |
| `X-Breakfast-Event-Id` | Stable event UUID (for idempotency) |
| `X-Breakfast-Timestamp` | Unix seconds (part of the signed string) |
| `X-Breakfast-Signature` | HMAC-SHA256 of `timestamp.body` |
| `X-Breakfast-Schema` | Schema version (`1`) |

Receivers should recompute the signature over `timestamp + "." + rawBody` with
the shared `WEBHOOK_SIGNING_SECRET`, compare in constant time, and reject
timestamps that are too old. De-duplicate on `X-Breakfast-Event-Id`.

### Retries, backoff and auto-disable

- A delivery is successful on any 2xx response; the endpoint's
  `consecutive_fails` counter resets to 0.
- On a non-2xx response or transport error, the attempt is recorded and
  `deliver()` throws, so the **queue** reschedules with exponential backoff
  (10s, 60s, 300s, 900s, 3600s) up to `max_attempts` (default 5).
- Each failure also increments the endpoint's `consecutive_fails`. After
  **10 consecutive failures** the endpoint is automatically disabled
  (`active = 0`, `disabled_reason = repeated_failures`) so a dead endpoint
  stops generating work.

### Redelivery

`redeliver($deliveryUuid)` resets a delivery to `pending`, clears its last
error and re-enqueues it — used from the Panel to replay a delivery after a
receiver is fixed (re-enable the endpoint first). See
[operations.md](operations.md).

## Audit trail

Every Hermes request — success, denial or error — is written to `hermes_audit`
(`src/Hermes/AuditLog.php`): the credential id, scope, method, endpoint,
target, result (`ok` / `denied` / `error` / `not_found`), status code, a
request id and safe metadata. The audit log **never** stores secrets,
signatures, nonces or authentication headers — metadata keys containing
`authorization`, `signature`, `secret`, `token`, `password` or `nonce` are
stripped before writing.

## Key rotation

1. Generate a new credential and add it as an additional
   `HERMES_KEY_<new-id>=…` variable (do not remove the old one yet).
   `php bin/console hermes:keys <new-id> <comma,scopes>` produces the line, or use
   *Hermes → Credentials → Generate credential* in the admin.
2. Roll the new id/secret out to Hermes and confirm signed requests succeed
   (check the audit log for `ok` results under the new credential id).
3. Remove the old `HERMES_KEY_<old-id>` variable and reload the environment.
4. Because a compromised secret is only ever in the environment, rotation is a
   config change and a restart — no code deploy is required. Rotate immediately
   if a secret is ever exposed.

## Managing Hermes from the standalone admin

Day-to-day Hermes operations live in the standalone Breakfast Admin app, under
**Hermes** (nav, below Operations) — not the Kirby Panel. It reads the backend
described above and never exposes a secret.

**Access.** The nav entry and every `/breakfast-admin/api/v1/hermes/*` route are
gated server-side: viewing requires the CRM *manage* grant
(`PanelGate::canViewHermes`); running a self-test or generating a credential line
is admin-only (`canManageHermes`). Hiding the nav is never the control.

**What each screen shows.**

- **Overview** — configured/enabled state, endpoint, credential + scope counts,
  replay window, 24-hour request/failure counts, last success/failure and recent
  activity. When Hermes is off or unconfigured it shows a guided setup checklist.
- **Credentials** — a read-only inventory (id, scopes, last used, request count),
  labelled *deployment-managed*. The shared secret is never shown.
- **Scopes** — all 13 scopes grouped by domain with a plain-English description,
  a risk level and read/write/draft kind, and which credentials hold each.
- **Activity** — the `hermes_audit` trail, filterable by result and paginated,
  with a redacted per-request detail drawer.
- **Diagnostics** — severity cards (enabled, credentials, audit + nonce storage,
  queue, 24-hour failures) and the read-only deployment settings.

**Settings: deployment-managed vs application-managed.** Every Hermes setting is
currently *deployment-managed* — it lives in the environment and changes only on
a redeploy. The admin surfaces these read-only and never pretends otherwise:

| Setting | Env var | Editable in UI |
| --- | --- | --- |
| Enabled | `HERMES_ENABLED` | No — deployment |
| Replay window | `HERMES_REPLAY_WINDOW` | No — deployment |
| Credentials (id, scopes, secret) | `HERMES_KEY_<id>` | No — deployment |

**Create / rotate a credential.** *Hermes → Credentials → Generate credential*
(admin only) produces the exact `HERMES_KEY_<id>=<scopes>|<secret>` line with a
fresh 32-byte secret, shown **once**. Add it to your deployment `.env` and
redeploy. The UI writes nothing at runtime and never stores or logs the secret;
the generation event is audited without it. This mirrors `bin/console hermes:keys`.

**Revoke a credential.** Remove its `HERMES_KEY_<id>` variable and redeploy.

**Test the integration.** *Hermes → Test connection* (admin only) signs a request
server-side with the chosen credential's secret and runs the real authenticator —
configuration, credential resolution, signature, timestamp window, single-use
nonce and scope enforcement — reporting each step pass/fail. It executes no
endpoint and never reveals the secret or signature.

**Disable Hermes immediately.** Set `HERMES_ENABLED=false` and redeploy; every
request is then rejected with `503`. The route guard also short-circuits before
any handler runs.

**Inspect failed requests.** *Hermes → Activity → filter "denied"/"error"*, then
open an entry for the redacted detail (credential, scope, endpoint, result,
status, request id, safe metadata).

**What Hermes can and cannot access** is unchanged from *What Hermes may and may
not do* above: it can read and draft within its granted scopes; it can never
publish, send email, delete, reveal secrets (Brevo keys, Hermes secrets, password
hashes, session data, preview passwords), change users/permissions, run shell
commands or reach arbitrary filesystem paths.

## Worked example — signing a request

Pseudocode for signing `POST /crm/enquiries/<uuid>/note`:

```
method   = "POST"
path     = "/crm/enquiries/8f3.../note"       # leading slash, no trailing slash
body     = '{"note":"Followed up by email."}'
ts       = unix_seconds_now()
nonce    = random_hex(16)

bodyHash  = sha256_hex(body)
canonical = method + "\n" + path + "\n" + ts + "\n" + nonce + "\n" + bodyHash
signature = hmac_sha256_hex(secret_bytes, canonical)   # secret = base64_decode(secret_b64)
```

As a curl invocation (illustrative — real clients compute the signature
programmatically):

```bash
BODY='{"note":"Followed up by email."}'
TS=$(date +%s)
NONCE=$(openssl rand -hex 16)
PATH_='/crm/enquiries/8f3.../note'
BODY_HASH=$(printf '%s' "$BODY" | openssl dgst -sha256 | awk '{print $2}')
CANON=$(printf 'POST\n%s\n%s\n%s\n%s' "$PATH_" "$TS" "$NONCE" "$BODY_HASH")
SIG=$(printf '%s' "$CANON" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST "https://breakfast.example/api/breakfast/v1$PATH_" \
  -H "X-Hermes-Key: hermes-primary" \
  -H "X-Hermes-Timestamp: $TS" \
  -H "X-Hermes-Nonce: $NONCE" \
  -H "X-Hermes-Signature: $SIG" \
  -H "Content-Type: application/json" \
  --data "$BODY"
```

> Note: the secret must be the **raw bytes** obtained by base64-decoding the
> stored secret. The example above uses `$SECRET` as-is for brevity; a real
> client decodes `<base64-secret>` first.
