<?php

declare(strict_types=1);

namespace Breakfast\Platform\Admin;

use Breakfast\Platform\Hermes\Authenticator;
use Breakfast\Platform\Hermes\Credential;
use Breakfast\Platform\Hermes\CredentialStore;
use Breakfast\Platform\Hermes\Scopes;
use Breakfast\Platform\Hermes\Signer;
use Breakfast\Platform\Support\Platform;

/**
 * Read + diagnostics service backing the standalone admin's Hermes console.
 *
 * Hermes is the HMAC-authenticated integration API. Its credentials are
 * DEPLOYMENT-managed: each is an environment variable
 * (HERMES_KEY_<id>=<scopes>|<base64-secret>) resolved by CredentialStore. Secrets
 * live only in the environment and this service NEVER returns them. It reads the
 * safe inventory (ids, scopes), derives health/usage from the immutable
 * hermes_audit trail, and can run a non-destructive connection self-test by
 * signing a request server-side and verifying the full auth chain — without ever
 * exposing the secret or signature.
 */
final class HermesAdmin
{
    private CredentialStore $store;

    public function __construct(private readonly Platform $platform)
    {
        $this->store = new CredentialStore();
    }

    /** @return array<string,mixed> The Hermes config block (enabled, replayWindow). */
    private function config(): array
    {
        $config = $this->platform->config('hermes', []);

        return is_array($config) ? $config : [];
    }

    public function enabled(): bool
    {
        return (bool) ($this->config()['enabled'] ?? false);
    }

    private function replayWindow(): int
    {
        return (int) ($this->config()['replayWindow'] ?? 300);
    }

    // ==================================================================
    // Overview
    // ==================================================================

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $credentials = $this->store->all();
        $health      = $this->health();

