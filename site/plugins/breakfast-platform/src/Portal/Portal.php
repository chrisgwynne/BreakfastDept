<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portal;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\FileStore;
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
 *
 * Portal state lives in flat files: identities (with embedded grants + events),
 * magic links, sessions, feedback and messages are each a JSON collection. The
 * delivery joins it renders (milestones, tasks, client files) come from their
 * owning services.
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

    private function store(): FileStore
    {
        return $this->platform->fileStore();
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
        if ($this->identityUuidForEmail($email) !== null) {
            throw new PortalException(409, 'A portal identity already exists for this email.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->store()->put('portal_identities', [
            'uuid'          => $uuid,
            'email'         => (string) ($data['email'] ?? $email),
            'email_key'     => $email,
            'display_name'  => (string) ($data['display_name'] ?? ''),
            'contact_uuid'  => $this->nullable($data['contact_uuid'] ?? null),
            'company_uuid'  => $this->nullable($data['company_uuid'] ?? null),
            'status'        => 'active',
            'last_login_at' => null,
            'revision'      => 0,
            'grants'        => [],
            'events'        => [],
            'created_by'    => $actor,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $this->event($uuid, 'identity_created', 'Portal identity created', '');

        return $this->findIdentity($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function findIdentity(string $uuid): ?array
    {
        $id = $this->store()->find('portal_identities', $uuid);
        if ($id === null) {
            return null;
        }
        unset($id['email_key']);
        $id['grants'] = array_values(array_map(
            static fn (array $g): array => ['entity_type' => $g['entity_type'] ?? '', 'entity_uuid' => $g['entity_uuid'] ?? '', 'role' => $g['role'] ?? ''],
            array_filter(is_array($id['grants'] ?? null) ? $id['grants'] : [], static fn (array $g): bool => (int) ($g['revoked'] ?? 0) === 0)
        ));

        return $id;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listIdentities(array $filters = []): array
    {
        $rows = $this->store()->all('portal_identities');
        if (!empty($filters['contact_uuid'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['contact_uuid'] ?? '') === (string) $filters['contact_uuid']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice(array_map(static fn (array $r): array => [
            'uuid' => $r['uuid'] ?? '', 'email' => $r['email'] ?? '', 'display_name' => $r['display_name'] ?? '',
            'contact_uuid' => $r['contact_uuid'] ?? null, 'company_uuid' => $r['company_uuid'] ?? null,
            'status' => $r['status'] ?? '', 'last_login_at' => $r['last_login_at'] ?? null, 'created_at' => $r['created_at'] ?? '',
        ], $rows), 0, 200);
    }

    /** @return array<string,mixed> */
    public function grantAccess(string $identityUuid, string $entityType, string $entityUuid, string $role, string $actor): array
    {
        if ($this->store()->find('portal_identities', $identityUuid) === null) {
            throw new PortalException(404, 'Portal identity not found.');
        }
        if (!in_array($role, ['viewer', 'approver'], true)) {
            throw new PortalException(422, 'Unknown access role.');
        }
        $now = Clock::nowIso();
        // Upsert: re-granting reactivates a revoked grant and updates the role.
        $this->store()->update('portal_identities', $identityUuid, static function (array $row) use ($entityType, $entityUuid, $role, $actor, $now): array {
            $grants = is_array($row['grants'] ?? null) ? $row['grants'] : [];
            $found = false;
            foreach ($grants as $i => $g) {
                if ((string) ($g['entity_type'] ?? '') === $entityType && (string) ($g['entity_uuid'] ?? '') === $entityUuid) {
                    $grants[$i]['role']    = $role;
                    $grants[$i]['revoked'] = 0;
                    $found = true;
                }
            }
            if (!$found) {
                $grants[] = ['uuid' => Uuid::v4(), 'entity_type' => $entityType, 'entity_uuid' => $entityUuid, 'role' => $role, 'revoked' => 0, 'created_by' => $actor, 'created_at' => $now];
            }
            $row['grants'] = $grants;

            return $row;
        });
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
        if (!$this->platform->projects()->exists($projectUuid)) {
            throw new PortalException(404, 'Project not found.');
        }
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalException(422, 'A valid email is required.');
        }
        $existing = $this->identityUuidForEmail($email);
        $identityUuid = $existing ?? (string) $this->createIdentity($data, $actor)['uuid'];
        $this->grantAccess($identityUuid, 'project', $projectUuid, (string) ($data['role'] ?? 'viewer'), $actor);
        $link = $this->requestLogin($email);
        $url = ($link['sent'] && $link['token'] !== null) ? rtrim($siteUrl, '/') . '/portal/verify/' . $link['token'] : '';

        return ['identity' => $this->findIdentity($identityUuid) ?? [], 'url' => $url];
    }

    public function revokeAccess(string $identityUuid, string $entityType, string $entityUuid, string $actor): void
    {
        $this->store()->update('portal_identities', $identityUuid, static function (array $row) use ($entityType, $entityUuid): array {
            $grants = is_array($row['grants'] ?? null) ? $row['grants'] : [];
            foreach ($grants as $i => $g) {
                if ((string) ($g['entity_type'] ?? '') === $entityType && (string) ($g['entity_uuid'] ?? '') === $entityUuid) {
                    $grants[$i]['revoked'] = 1;
                }
            }
            $row['grants'] = $grants;

            return $row;
        });
        $this->event($identityUuid, 'access_revoked', $entityType . ':' . $entityUuid, '');
    }

    /** @return array<string,mixed> */
    public function setStatus(string $identityUuid, string $status, string $actor): array
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new PortalException(422, 'Unknown status.');
        }
        $this->store()->update('portal_identities', $identityUuid, static function (array $row) use ($status): array {
            $row['status']     = $status;
            $row['updated_at'] = Clock::nowIso();
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;

            return $row;
        });
        if ($status === 'suspended') {
            // Suspending kills every live session immediately.
            foreach ($this->store()->all('portal_sessions') as $s) {
                if ((string) ($s['identity_uuid'] ?? '') === $identityUuid && (int) ($s['revoked'] ?? 0) === 0) {
                    $this->store()->update('portal_sessions', (string) $s['uuid'], static function (array $r): array {
                        $r['revoked'] = 1;

                        return $r;
                    });
                }
            }
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
        $identityUuid = $key === '' ? null : $this->identityUuidForEmail($key);
        $identity = $identityUuid !== null ? $this->store()->find('portal_identities', $identityUuid) : null;
        if ($identity === null || (string) $identity['status'] !== 'active') {
            return ['sent' => false, 'identity_uuid' => null, 'token' => null];
        }
        $identityUuid = (string) $identity['uuid'];
        $raw = bin2hex(random_bytes(24));
        $now = Clock::nowIso();
        $this->store()->put('portal_magic_links', [
            'uuid'          => Uuid::v4(),
            'identity_uuid' => $identityUuid,
            'token_hash'    => $this->hash($raw),
            'email'         => $key,
            'ip_hash'       => $ipHash,
            'expires_at'    => date('c', time() + self::MAGIC_LINK_TTL),
            'used_at'       => null,
            'created_at'    => $now,
        ]);
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
        $link = $this->findByTokenHash('portal_magic_links', $this->hash($rawToken));
        if ($link === null) {
            throw new PortalException(404, 'This sign-in link is invalid.');
        }
        if (($link['used_at'] ?? null) !== null) {
            throw new PortalException(410, 'This sign-in link has already been used.');
        }
        if ($this->isPast((string) $link['expires_at'])) {
            throw new PortalException(410, 'This sign-in link has expired. Please request a new one.');
        }
        $identityUuid = (string) $link['identity_uuid'];
        $identity = $this->store()->find('portal_identities', $identityUuid);
        if ($identity === null || (string) $identity['status'] !== 'active') {
            throw new PortalException(403, 'This account is not active.');
        }
        $rawSession = bin2hex(random_bytes(32));
        $now = Clock::nowIso();

        $this->store()->update('portal_magic_links', (string) $link['uuid'], static function (array $r) use ($now): array {
            $r['used_at'] = $now;

            return $r;
        });
        $this->store()->put('portal_sessions', [
            'uuid'          => Uuid::v4(),
            'identity_uuid' => $identityUuid,
            'token_hash'    => $this->hash($rawSession),
            'ip_hash'       => $ipHash,
            'user_agent'    => substr($userAgent, 0, 255),
            'expires_at'    => date('c', time() + self::SESSION_TTL),
            'last_seen_at'  => $now,
            'revoked'       => 0,
            'created_at'    => $now,
        ]);
        $this->store()->update('portal_identities', $identityUuid, static function (array $r) use ($now): array {
            $r['last_login_at'] = $now;
            $r['updated_at']    = $now;

            return $r;
        });
        $this->event($identityUuid, 'login', 'Signed in', $ipHash);

        return ['session_token' => $rawSession, 'identity' => $this->findIdentity($identityUuid) ?? []];
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
        $session = $this->findByTokenHash('portal_sessions', $this->hash($rawToken));
        if ($session === null || (int) ($session['revoked'] ?? 0) === 1 || $this->isPast((string) $session['expires_at'])) {
            return null;
        }
        $identity = $this->store()->find('portal_identities', (string) $session['identity_uuid']);
        if ($identity === null || (string) $identity['status'] !== 'active') {
            return null;
        }
        $this->store()->update('portal_sessions', (string) $session['uuid'], static function (array $r): array {
            $r['last_seen_at'] = Clock::nowIso();

            return $r;
        });

        return $this->findIdentity((string) $session['identity_uuid']);
    }

    public function logout(string $rawToken): void
    {
        if (trim($rawToken) === '') {
            return;
        }
        $session = $this->findByTokenHash('portal_sessions', $this->hash($rawToken));
        if ($session !== null) {
            $this->store()->update('portal_sessions', (string) $session['uuid'], static function (array $r): array {
                $r['revoked'] = 1;

                return $r;
            });
        }
    }

    // ==================================================================
    // Access-scoped reads
    // ==================================================================

    public function canAccessProject(string $identityUuid, string $projectUuid): bool
    {
        $identity = $this->store()->find('portal_identities', $identityUuid);
        if ($identity === null) {
            return false;
        }
        foreach (is_array($identity['grants'] ?? null) ? $identity['grants'] : [] as $g) {
            if ((string) ($g['entity_type'] ?? '') === 'project' && (string) ($g['entity_uuid'] ?? '') === $projectUuid && (int) ($g['revoked'] ?? 0) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The projects this identity may see — a safe, read-only projection. Server
     * enforced: only granted, non-archived projects are returned.
     *
     * @return list<array<string,mixed>>
     */
    public function accessibleProjects(string $identityUuid): array
    {
        $identity = $this->store()->find('portal_identities', $identityUuid);
        if ($identity === null) {
            return [];
        }
        $rows = [];
        foreach (is_array($identity['grants'] ?? null) ? $identity['grants'] : [] as $grant) {
            if ((string) ($grant['entity_type'] ?? '') !== 'project' || (int) ($grant['revoked'] ?? 0) !== 0) {
                continue;
            }
            $full = $this->platform->projects()->find((string) ($grant['entity_uuid'] ?? ''));
            if ($full === null || (string) ($full['status'] ?? '') === 'archived') {
                continue;
            }
            $rows[] = [
                'uuid'             => $full['uuid'] ?? '',
                'name'             => $full['name'] ?? '',
                'status'           => $full['status'] ?? '',
                'target_date'      => $full['target_date'] ?? null,
                'progress_percent' => (int) ($full['progress_percent'] ?? 0),
                'updated_at'       => $full['updated_at'] ?? '',
            ];
        }
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

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
        // Milestones + delivery tasks are still database-backed.
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
            'has_approved' => $this->hasApproval($projectUuid, $identityUuid),
            'messages' => $this->messageThread($projectUuid, $identityUuid, 'client'),
        ];
    }

    /**
     * Guard a client file download: the file must exist, be client-visible, and
     * belong to a project this identity currently has access to. Returns the file
     * uuid to stream, or throws.
     */
    public function assertFileDownloadable(string $identityUuid, string $fileUuid): void
    {
        $file = $this->store()->find('client_files', $fileUuid);
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

    /**
     * The client's own commercial documents — proposals, contracts and invoices
     * — surfaced in the portal for self-service viewing. Scoped to the identity's
     * contact and to statuses the client has legitimately been sent, each linking
     * to its existing tokened hosted page. Drafts and internal states never
     * appear. Money is returned in pence.
     *
     * @return array{proposals:list<array<string,mixed>>,contracts:list<array<string,mixed>>,invoices:list<array<string,mixed>>}
     */
    public function accessibleDocuments(string $identityUuid): array
    {
        $identity = $this->findIdentity($identityUuid);
        $contact = (string) ($identity['contact_uuid'] ?? '');
        if ($contact === '') {
            return ['proposals' => [], 'contracts' => [], 'invoices' => []];
        }
        $proposalStatuses = ['sent', 'viewed', 'accepted', 'declined'];
        $proposals = array_values(array_filter($this->platform->fileStore()->all('proposals'), static fn (array $r): bool => (string) ($r['contact_uuid'] ?? '') === $contact
            && (string) ($r['public_token'] ?? '') !== ''
            && in_array((string) ($r['status'] ?? ''), $proposalStatuses, true)));
        usort($proposals, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $proposals = array_slice(array_map(static fn (array $r): array => [
            'number'       => $r['number'] ?? '',
            'title'        => $r['title'] ?? '',
            'status'       => $r['status'] ?? '',
            'total'        => (int) ($r['total'] ?? 0),
            'public_token' => $r['public_token'] ?? '',
        ], $proposals), 0, 50);
        $contractStatuses = ['sent', 'viewed', 'signed', 'completed', 'declined'];
        $contracts = array_values(array_filter($this->platform->fileStore()->all('contracts'), static fn (array $r): bool => (string) ($r['contact_uuid'] ?? '') === $contact
            && (string) ($r['public_token'] ?? '') !== ''
            && in_array((string) ($r['status'] ?? ''), $contractStatuses, true)));
        usort($contracts, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $contracts = array_slice(array_map(static fn (array $r): array => [
            'number'         => $r['number'] ?? '',
            'title'          => $r['title'] ?? '',
            'status'         => $r['status'] ?? '',
            'contract_value' => (int) ($r['contract_value'] ?? 0),
            'public_token'   => $r['public_token'] ?? '',
        ], $contracts), 0, 50);
        $invoiceStatuses = ['issued', 'part_paid', 'paid', 'overdue', 'void'];
        $invoices = array_values(array_filter($this->platform->fileStore()->all('invoices'), static fn (array $r): bool => (string) ($r['contact_uuid'] ?? '') === $contact
            && (string) ($r['public_token'] ?? '') !== ''
            && in_array((string) ($r['status'] ?? ''), $invoiceStatuses, true)));
        usort($invoices, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $invoices = array_slice(array_map(static fn (array $r): array => [
            'number'       => $r['number'] ?? '',
            'status'       => $r['status'] ?? '',
            'total'        => (int) ($r['total'] ?? 0),
            'public_token' => $r['public_token'] ?? '',
        ], $invoices), 0, 50);
        $base = rtrim((string) kirby()->site()->url(), '/');

        return [
            'proposals' => array_map(static fn (array $p): array => [
                'title' => (string) ($p['title'] ?? '') ?: (string) ($p['number'] ?? 'Proposal'), 'number' => (string) ($p['number'] ?? ''),
                'status' => (string) ($p['status'] ?? ''), 'total' => (int) ($p['total'] ?? 0), 'url' => $base . '/proposal/' . (string) $p['public_token'],
            ], $proposals),
            'contracts' => array_map(static fn (array $c): array => [
                'title' => (string) ($c['title'] ?? '') ?: (string) ($c['number'] ?? 'Contract'), 'number' => (string) ($c['number'] ?? ''),
                'status' => (string) ($c['status'] ?? ''), 'total' => (int) ($c['contract_value'] ?? 0), 'url' => $base . '/contract/' . (string) $c['public_token'],
            ], $contracts),
            'invoices' => array_map(static fn (array $i): array => [
                'number' => (string) ($i['number'] ?? 'Invoice'), 'status' => (string) ($i['status'] ?? ''),
                'total' => (int) ($i['total'] ?? 0), 'url' => $base . '/invoice/' . (string) $i['public_token'],
            ], $invoices),
        ];
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
        $this->store()->put('portal_feedback', [
            'uuid'           => $uuid,
            'project_uuid'   => $projectUuid,
            'identity_uuid'  => $identityUuid,
            'kind'           => $kind,
            'body'           => $body,
            'author_name'    => $name,
            'status'         => 'open',
            'approved_label' => (string) ($data['approved_label'] ?? ($kind === 'approval' ? 'Project sign-off' : '')),
            'ip_hash'        => (string) ($data['ip_hash'] ?? ''),
            'user_agent'     => substr((string) ($data['user_agent'] ?? ''), 0, 255),
            'handled_by'     => null,
            'handled_at'     => null,
            'created_at'     => $now,
        ]);
        $this->event($identityUuid, 'feedback_' . $kind, $projectUuid, (string) ($data['ip_hash'] ?? ''));

        $summary = $kind === 'approval' ? 'Client signed off the project' : 'Client left feedback';
        $contact = (string) ($identity['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, 'portal.feedback_' . $kind, $summary, 'client', null, ['project' => $projectUuid, 'feedback' => $uuid]);
        }
        $this->platform->projects()->logEvent($projectUuid, 'portal_feedback', $summary . ($body !== '' ? ': ' . mb_substr($body, 0, 140) : ''), 'portal:' . $identityUuid);
        $this->platform->audit()->event('portal.feedback_' . $kind, 'project', $projectUuid, 'portal:' . $identityUuid, ['feedback' => $uuid]);

        return $this->store()->find('portal_feedback', $uuid) ?? [];
    }

    /**
     * A client's own feedback thread for a project (their submissions only).
     *
     * @return list<array<string,mixed>>
     */
    public function feedbackForIdentity(string $identityUuid, string $projectUuid): array
    {
        $rows = array_values(array_filter($this->store()->all('portal_feedback'), static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === $projectUuid && (string) ($r['identity_uuid'] ?? '') === $identityUuid));
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice(array_map(static fn (array $r): array => [
            'uuid' => $r['uuid'] ?? '', 'kind' => $r['kind'] ?? '', 'body' => $r['body'] ?? '', 'status' => $r['status'] ?? '',
            'approved_label' => $r['approved_label'] ?? '', 'created_at' => $r['created_at'] ?? '',
        ], $rows), 0, 100);
    }

    /**
     * All portal feedback on a project (staff view).
     *
     * @return list<array<string,mixed>>
     */
    public function feedbackForProject(string $projectUuid): array
    {
        $rows = array_values(array_filter($this->store()->all('portal_feedback'), static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === $projectUuid));
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, 200);
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
        if ($this->store()->find('portal_feedback', $feedbackUuid) === null) {
            throw new PortalException(404, 'Feedback not found.');
        }
        $updated = $this->store()->update('portal_feedback', $feedbackUuid, static function (array $row) use ($status, $actor): array {
            $row['status']     = $status;
            $row['handled_by'] = $actor;
            $row['handled_at'] = Clock::nowIso();

            return $row;
        });

        return $updated ?? [];
    }

    private function hasApproval(string $projectUuid, string $identityUuid): bool
    {
        foreach ($this->store()->all('portal_feedback') as $r) {
            if ((string) ($r['project_uuid'] ?? '') === $projectUuid && (string) ($r['identity_uuid'] ?? '') === $identityUuid && (string) ($r['kind'] ?? '') === 'approval') {
                return true;
            }
        }

        return false;
    }

    // ==================================================================
    // Messaging (two-way, per project + identity)
    // ==================================================================

    /**
     * The client posts a message to staff on a granted project. Access-checked,
     * attributed, mirrored onto the contact's CRM timeline; marked unread for
     * staff. Returns the stored message.
     *
     * @return array<string,mixed>
     */
    public function postClientMessage(string $identityUuid, string $projectUuid, string $body): array
    {
        if (!$this->canAccessProject($identityUuid, $projectUuid)) {
            throw new PortalException(403, 'You don’t have access to this project.');
        }
        $body = trim($body);
        if ($body === '') {
            throw new PortalException(422, 'Enter a message.');
        }
        $identity = $this->findIdentity($identityUuid);
        $name = trim((string) ($identity['display_name'] ?? '')) ?: (string) ($identity['email'] ?? 'Client');
        $uuid = $this->insertMessage($projectUuid, $identityUuid, 'client', $name, $body, readByClient: 1, readByStaff: 0);
        $this->event($identityUuid, 'message_sent', $projectUuid, '');
        $contact = (string) ($identity['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, 'portal.message', 'Client sent a message', 'client', null, ['project' => $projectUuid, 'message' => $uuid]);
        }
        $this->platform->projects()->logEvent($projectUuid, 'portal_message', 'Client message: ' . mb_substr($body, 0, 140), 'portal:' . $identityUuid);

        return $this->store()->find('portal_messages', $uuid) ?? [];
    }

    /**
     * Staff reply to a client on a project thread. Marked unread for the client.
     *
     * @return array<string,mixed>
     */
    public function postStaffMessage(string $projectUuid, string $identityUuid, string $body, string $actor): array
    {
        if ($this->store()->find('portal_identities', $identityUuid) === null) {
            throw new PortalException(404, 'Portal identity not found.');
        }
        $body = trim($body);
        if ($body === '') {
            throw new PortalException(422, 'Enter a message.');
        }
        $uuid = $this->insertMessage($projectUuid, $identityUuid, 'staff', $actor, $body, readByClient: 0, readByStaff: 1);
        $this->platform->projects()->logEvent($projectUuid, 'portal_message', 'Reply to client', $actor);

        return $this->store()->find('portal_messages', $uuid) ?? [];
    }

    /**
     * The thread for a (project, identity), oldest first. `viewer` decides which
     * side's unread flags are cleared as a side effect of reading.
     *
     * @return list<array<string,mixed>>
     */
    public function messageThread(string $projectUuid, string $identityUuid, string $viewer = ''): array
    {
        $rows = array_values(array_filter($this->store()->all('portal_messages'), static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === $projectUuid && (string) ($r['identity_uuid'] ?? '') === $identityUuid));
        if ($viewer === 'client' || $viewer === 'staff') {
            $flag = $viewer === 'client' ? 'read_by_client' : 'read_by_staff';
            $otherSide = $viewer === 'client' ? 'staff' : 'client';
            foreach ($rows as $m) {
                if ((string) ($m['sender'] ?? '') === $otherSide && (int) ($m[$flag] ?? 0) === 0) {
                    $this->store()->update('portal_messages', (string) $m['uuid'], static function (array $r) use ($flag): array {
                        $r[$flag] = 1;

                        return $r;
                    });
                }
            }
        }
        usort($rows, static fn ($a, $b) => [(string) ($a['created_at'] ?? ''), (int) ($a['seq'] ?? 0)] <=> [(string) ($b['created_at'] ?? ''), (int) ($b['seq'] ?? 0)]);

        return array_slice(array_map(static fn (array $r): array => [
            'uuid' => $r['uuid'] ?? '', 'sender' => $r['sender'] ?? '', 'author_name' => $r['author_name'] ?? '', 'body' => $r['body'] ?? '', 'created_at' => $r['created_at'] ?? '',
        ], $rows), 0, 500);
    }

    /** Count of messages from the client that staff have not yet read, for a project. */
    public function unreadForStaff(string $projectUuid): int
    {
        return count(array_filter($this->store()->all('portal_messages'), static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === $projectUuid && (string) ($r['sender'] ?? '') === 'client' && (int) ($r['read_by_staff'] ?? 0) === 0));
    }

    private function insertMessage(string $projectUuid, string $identityUuid, string $sender, string $name, string $body, int $readByClient, int $readByStaff): string
    {
        $uuid = Uuid::v4();
        // Second-resolution timestamps tie for messages posted in the same second;
        // a monotonic sequence guarantees a stable oldest-first thread order.
        $seq = $this->store()->bump('portal_message_seq', 'n');
        $this->store()->put('portal_messages', [
            'uuid'           => $uuid,
            'project_uuid'   => $projectUuid,
            'identity_uuid'  => $identityUuid,
            'sender'         => $sender,
            'author_name'    => $name,
            'body'           => $body,
            'read_by_client' => $readByClient,
            'read_by_staff'  => $readByStaff,
            'seq'            => $seq,
            'created_at'     => Clock::nowIso(),
        ]);

        return $uuid;
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function identityUuidForEmail(string $emailKey): ?string
    {
        foreach ($this->store()->all('portal_identities') as $i) {
            if ((string) ($i['email_key'] ?? '') === $emailKey) {
                return (string) $i['uuid'];
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByTokenHash(string $collection, string $hash): ?array
    {
        foreach ($this->store()->all($collection) as $row) {
            if ((string) ($row['token_hash'] ?? '') === $hash) {
                return $row;
            }
        }

        return null;
    }

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
        $this->store()->update('portal_identities', $identityUuid, static function (array $row) use ($type, $detail, $ipHash): array {
            $row['events'][] = ['uuid' => Uuid::v4(), 'type' => $type, 'detail' => $detail, 'ip_hash' => $ipHash, 'created_at' => Clock::nowIso()];

            return $row;
        });
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
