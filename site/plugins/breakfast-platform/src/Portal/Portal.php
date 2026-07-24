<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portal;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Client portal foundation & identity — a separate trust boundary from staff.
 *
 * A portal identity is a client login tied to a CRM contact. Access is
 * passwordless: a client requests a single-use, short-lived magic link by email;
 * consuming it mints an opaque, server-side session whose token is stored only as
 * a sha256 hash. Access grants scope exactly which projects an identity may see —
 * nothing is visible without a grant. Email enumeration is avoided: requesting a
 * link for an unknown/suspended address returns the same shape as success.
 *
 * This service NEVER grants staff access and is unreachable from Hermes.
 */
final class Portal
{
    private const MAGIC_LINK_TTL = 900;        // 15 minutes
    private const SESSION_TTL = 2592000;       // 30 days (absolute)

    public function __construct(
        private readonly Platform $platform,
    ) {
    }

    private function db(): Database
    {
        return $this->platform->db();
    }

    // ==================================================================
    // Staff-side: identities + grants
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createIdentity(array $data, string $actor): array
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalException(422, 'A valid email is required.');
        }
        if ($this->db()->one('SELECT uuid FROM portal_identities WHERE email_key = :e', ['e' => $email]) !== null) {
            throw new PortalException(409, 'A portal identity already exists for this email.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->db()->run(
            'INSERT INTO portal_identities (uuid, email, email_key, display_name, contact_uuid, company_uuid, status, created_by, created_at, updated_at)
             VALUES (:uuid, :email, :key, :name, :contact, :company, \'active\', :actor, :now, :now)',
            [
                'uuid' => $uuid, 'email' => (string) ($data['email'] ?? $email), 'key' => $email,
                'name' => (string) ($data['display_name'] ?? ''), 'contact' => $this->nullable($data['contact_uuid'] ?? null),
                'company' => $this->nullable($data['company_uuid'] ?? null), 'actor' => $actor, 'now' => $now,
            ]
        );
        $this->event($uuid, 'identity_created', 'Portal identity created', '');

        return $this->findIdentity($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function findIdentity(string $uuid): ?array
    {
        $id = $this->db()->one('SELECT * FROM portal_identities WHERE uuid = :u', ['u' => $uuid]);
        if ($id === null) {
            return null;
        }
        unset($id['email_key']);
        $id['grants'] = $this->db()->all("SELECT entity_type, entity_uuid, role FROM portal_access_grants WHERE identity_uuid = :u AND revoked = 0", ['u' => $uuid]);

        return $id;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listIdentities(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['contact_uuid'])) {
            $where[] = 'contact_uuid = :c';
            $params['c'] = (string) $filters['contact_uuid'];
        }
        $rows = $this->db()->all('SELECT uuid, email, display_name, contact_uuid, company_uuid, status, last_login_at, created_at FROM portal_identities WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 200', $params);

        return $rows;
    }

    /** @return array<string,mixed> */
    public function grantAccess(string $identityUuid, string $entityType, string $entityUuid, string $role, string $actor): array
    {
        if ($this->findIdentity($identityUuid) === null) {
            throw new PortalException(404, 'Portal identity not found.');
        }
        if (!in_array($role, ['viewer', 'approver'], true)) {
            throw new PortalException(422, 'Unknown access role.');
        }
        $now = Clock::nowIso();
        // Upsert: re-granting reactivates a revoked grant and updates the role.
        $existing = $this->db()->one('SELECT uuid FROM portal_access_grants WHERE identity_uuid = :i AND entity_type = :t AND entity_uuid = :e', ['i' => $identityUuid, 't' => $entityType, 'e' => $entityUuid]);
        if ($existing !== null) {
            $this->db()->run('UPDATE portal_access_grants SET role = :r, revoked = 0 WHERE uuid = :u', ['r' => $role, 'u' => (string) $existing['uuid']]);
        } else {
            $this->db()->run('INSERT INTO portal_access_grants (uuid, identity_uuid, entity_type, entity_uuid, role, created_by, created_at) VALUES (:uuid, :i, :t, :e, :r, :actor, :now)', ['uuid' => Uuid::v4(), 'i' => $identityUuid, 't' => $entityType, 'e' => $entityUuid, 'r' => $role, 'actor' => $actor, 'now' => $now]);
        }
        $this->event($identityUuid, 'access_granted', $entityType . ':' . $entityUuid . ' (' . $role . ')', '');

        return $this->findIdentity($identityUuid) ?? [];
    }

    /**
     * Operator convenience: create-or-find a portal identity for an email and
     * grant it access to a project in one idempotent step, returning the identity
     * and a fresh sign-in link for the operator to send.
     *
     * @param array<string,mixed> $data email (required), display_name, contact_uuid, company_uuid, role
     * @return array{identity:array<string,mixed>,url:string}
     */
    public function inviteToProject(string $projectUuid, array $data, string $siteUrl, string $actor): array
    {
        if ($this->db()->one('SELECT uuid FROM projects WHERE uuid = :u', ['u' => $projectUuid]) === null) {
            throw new PortalException(404, 'Project not found.');
        }
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalException(422, 'A valid email is required.');
        }
        $existing = $this->db()->one('SELECT uuid FROM portal_identities WHERE email_key = :e', ['e' => $email]);
        $identityUuid = $existing !== null
            ? (string) $existing['uuid']
            : (string) $this->createIdentity($data, $actor)['uuid'];
        $this->grantAccess($identityUuid, 'project', $projectUuid, (string) ($data['role'] ?? 'viewer'), $actor);
        $link = $this->requestLogin($email);
        $url = ($link['sent'] && $link['token'] !== null) ? rtrim($siteUrl, '/') . '/portal/verify/' . $link['token'] : '';

        return ['identity' => $this->findIdentity($identityUuid) ?? [], 'url' => $url];
    }

    public function revokeAccess(string $identityUuid, string $entityType, string $entityUuid, string $actor): void
    {
        $this->db()->run('UPDATE portal_access_grants SET revoked = 1 WHERE identity_uuid = :i AND entity_type = :t AND entity_uuid = :e', ['i' => $identityUuid, 't' => $entityType, 'e' => $entityUuid]);
        $this->event($identityUuid, 'access_revoked', $entityType . ':' . $entityUuid, '');
    }

    /** @return array<string,mixed> */
    public function setStatus(string $identityUuid, string $status, string $actor): array
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new PortalException(422, 'Unknown status.');
        }
        $this->db()->run('UPDATE portal_identities SET status = :s, updated_at = :now, revision = revision + 1 WHERE uuid = :u', ['s' => $status, 'now' => Clock::nowIso(), 'u' => $identityUuid]);
        if ($status === 'suspended') {
            // Suspending kills every live session immediately.
            $this->db()->run('UPDATE portal_sessions SET revoked = 1 WHERE identity_uuid = :u', ['u' => $identityUuid]);
        }
        $this->event($identityUuid, 'status_' . $status, 'Identity ' . $status, '');

        return $this->findIdentity($identityUuid) ?? [];
    }

    // ==================================================================
    // Client-side: passwordless auth
    // ==================================================================

    /**
     * Request a magic link. To avoid email enumeration this always reports
     * success; a link is only actually minted for an active identity. Returns the
     * raw token ONLY when one was minted (for the caller to build + send the URL).
     *
     * @return array{sent:bool,identity_uuid:?string,token:?string}
     */
    public function requestLogin(string $email, string $ipHash = ''): array
    {
        $key = strtolower(trim($email));
        $identity = $key === '' ? null : $this->db()->one("SELECT uuid, status FROM portal_identities WHERE email_key = :e", ['e' => $key]);
        if ($identity === null || (string) $identity['status'] !== 'active') {
            return ['sent' => false, 'identity_uuid' => null, 'token' => null];
        }
        $identityUuid = (string) $identity['uuid'];
        $raw = bin2hex(random_bytes(24));
        $now = Clock::nowIso();
        $this->db()->run(
            'INSERT INTO portal_magic_links (uuid, identity_uuid, token_hash, email, ip_hash, expires_at, created_at) VALUES (:uuid, :i, :h, :email, :ip, :exp, :now)',
            ['uuid' => Uuid::v4(), 'i' => $identityUuid, 'h' => $this->hash($raw), 'email' => $key, 'ip' => $ipHash, 'exp' => date('c', time() + self::MAGIC_LINK_TTL), 'now' => $now]
        );
        $this->event($identityUuid, 'login_requested', 'Magic link requested', $ipHash);

        return ['sent' => true, 'identity_uuid' => $identityUuid, 'token' => $raw];
    }

    /**
     * Consume a magic link (one-shot) and mint a portal session. Returns the raw
     * session token for the caller to set as an httpOnly cookie.
     *
     * @return array{session_token:string,identity:array<string,mixed>}
     */
    public function consumeMagicLink(string $rawToken, string $ipHash = '', string $userAgent = ''): array
    {
        $link = $this->db()->one('SELECT * FROM portal_magic_links WHERE token_hash = :h', ['h' => $this->hash($rawToken)]);
        if ($link === null) {
            throw new PortalException(404, 'This sign-in link is invalid.');
        }
        if ($link['used_at'] !== null) {
            throw new PortalException(410, 'This sign-in link has already been used.');
        }
        if ($this->isPast((string) $link['expires_at'])) {
            throw new PortalException(410, 'This sign-in link has expired. Please request a new one.');
        }
        $identityUuid = (string) $link['identity_uuid'];
        $identity = $this->db()->one("SELECT * FROM portal_identities WHERE uuid = :u AND status = 'active'", ['u' => $identityUuid]);
        if ($identity === null) {
            throw new PortalException(403, 'This account is not active.');
        }
        $rawSession = bin2hex(random_bytes(32));
        $now = Clock::nowIso();

        return $this->db()->transaction(function () use ($link, $identityUuid, $rawSession, $ipHash, $userAgent, $now): array {
            $this->db()->run('UPDATE portal_magic_links SET used_at = :now WHERE uuid = :u', ['now' => $now, 'u' => (string) $link['uuid']]);
            $this->db()->run(
                'INSERT INTO portal_sessions (uuid, identity_uuid, token_hash, ip_hash, user_agent, expires_at, last_seen_at, created_at) VALUES (:uuid, :i, :h, :ip, :ua, :exp, :now, :now)',
                ['uuid' => Uuid::v4(), 'i' => $identityUuid, 'h' => $this->hash($rawSession), 'ip' => $ipHash, 'ua' => substr($userAgent, 0, 255), 'exp' => date('c', time() + self::SESSION_TTL), 'now' => $now]
            );
            $this->db()->run('UPDATE portal_identities SET last_login_at = :now, updated_at = :now WHERE uuid = :u', ['now' => $now, 'u' => $identityUuid]);
            $this->event($identityUuid, 'login', 'Signed in', $ipHash);

            return ['session_token' => $rawSession, 'identity' => $this->findIdentity($identityUuid) ?? []];
        });
    }

    /**
     * Resolve a session cookie token to its identity, sliding last-seen. Returns
     * null for any invalid/expired/revoked session (fail closed).
     *
     * @return array<string,mixed>|null
     */
    public function identityFromSession(string $rawToken): ?array
    {
        if (trim($rawToken) === '') {
            return null;
        }
        $session = $this->db()->one('SELECT * FROM portal_sessions WHERE token_hash = :h', ['h' => $this->hash($rawToken)]);
        if ($session === null || (int) $session['revoked'] === 1 || $this->isPast((string) $session['expires_at'])) {
            return null;
        }
        $identity = $this->db()->one("SELECT * FROM portal_identities WHERE uuid = :u AND status = 'active'", ['u' => (string) $session['identity_uuid']]);
        if ($identity === null) {
            return null;
        }
        $this->db()->run('UPDATE portal_sessions SET last_seen_at = :now WHERE uuid = :u', ['now' => Clock::nowIso(), 'u' => (string) $session['uuid']]);

        return $this->findIdentity((string) $session['identity_uuid']);
    }

    public function logout(string $rawToken): void
    {
        if (trim($rawToken) === '') {
            return;
        }
        $this->db()->run('UPDATE portal_sessions SET revoked = 1 WHERE token_hash = :h', ['h' => $this->hash($rawToken)]);
    }

    // ==================================================================
    // Access-scoped reads
    // ==================================================================

    public function canAccessProject(string $identityUuid, string $projectUuid): bool
    {
        return $this->db()->one('SELECT uuid FROM portal_access_grants WHERE identity_uuid = :i AND entity_type = \'project\' AND entity_uuid = :e AND revoked = 0', ['i' => $identityUuid, 'e' => $projectUuid]) !== null;
    }

    /**
     * The projects this identity may see — a safe, read-only projection. Server
     * enforced: only granted, non-archived projects are returned.
     *
     * @return list<array<string,mixed>>
     */
    public function accessibleProjects(string $identityUuid): array
    {
        $rows = $this->db()->all(
            "SELECT p.uuid, p.name, p.status, p.target_date
             FROM portal_access_grants g
             JOIN projects p ON p.uuid = g.entity_uuid
             WHERE g.identity_uuid = :i AND g.entity_type = 'project' AND g.revoked = 0 AND p.status <> 'archived'
             ORDER BY p.updated_at DESC",
            ['i' => $identityUuid]
        );
        // Progress is derived from real tasks, not stored — resolve it per row.
        foreach ($rows as &$row) {
            $full = $this->platform->projects()->find((string) $row['uuid']);
            $row['progress_percent'] = (int) ($full['progress_percent'] ?? 0);
        }
        unset($row);

        return $rows;
    }

    /**
     * A safe, read-only projection of ONE granted project for the client: the
     * overview, client-visible milestones, client-visible tasks (grouped by
     * milestone) and client-visible, non-archived files. Throws 403 if the
     * identity has no live grant for the project — access is server-enforced,
     * never inferred from the URL.
     *
     * @return array<string,mixed>
     */
    public function portalProject(string $identityUuid, string $projectUuid): array
    {
        if (!$this->canAccessProject($identityUuid, $projectUuid)) {
            throw new PortalException(403, 'You don’t have access to this project.');
        }
        $project = $this->platform->projects()->find($projectUuid);
        if ($project === null || (string) $project['status'] === 'archived') {
            throw new PortalException(404, 'Project not found.');
        }
        $milestones = $this->db()->all(
            "SELECT uuid, title, status, due_date FROM milestones WHERE project_uuid = :p AND client_visible = 1 AND status <> 'cancelled' ORDER BY sort_order ASC",
            ['p' => $projectUuid]
        );
        $tasks = $this->db()->all(
            "SELECT uuid, title, status, milestone_uuid FROM project_tasks WHERE project_uuid = :p AND client_visible = 1 AND status <> 'cancelled' ORDER BY sort_order ASC",
            ['p' => $projectUuid]
        );
        $files = array_values(array_filter(
            $this->platform->files()->list(['project_uuid' => $projectUuid]),
            static fn (array $f): bool => (int) ($f['client_visible'] ?? 0) === 1
        ));

        return [
            'overview' => [
                'uuid' => (string) $project['uuid'], 'name' => (string) $project['name'], 'status' => (string) $project['status'],
                'target_date' => (string) ($project['target_date'] ?? ''), 'progress_percent' => (int) ($project['progress_percent'] ?? 0),
            ],
            'milestones' => $milestones,
            'tasks' => $tasks,
            'files' => array_map(static fn (array $f): array => [
                'uuid' => (string) $f['uuid'], 'display_name' => (string) $f['display_name'], 'category' => (string) ($f['category'] ?? ''),
                'extension' => (string) ($f['extension'] ?? ''), 'byte_size' => (int) ($f['byte_size'] ?? 0), 'current_version' => (int) ($f['current_version'] ?? 1),
            ], $files),
            'feedback' => $this->feedbackForIdentity($identityUuid, $projectUuid),
            'has_approved' => $this->db()->one("SELECT uuid FROM portal_feedback WHERE project_uuid = :p AND identity_uuid = :i AND kind = 'approval' LIMIT 1", ['p' => $projectUuid, 'i' => $identityUuid]) !== null,
        ];
    }

    /**
     * Guard a client file download: the file must exist, be client-visible, and
     * belong to a project this identity currently has access to. Returns the file
     * uuid to stream, or throws.
     */
    public function assertFileDownloadable(string $identityUuid, string $fileUuid): void
    {
        $file = $this->db()->one('SELECT project_uuid, client_visible, archived FROM client_files WHERE uuid = :u', ['u' => $fileUuid]);
        if ($file === null || (int) ($file['client_visible'] ?? 0) !== 1 || (int) ($file['archived'] ?? 0) === 1) {
            throw new PortalException(404, 'File not found.');
        }
        $projectUuid = (string) ($file['project_uuid'] ?? '');
        if ($projectUuid === '' || !$this->canAccessProject($identityUuid, $projectUuid)) {
            throw new PortalException(403, 'You don’t have access to this file.');
        }
    }

    /**
     * The client's live design previews, surfaced inside the authenticated
     * portal. Scoped to the identity's own contact and to active previews only —
     * a safe projection with the canonical preview URL. Password-protected
     * previews still require their password on the preview origin itself; the
     * portal only advertises that one exists.
     *
     * @return list<array<string,mixed>>
     */
    public function accessiblePreviews(string $identityUuid): array
    {
        $identity = $this->findIdentity($identityUuid);
        $contact = (string) ($identity['contact_uuid'] ?? '');
        if ($contact === '') {
            return [];
        }
        $rows = $this->platform->previews()->all(['contact_uuid' => $contact, 'status' => 'active', 'include_archived' => false, 'limit' => 50]);
        $urls = $this->platform->previewUrls();

        return array_map(static function (array $p) use ($urls): array {
            $slug = (string) ($p['public_slug'] ?? '');

            return [
                'uuid' => (string) ($p['uuid'] ?? ''),
                'title' => trim((string) ($p['title_override'] ?? '')) ?: (trim((string) ($p['client_display_name'] ?? '')) ?: (trim((string) ($p['name'] ?? '')) ?: $slug)),
                'slug' => $slug,
                'url' => $slug !== '' ? $urls->url($slug) : '',
                'password_protected' => (string) ($p['visibility'] ?? '') === 'password',
                'updated_at' => (string) ($p['updated_at'] ?? ''),
            ];
        }, $rows);
    }

    // ==================================================================
    // Feedback & approvals (client voice → delivery)
    // ==================================================================

    /**
     * The client submits feedback or a formal sign-off on a granted project.
     * Access is enforced; the item is attributed, audited and mirrored onto the
     * contact's CRM timeline. Approvals carry evidence and are immutable.
     *
     * @param array<string,mixed> $data kind, body, approved_label, ip_hash, user_agent
     * @return array<string,mixed>
     */
    public function submitFeedback(string $identityUuid, string $projectUuid, array $data): array
    {
        if (!$this->canAccessProject($identityUuid, $projectUuid)) {
            throw new PortalException(403, 'You don’t have access to this project.');
        }
        $identity = $this->findIdentity($identityUuid);
        if ($identity === null) {
            throw new PortalException(404, 'Identity not found.');
        }
        $kind = in_array((string) ($data['kind'] ?? 'comment'), ['comment', 'approval'], true) ? (string) $data['kind'] : 'comment';
        $body = trim((string) ($data['body'] ?? ''));
        if ($kind === 'comment' && $body === '') {
            throw new PortalException(422, 'Enter your feedback.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $name = trim((string) ($identity['display_name'] ?? '')) ?: (string) ($identity['email'] ?? 'Client');
        $this->db()->run(
            'INSERT INTO portal_feedback (uuid, project_uuid, identity_uuid, kind, body, author_name, status, approved_label, ip_hash, user_agent, created_at)
             VALUES (:uuid, :p, :i, :kind, :body, :name, \'open\', :label, :ip, :ua, :now)',
            [
                'uuid' => $uuid, 'p' => $projectUuid, 'i' => $identityUuid, 'kind' => $kind, 'body' => $body, 'name' => $name,
                'label' => (string) ($data['approved_label'] ?? ($kind === 'approval' ? 'Project sign-off' : '')),
                'ip' => (string) ($data['ip_hash'] ?? ''), 'ua' => substr((string) ($data['user_agent'] ?? ''), 0, 255), 'now' => $now,
            ]
        );
        $this->event($identityUuid, 'feedback_' . $kind, $projectUuid, (string) ($data['ip_hash'] ?? ''));

        $summary = $kind === 'approval' ? 'Client signed off the project' : 'Client left feedback';
        $contact = (string) ($identity['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, 'portal.feedback_' . $kind, $summary, 'client', null, ['project' => $projectUuid, 'feedback' => $uuid]);
        }
        $this->platform->projects()->logEvent($projectUuid, 'portal_feedback', $summary . ($body !== '' ? ': ' . mb_substr($body, 0, 140) : ''), 'portal:' . $identityUuid);
        $this->platform->audit()->event('portal.feedback_' . $kind, 'project', $projectUuid, 'portal:' . $identityUuid, ['feedback' => $uuid]);

        return $this->db()->one('SELECT * FROM portal_feedback WHERE uuid = :u', ['u' => $uuid]) ?? [];
    }

    /**
     * A client's own feedback thread for a project (their submissions only).
     *
     * @return list<array<string,mixed>>
     */
    public function feedbackForIdentity(string $identityUuid, string $projectUuid): array
    {
        return $this->db()->all(
            'SELECT uuid, kind, body, status, approved_label, created_at FROM portal_feedback WHERE project_uuid = :p AND identity_uuid = :i ORDER BY created_at DESC LIMIT 100',
            ['p' => $projectUuid, 'i' => $identityUuid]
        );
    }

    /**
     * All portal feedback on a project (staff view).
     *
     * @return list<array<string,mixed>>
     */
    public function feedbackForProject(string $projectUuid): array
    {
        return $this->db()->all(
            'SELECT * FROM portal_feedback WHERE project_uuid = :p ORDER BY created_at DESC LIMIT 200',
            ['p' => $projectUuid]
        );
    }

    /**
     * Staff move a feedback item's status (open → acknowledged → resolved).
     *
     * @return array<string,mixed>
     */
    public function setFeedbackStatus(string $feedbackUuid, string $status, string $actor): array
    {
        if (!in_array($status, ['open', 'acknowledged', 'resolved'], true)) {
            throw new PortalException(422, 'Unknown status.');
        }
        $fb = $this->db()->one('SELECT project_uuid FROM portal_feedback WHERE uuid = :u', ['u' => $feedbackUuid]);
        if ($fb === null) {
            throw new PortalException(404, 'Feedback not found.');
        }
        $this->db()->run('UPDATE portal_feedback SET status = :s, handled_by = :actor, handled_at = :now WHERE uuid = :u', ['s' => $status, 'actor' => $actor, 'now' => Clock::nowIso(), 'u' => $feedbackUuid]);

        return $this->db()->one('SELECT * FROM portal_feedback WHERE uuid = :u', ['u' => $feedbackUuid]) ?? [];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private function isPast(string $iso): bool
    {
        $ts = strtotime($iso);

        return $ts !== false && $ts < time();
    }

    private function event(string $identityUuid, string $type, string $detail, string $ipHash): void
    {
        $this->db()->run('INSERT INTO portal_events (uuid, identity_uuid, type, detail, ip_hash, created_at) VALUES (:uuid, :i, :type, :detail, :ip, :now)', ['uuid' => Uuid::v4(), 'i' => $identityUuid, 'type' => $type, 'detail' => $detail, 'ip' => $ipHash, 'now' => Clock::nowIso()]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