        return [
            'enabled'          => $this->enabled(),
            'configured'       => $credentials !== [],
            'credential_count' => count($credentials),
            'scope_count'      => count(Scopes::all()),
            'endpoint'         => 'api/breakfast/v1',
            'replay_window'    => $this->replayWindow(),
            'production'       => $this->platform->isProduction(),
            'status'           => $health['status'],
            'last_success'     => $health['last_success'],
            'last_failure'     => $health['last_failure'],
            'requests_24h'     => $health['requests_24h'],
            'failures_24h'     => $health['failures_24h'],
            'recent'           => array_slice($this->activity([], 1, 6)['items'], 0, 6),
        ];
    }

    // ==================================================================
    // Credentials (read-only inventory — never secrets)
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function credentials(): array
    {
        $usage = $this->usageByCredential();
        $out   = [];
        foreach ($this->store->all() as $credential) {
            $u = $usage[$credential->id] ?? null;
            $out[] = [
                'id'           => $credential->id,
                'scopes'       => $credential->scopes,
                'scope_count'  => count($credential->scopes),
                'managed'      => 'deployment', // env-backed; not editable at runtime
                'status'       => 'active',      // present in the environment
                'last_used'    => $u['last_used'] ?? null,
                'last_result'  => $u['last_result'] ?? null,
                'request_count' => $u['count'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Latest usage per credential id, derived from the audit trail.
     *
     * @return array<string,array{last_used:string,last_result:string,count:int}>
     */
    private function usageByCredential(): array
    {
        if ($this->platform->db()->tableExists('hermes_audit') === false) {
            return [];
        }
        $rows = $this->platform->db()->all(
            "SELECT credential_id,
                    MAX(created_at) AS last_used,
                    COUNT(*)        AS n
             FROM hermes_audit
             WHERE credential_id NOT LIKE 'panel:%'
             GROUP BY credential_id"
        );
        $out = [];
        foreach ($rows as $r) {
            $id = (string) ($r['credential_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $last = $this->platform->db()->one(
                'SELECT result FROM hermes_audit WHERE credential_id = :c ORDER BY created_at DESC LIMIT 1',
                ['c' => $id]
            );
            $out[$id] = [
                'last_used'   => (string) ($r['last_used'] ?? ''),
                'last_result' => (string) ($last['result'] ?? ''),
                'count'       => (int) ($r['n'] ?? 0),
            ];
        }

        return $out;
    }

    // ==================================================================
    // Scopes (catalogue grouped by domain, with risk + who holds each)
    // ==================================================================

    /** @return array<string,mixed> */
    public function scopes(): array
    {
        $holders = $this->scopeHolders();
        $groups  = [];
        foreach (self::SCOPE_META as $scope => $meta) {
            $domain = $meta['domain'];
            $groups[$domain] ??= ['domain' => $domain, 'scopes' => []];
            $groups[$domain]['scopes'][] = [
                'scope'       => $scope,
                'label'       => $meta['label'],
                'description' => $meta['description'],
                'risk'        => $meta['risk'],
                'kind'        => $meta['kind'],
                'granted_to'  => $holders[$scope] ?? [],
            ];
        }

        return ['groups' => array_values($groups), 'total' => count(self::SCOPE_META)];
    }

    /** @return array<string,list<string>> scope => credential ids that hold it */
    private function scopeHolders(): array
    {
        $out = [];
        foreach (Scopes::all() as $scope) {
            $out[$scope] = [];
        }
        foreach ($this->store->all() as $credential) {
            foreach ($credential->scopes as $scope) {
                $out[$scope][] = $credential->id;
            }
        }

        return $out;
    }

    /**
     * Plain-English scope catalogue: domain, label, description, risk and whether
     * the scope only reads or can draft/write. Hermes can never publish, send,
     * delete, reveal secrets or alter access — the write scopes are deliberately
     * limited to notes, tasks, classification and unpublished drafts.
     *
     * @var array<string,array{domain:string,label:string,description:string,risk:string,kind:string}>
     */
    private const SCOPE_META = [
        Scopes::CONTENT_READ     => ['domain' => 'Content',  'label' => 'Read content',        'description' => 'Read published site content and what has changed.',                'risk' => 'low',    'kind' => 'read'],
        Scopes::CONTENT_DRAFT    => ['domain' => 'Content',  'label' => 'Draft content',       'description' => 'Create unpublished draft pages, journal posts and projects for review.', 'risk' => 'medium', 'kind' => 'draft'],
        Scopes::CRM_SUMMARY      => ['domain' => 'CRM',      'label' => 'CRM summary',         'description' => 'Read high-level CRM counts and summaries only.',                    'risk' => 'low',    'kind' => 'read'],
        Scopes::CRM_READ         => ['domain' => 'CRM',      'label' => 'Read CRM',            'description' => 'Read enquiries, contacts, opportunities and tasks.',                'risk' => 'medium', 'kind' => 'read'],
        Scopes::CRM_NOTES        => ['domain' => 'CRM',      'label' => 'Add CRM notes',       'description' => 'Attach notes to CRM records. Never edits or deletes data.',         'risk' => 'medium', 'kind' => 'write'],
        Scopes::CRM_TASKS        => ['domain' => 'CRM',      'label' => 'Manage tasks',        'description' => 'Create and update follow-up tasks.',                                'risk' => 'medium', 'kind' => 'write'],
        Scopes::CRM_CLASSIFY     => ['domain' => 'CRM',      'label' => 'Classify enquiries',  'description' => 'Triage/classify incoming enquiries (status, spam, summary).',       'risk' => 'medium', 'kind' => 'write'],
        Scopes::CRM_EXPORT       => ['domain' => 'CRM',      'label' => 'Export CRM',          'description' => 'Read CRM data in bulk for export. Grant with care.',                'risk' => 'high',   'kind' => 'read'],
        Scopes::CRM_LEADS_CREATE     => ['domain' => 'CRM',  'label' => 'Create leads',        'description' => 'Create a new lead (contact + optional company + enquiry) via the same validated, audited service a human uses. Never deletes, merges or edits existing records.', 'risk' => 'medium', 'kind' => 'write'],
        Scopes::CRM_CONTACTS_CREATE  => ['domain' => 'CRM',  'label' => 'Create contacts',     'description' => 'Create a new contact (de-duplicated by email, optional company link). Never deletes or merges.', 'risk' => 'medium', 'kind' => 'write'],
        Scopes::CRM_COMPANIES_CREATE => ['domain' => 'CRM',  'label' => 'Create companies',    'description' => 'Create (or find by name) a company. Never deletes or merges.',       'risk' => 'medium', 'kind' => 'write'],
        Scopes::WEBHOOKS_TEST    => ['domain' => 'Webhooks', 'label' => 'Test webhooks',       'description' => 'Trigger a harmless test webhook to verify wiring.',                 'risk' => 'low',    'kind' => 'write'],
        Scopes::PREVIEWS_SUMMARY => ['domain' => 'Previews', 'label' => 'Previews summary',    'description' => 'Read client-preview counts by status.',                            'risk' => 'low',    'kind' => 'read'],
        Scopes::PREVIEWS_READ    => ['domain' => 'Previews', 'label' => 'Read previews',       'description' => 'Read client-preview details and metadata.',                        'risk' => 'medium', 'kind' => 'read'],
        Scopes::PREVIEWS_ANALYSE => ['domain' => 'Previews', 'label' => 'Analyse previews',    'description' => 'Analyse client-preview content and engagement.',                    'risk' => 'medium', 'kind' => 'read'],
        Scopes::PREVIEWS_DRAFT   => ['domain' => 'Previews', 'label' => 'Draft previews',      'description' => 'Create an UNPUBLISHED preview draft awaiting human review. Never publishes, uploads files, sets passwords or sends invitations.', 'risk' => 'medium', 'kind' => 'draft'],
        Scopes::CALENDAR_CREATE  => ['domain' => 'Calendar', 'label' => 'Create events',       'description' => 'Create a TENTATIVE internal calendar event (follow-up, reminder, proposed call) for review. Never invites external attendees, emails clients, cancels/reschedules confirmed meetings or deletes events.', 'risk' => 'medium', 'kind' => 'draft'],
    ];

    // ==================================================================
    // Activity (audit log — redacted, filtered, paginated)
    // ==================================================================

    /**
     * @param array<string,string> $filters supported: result, credential
     * @return array<string,mixed>
     */
    public function activity(array $filters, int $page = 1, int $perPage = 25): array
    {
        if ($this->platform->db()->tableExists('hermes_audit') === false) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage];
        }

        $where  = ['1 = 1'];
        $params = [];
        if (($filters['result'] ?? '') !== '') {
            $where[]          = 'result = :result';
            $params['result'] = $filters['result'];
        }
        if (($filters['credential'] ?? '') !== '') {
            $where[]        = 'credential_id = :cred';
            $params['cred'] = $filters['credential'];
        }
        $clause = implode(' AND ', $where);

        $total = (int) $this->platform->db()->scalar("SELECT COUNT(*) FROM hermes_audit WHERE $clause", $params);

        $perPage        = max(1, min(100, $perPage));
        $page           = max(1, $page);
        $params['lim']  = $perPage;
        $params['off']  = ($page - 1) * $perPage;

        $rows = $this->platform->db()->all(
            "SELECT * FROM hermes_audit WHERE $clause ORDER BY created_at DESC LIMIT :lim OFFSET :off",
            $params
        );

        return [
            'items'    => array_map([$this, 'auditRow'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string,mixed>|null */
    public function activityDetail(string $uuid): ?array
    {
        if ($this->platform->db()->tableExists('hermes_audit') === false) {
            return null;
        }
        $row = $this->platform->db()->one('SELECT * FROM hermes_audit WHERE uuid = :u', ['u' => $uuid]);

        return $row === null ? null : $this->auditRow($row, true);
    }

    /**
     * Map an audit row to a safe, redacted shape. The metadata column is already
     * scrubbed of secrets on write; we still whitelist what leaves the server.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function auditRow(array $r, bool $detail = false): array
    {
        $out = [
            'id'          => (string) ($r['uuid'] ?? ''),
            'at'          => (string) ($r['created_at'] ?? ''),
            'credential'  => (string) ($r['credential_id'] ?? ''),
            'scope'       => (string) ($r['scope'] ?? ''),
            'method'      => (string) ($r['method'] ?? ''),
            'endpoint'    => (string) ($r['endpoint'] ?? ''),
            'result'      => (string) ($r['result'] ?? ''),
            'status'      => (int) ($r['status_code'] ?? 0),
            'request_id'  => (string) ($r['request_id'] ?? ''),
        ];
        if ($detail) {
            $out['target_type'] = (string) ($r['target_type'] ?? '');
            $out['target_uuid'] = (string) ($r['target_uuid'] ?? '');
            $meta = json_decode((string) ($r['metadata'] ?? '{}'), true);
            $out['metadata'] = is_array($meta) ? $meta : [];
        }

        return $out;
    }

    // ==================================================================
    // Health / diagnostics
    // ==================================================================

    /** @return array<string,mixed> */
    public function health(): array
    {
        $configured = $this->store->all() !== [];
        $enabled    = $this->enabled();
        $hasAudit   = $this->platform->db()->tableExists('hermes_audit');

        $lastSuccess = $lastFailure = null;
        $requests24h = $failures24h = 0;
        if ($hasAudit) {
            $lastSuccess = $this->platform->db()->scalar(
                "SELECT MAX(created_at) FROM hermes_audit WHERE result = 'ok' AND credential_id NOT LIKE 'panel:%'"
            );
            $lastFailure = $this->platform->db()->scalar(
                "SELECT MAX(created_at) FROM hermes_audit WHERE result != 'ok' AND credential_id NOT LIKE 'panel:%'"
            );
            $since = date('c', time() - 86400);
            $requests24h = (int) $this->platform->db()->scalar(
                "SELECT COUNT(*) FROM hermes_audit WHERE created_at >= :s AND credential_id NOT LIKE 'panel:%'",
                ['s' => $since]
            );
            $failures24h = (int) $this->platform->db()->scalar(
                "SELECT COUNT(*) FROM hermes_audit WHERE created_at >= :s AND result != 'ok' AND credential_id NOT LIKE 'panel:%'",
                ['s' => $since]
            );
        }

        // Severity: disabled | misconfigured | degraded | attention | healthy.
        if (!$enabled) {
            $status = 'disabled';
        } elseif (!$configured) {
            $status = 'misconfigured';
        } elseif ($requests24h > 0 && $failures24h / max(1, $requests24h) >= 0.5) {
            $status = 'degraded';
        } elseif ($failures24h > 0) {
            $status = 'attention';
        } else {
            $status = 'healthy';
        }

        return [
            'status'            => $status,
            'enabled'           => $enabled,
            'configured'        => $configured,
            'credentials'       => count($this->store->all()),
            'audit_storage'     => $hasAudit,
            'nonce_storage'     => $this->platform->db()->tableExists('hermes_nonces'),
            'queue_pending'     => $this->platform->queue()->pendingCount(),
            'queue_failed'      => $this->platform->queue()->failedCount(),
            'replay_window'     => $this->replayWindow(),
            'last_success'      => $lastSuccess !== null ? (string) $lastSuccess : null,
            'last_failure'      => $lastFailure !== null ? (string) $lastFailure : null,
            'requests_24h'      => $requests24h,
            'failures_24h'      => $failures24h,
        ];
    }

    // ==================================================================
    // Settings (deployment-managed; read-only in the UI)
    // ==================================================================

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return [
            'deployment' => [
                [
                    'key'     => 'HERMES_ENABLED',
                    'label'   => 'Enabled',
                    'value'   => $this->enabled() ? 'true' : 'false',
                    'note'    => 'Master switch. When off, every Hermes request is rejected with 503.',
                ],
                [
                    'key'     => 'HERMES_REPLAY_WINDOW',
                    'label'   => 'Replay window (seconds)',
                    'value'   => (string) $this->replayWindow(),
                    'note'    => 'How far a request timestamp may be from server time before it is rejected.',
                ],
                [
                    'key'     => 'HERMES_KEY_*',
                    'label'   => 'Credentials',
                    'value'   => count($this->store->all()) . ' configured',
                    'note'    => 'Each credential is one HERMES_KEY_<id> env var: <scopes>|<base64 secret>. Secrets stay in the environment.',
                ],
            ],
        ];
    }

    // ==================================================================
    // Connection self-test (non-destructive)
    // ==================================================================

    /**
     * Sign a canonical request server-side with the credential's secret and run
     * it through the real Authenticator, verifying the whole chain end to end:
     * config present, credential resolved, signature valid, timestamp in window,
     * nonce accepted, and at least one scope enforceable. Executes NO endpoint
     * handler and never returns the secret or signature.
     *
     * @return array<string,mixed>
     */
    public function selfTest(string $credentialId, string $actor): array
    {
        $steps = [];
        $add   = static function (array &$steps, string $key, string $label, bool $ok, string $detail = '') {
            $steps[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
        };

        $configOk = $this->config() !== [];
        $add($steps, 'config', 'Configuration present', $configOk);
        $add(
            $steps,
            'enabled',
            'Integration enabled',
            $this->enabled(),
            $this->enabled() ? '' : 'HERMES_ENABLED is false — live requests are rejected.'
        );

        $credential = $this->store->find($credentialId);
        $add(
            $steps,
            'credential',
            'Credential resolved',
            $credential instanceof Credential,
            $credential instanceof Credential ? $credentialId : 'No such credential.'
        );

        $signatureOk = $timestampOk = $nonceOk = $scopeOk = false;
        if ($credential instanceof Credential) {
            $method    = 'GET';
            $path      = '/__selftest';
            $body      = '';
            $timestamp = (string) time();
            $nonce     = 'selftest-' . bin2hex(random_bytes(12));
            $canonical = Signer::canonical($method, $path, $timestamp, $nonce, $body);
            $signature = Signer::sign($credential->secret(), $canonical);

            $auth = new Authenticator($this->store, $this->platform->db(), $this->replayWindow());
            $result = $auth->authenticate($method, $path, $body, [
                'x-hermes-key'       => $credential->id,
                'x-hermes-timestamp' => $timestamp,
                'x-hermes-nonce'     => $nonce,
                'x-hermes-signature' => $signature,
            ]);

            // A successful authenticate() means signature + timestamp + nonce all passed.
            $signatureOk = $result->ok;
            $timestampOk = $result->ok || $result->error !== 'timestamp_out_of_window';
            $nonceOk     = $result->ok || $result->error !== 'nonce_reused';
            $scopeOk     = $credential->scopes !== [] && $auth->authorize($credential, $credential->scopes[0]);
        }

        $add($steps, 'signature', 'Signature verified', $signatureOk);
        $add($steps, 'timestamp', 'Timestamp within window', $timestampOk);
        $add($steps, 'nonce', 'Nonce accepted (single-use)', $nonceOk);
        $add($steps, 'scope', 'Scope enforcement works', $scopeOk);

        $ok = $configOk && $signatureOk && $timestampOk && $nonceOk && $scopeOk;

        // Record the diagnostic in the same audit trail (no secrets).
        $this->platform->audit()->event(
            'hermes.selftest',
            'hermes_credential',
            null,
            $actor,
            ['credential' => $credentialId, 'result' => $ok ? 'ok' : 'failed']
        );

        return ['ok' => $ok, 'credential' => $credentialId, 'steps' => $steps];
    }

    // ==================================================================
    // Credential env-line generator (deployment flow — secret shown once)
    // ==================================================================

    /**
     * Produce the exact .env line for a new or rotated credential, with a freshly
     * generated 32-byte secret. This mirrors the `hermes:keys` CLI. The secret is
     * returned ONCE for the operator to place in their deployment environment; it
     * is never persisted or written to the audit log. Actual activation happens on
     * the next deploy — this method changes nothing at runtime.
     *
     * @param list<string> $scopes
     * @return array<string,mixed>
     */
    public function generateCredentialLine(string $id, array $scopes, string $actor): array
    {
        $id = preg_replace('/[^A-Za-z0-9_]/', '', $id) ?? '';
        if ($id === '') {
            throw new ApiException(422, 'A credential id is required (letters, numbers, underscore).', 'invalid', ['id' => 'Required.']);
        }
        $scopes = array_values(array_filter($scopes, [Scopes::class, 'isValid']));
        if ($scopes === []) {
            throw new ApiException(422, 'Select at least one valid scope.', 'invalid', ['scopes' => 'Required.']);
        }

        $rotating = $this->store->find($id) !== null;
        $secret   = base64_encode(random_bytes(32));
        $envLine  = 'HERMES_KEY_' . $id . '=' . implode(',', $scopes) . '|' . $secret;

        // Audit the generation event — but NEVER the secret.
        $this->platform->audit()->event(
            $rotating ? 'hermes.credential.rotate' : 'hermes.credential.generate',
            'hermes_credential',
            null,
            $actor,
            ['credential' => $id, 'scopes' => implode(',', $scopes)]
        );

        return [
            'id'       => $id,
            'scopes'   => $scopes,
            'rotating' => $rotating,
            'env_line' => $envLine,
            'instructions' => 'Add this line to your deployment .env (replacing any existing HERMES_KEY_' . $id
                . '), keep the secret private, then redeploy. The secret is shown once and is not stored here.',
        ];
    }
}
