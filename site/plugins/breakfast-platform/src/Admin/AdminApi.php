<?php

declare(strict_types=1);

namespace Breakfast\Platform\Admin;

use Breakfast\Platform\Security\PanelGate;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;

/**
 * JSON API for the standalone Breakfast Admin application (/api/breakfast-admin/v1).
 *
 * This is deliberately separate from Kirby's Panel API. Authentication is the
 * Kirby session (established by our own /session login endpoint); every request
 * is checked server-side, mutations require a valid CSRF token, and permissions
 * are enforced here via PanelGate — never by the client. Responses are
 * { "data": ... } on success and { "error"|"message", "code"?, "fields"? } on
 * failure, with safe messages only.
 */
final class AdminApi
{
    public function __construct(
        private readonly App $kirby,
        private readonly Platform $platform,
    ) {
    }

    public function handle(string $path): Response
    {
        $method = strtoupper($this->kirby->request()->method());
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));

        try {
            $result = $this->route($method, $segments);
            if ($result instanceof Response) {
                return $result;
            }

            return $this->json(['data' => $result]);
        } catch (ApiException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => $e->errorCode, 'fields' => $e->fields], $e->status);
        } catch (Throwable) {
            return $this->json(['error' => 'Something went wrong.'], 500);
        }
    }

    /**
     * @param list<string> $seg
     * @return mixed
     */
    private function route(string $method, array $seg)
    {
        $head = $seg[0] ?? '';

        // ---- Session (auth) — the only unauthenticated area ----
        if ($head === 'session') {
            return $this->session($method, $seg);
        }

        // Everything else requires an authenticated user.
        $user = $this->kirby->user();
        if ($user === null) {
            throw new ApiException(401, 'Not signed in.', 'unauthenticated');
        }
        if (!PanelGate::canAccess($user)) {
            throw new ApiException(403, 'You don’t have access to the admin.', 'forbidden');
        }
        if ($method !== 'GET') {
            $this->requireCsrf();
        }

        return match ($head) {
            'dashboard'     => $this->dashboard(),
            'enquiries'     => $this->enquiries($method, $seg),
            'contacts'      => $this->contacts($method, $seg),
            'companies'     => $this->companies($method, $seg),
            'opportunities' => $this->opportunities($method, $seg),
            'tasks'         => $this->tasks($method, $seg),
            'activities'    => $this->activities(),
            'previews'      => $this->previews($seg),
            'email'         => $this->email($user),
            'website'       => $this->website(),
            'reports'       => $this->reports(),
            'operations'    => $this->operations($user),
            default         => throw new ApiException(404, 'Unknown endpoint.', 'not_found'),
        };
    }

    // ==================================================================
    // Session / auth
    // ==================================================================

    /**
     * @param list<string> $seg
     * @return mixed
     */
    private function session(string $method, array $seg)
    {
        // GET /session/csrf — seed a CSRF token before login.
        if (($seg[1] ?? '') === 'csrf') {
            return ['csrf' => csrf()];
        }

        if ($method === 'GET') {
            $user = $this->kirby->user();
            if ($user === null || !PanelGate::canAccess($user)) {
                throw new ApiException(401, 'Not signed in.', 'unauthenticated');
            }

            return $this->sessionPayload($user);
        }

        if ($method === 'POST') {
            $body = $this->body();
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');
            $long = (bool) ($body['remember'] ?? false);
            if ($email === '' || $password === '') {
                throw new ApiException(422, 'Enter your email and password.', 'invalid');
            }
            try {
                $user = $this->kirby->auth()->login($email, $password, $long);
            } catch (Throwable) {
                // Never disclose whether the account exists or which check failed.
                throw new ApiException(401, 'Those details don’t match.', 'invalid_credentials');
            }
            if (!PanelGate::canAccess($user)) {
                $this->kirby->auth()->logout();
                throw new ApiException(403, 'This account can’t access the admin.', 'forbidden');
            }

            return $this->sessionPayload($user);
        }

        // PATCH /session — update the signed-in user's own display name (the
        // friendly name shown in the greeting). Auth + CSRF enforced here because
        // the session branch runs before route()'s shared gate.
        if ($method === 'PATCH') {
            $user = $this->kirby->user();
            if ($user === null || !PanelGate::canAccess($user)) {
                throw new ApiException(401, 'Not signed in.', 'unauthenticated');
            }
            $this->requireCsrf();

            $name = trim((string) ($this->body()['name'] ?? ''));
            if (mb_strlen($name) > 80) {
                $name = mb_substr($name, 0, 80);
            }
            $email = (string) $user->email();
            // Elevate only to write the account file, then build the payload from
            // the updated user. The session cookie still identifies the real user.
            $this->kirby->impersonate('kirby');
            $updated = $this->kirby->user($email)?->changeName($name);
            if ($updated === null) {
                throw new ApiException(500, 'Could not update your name.', 'update_failed');
            }

            return $this->sessionPayload($updated);
        }

        if ($method === 'DELETE') {
            $this->kirby->auth()->logout();

            return ['ok' => true];
        }

        throw new ApiException(405, 'Method not allowed.', 'method');
    }

    /** @return array<string,mixed> */
    private function sessionPayload(\Kirby\Cms\User $user): array
    {
        $name = trim((string) $user->name()->or($user->email())->value());
        $role = $user->role()->name();
        $isAdmin = $user->isAdmin();

        $permissions = [];
        if ($isAdmin) {
            $permissions[] = 'admin';
        }
        if (PanelGate::canManage($user)) {
            $permissions[] = 'crm.manage';
        }
        if (PanelGate::canExport($user)) {
            $permissions[] = 'crm.export';
        }
        if (PanelGate::canSendEmail($user)) {
            $permissions[] = 'email.send';
        }

        return [
            'user' => [
                'id'          => $user->id(),
                'email'       => (string) $user->email(),
                'name'        => $name !== '' ? $name : (string) $user->email(),
                'role'        => $role,
                'initials'    => $this->initials($name !== '' ? $name : (string) $user->email()),
                'permissions' => $permissions,
            ],
            'csrf' => csrf(),
        ];
    }

    // ==================================================================
    // Dashboard
    // ==================================================================

    /** @return array<string,mixed> */
    private function dashboard(): array
    {
        $d = new DashboardData($this->platform);
        $crm = $this->platform->crm();
        $metrics = $d->metrics();
        $health = $d->systemHealth();

        $tasks = $d->tasks(50);
        $overdue = is_array($tasks['overdue'] ?? null) ? count($tasks['overdue']) : 0;

        $pipeline = [];
        $byStage = $this->platform->opportunities()->pipelineByStage();
        foreach ($crm->stages() as $stage) {
            if ($stage === 'won' || $stage === 'lost') {
                continue; // pipelineByStage() only tracks open stages
            }
            $row = $byStage[$stage] ?? [];
            $pipeline[] = [
                'key'   => $stage,
                'label' => $this->stageLabel($stage),
                'count' => (int) ($row['count'] ?? 0),
                'value' => (int) ($row['value'] ?? 0),
            ];
        }

        $preview = $d->previewsSummary();
        $recent = [];
        foreach (array_slice($this->platform->activities()->recent(8), 0, 8) as $a) {
            $recent[] = [
                'id'    => (string) ($a['uuid'] ?? ($a['id'] ?? '')),
                'type'  => (string) ($a['type'] ?? 'event'),
                'title' => (string) ($a['summary'] ?? $a['type'] ?? 'Activity'),
                'meta'  => (string) ($a['entity_type'] ?? ''),
                'at'    => $this->ago((string) ($a['created_at'] ?? '')),
            ];
        }

        return [
            'greeting' => $this->greeting(),
            'date'     => date('l, j F'),
            'attention' => [
                'new_enquiries'          => (int) ($metrics['new_leads'] ?? 0),
                'overdue_tasks'          => $overdue,
                'failed_emails'          => (int) ($health['failed_jobs'] ?? 0),
                'previews_awaiting'      => (int) ($preview['draft'] ?? 0),
                'stalled_opportunities'  => 0,
            ],
            'metrics' => [
                'new_leads'          => (int) ($metrics['new_leads'] ?? 0),
                'open_opportunities' => (int) ($metrics['open_opportunities'] ?? 0),
                'pipeline_value'     => (int) ($metrics['pipeline_value'] ?? 0),
                'active_previews'    => (int) ($metrics['active_previews'] ?? 0),
                'contacts'           => (int) ($metrics['contacts'] ?? 0),
                'overdue_tasks'      => $overdue,
            ],
            'pipeline' => $pipeline,
            'recent'   => $recent,
            'health'   => [
                'queue_depth'   => (int) ($health['queue_depth'] ?? 0),
                'failed_jobs'   => (int) ($health['failed_jobs'] ?? 0),
                'mail_provider' => (string) ($health['mail_provider'] ?? ''),
                'production'    => (bool) ($health['production'] ?? false),
                'version'       => (string) ($health['version'] ?? 'dev'),
            ],
        ];
    }

    // ==================================================================
    // CRM (reads + core mutations)
    // ==================================================================

    /**
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function enquiries(string $method, array $seg): array
    {
        if ($method === 'GET' && !isset($seg[1])) {
            $q = $this->query();
            $items = $this->platform->enquiries()->search([
                'status' => $q['status'] ?? null,
                'limit'  => $this->perPage(),
            ]);

            return ['items' => array_map([$this, 'enquiryRow'], $items), 'total' => count($items)];
        }
        throw new ApiException(404, 'Not found.', 'not_found');
    }

    /**
     * @param list<string> $seg
     * @return mixed
     */
    private function contacts(string $method, array $seg)
    {
        if (isset($seg[1])) {
            $c = $this->platform->contacts()->find($seg[1]);
            if ($c === null) {
                throw new ApiException(404, 'Contact not found.', 'not_found');
            }

            return $this->contactDetail($c);
        }
        $items = $this->platform->contacts()->search(['limit' => $this->perPage()]);

        return ['items' => array_map([$this, 'contactRow'], $items), 'total' => count($items)];
    }

    /**
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function companies(string $method, array $seg): array
    {
        $items = $this->platform->companies()->all($this->perPage());

        return ['items' => array_map(static fn (array $r): array => [
            'id'            => (string) ($r['uuid'] ?? ''),
            'name'          => (string) ($r['name'] ?? ''),
            'website'       => (string) ($r['website'] ?? ''),
            'sector'        => (string) ($r['sector'] ?? ''),
            'location'      => (string) ($r['location'] ?? ''),
            'contact_count' => (int) ($r['contact_count'] ?? 0),
        ], $items), 'total' => $this->platform->companies()->count()];
    }

    /**
     * @param list<string> $seg
     * @return mixed
     */
    private function opportunities(string $method, array $seg)
    {
        if ($method === 'POST' && ($seg[1] ?? '') !== '' && ($seg[2] ?? '') === 'move') {
            if (!PanelGate::canManage($this->kirby->user())) {
                throw new ApiException(403, 'You can’t change deals.', 'forbidden');
            }
            $stage = (string) ($this->body()['stage'] ?? '');
            $moved = $this->platform->crm()->moveOpportunity($seg[1], $stage, 'user', (string) $this->kirby->user()?->email());

            return ['opportunity' => $moved];
        }

        $items = $this->platform->opportunities()->search(['limit' => $this->perPage()]);

        return ['items' => array_map(static fn (array $r): array => [
            'id'          => (string) ($r['uuid'] ?? ''),
            'title'       => (string) ($r['title'] ?? ''),
            'stage'       => (string) ($r['stage'] ?? ''),
            'value'       => (int) ($r['estimated_value'] ?? 0),
            'probability' => (int) ($r['probability'] ?? 0),
            'contact'     => (string) ($r['contact_name'] ?? ''),
            'next_action' => (string) ($r['next_action_date'] ?? ''),
        ], $items), 'total' => count($items), 'stages' => $this->stagesList()];
    }

    /**
     * @param list<string> $seg
     * @return mixed
     */
    private function tasks(string $method, array $seg)
    {
        if ($method === 'POST' && ($seg[1] ?? '') !== '' && ($seg[2] ?? '') === 'complete') {
            if (!PanelGate::canManage($this->kirby->user())) {
                throw new ApiException(403, 'You can’t change tasks.', 'forbidden');
            }
            $this->platform->tasks()->complete($seg[1]);

            return ['ok' => true];
        }

        $q = $this->query();
        $items = $this->platform->tasks()->search([
            'status'  => $q['status'] ?? null,
            'overdue' => ($q['view'] ?? '') === 'overdue' ? true : null,
            'limit'   => $this->perPage(),
        ]);

        return ['items' => array_map(static fn (array $r): array => [
            'id'        => (string) ($r['uuid'] ?? ''),
            'title'     => (string) ($r['title'] ?? ''),
            'status'    => (string) ($r['status'] ?? 'open'),
            'due_date'  => (string) ($r['due_date'] ?? ''),
            'assigned'  => (string) ($r['assigned_to'] ?? ''),
        ], $items), 'total' => count($items)];
    }

    /** @return array<string,mixed> */
    private function activities(): array
    {
        $q = $this->query();
        $type = (string) ($q['entity_type'] ?? '');
        $uuid = (string) ($q['entity_uuid'] ?? '');
        if ($type === '' || $uuid === '') {
            throw new ApiException(422, 'entity_type and entity_uuid are required.', 'invalid');
        }
        $items = $this->platform->activities()->forEntity($type, $uuid);

        return ['items' => array_map([$this, 'activityRow'], $items)];
    }

    // ==================================================================
    // Previews / reports / operations
    // ==================================================================

    /**
     * @param list<string> $seg
     * @return mixed
     */
    private function previews(array $seg)
    {
        if (!PanelGate::canAccess($this->kirby->user())) {
            throw new ApiException(403, 'No access.', 'forbidden');
        }
        $repo = $this->platform->previews();
        if (isset($seg[1])) {
            $p = $repo->find($seg[1]);
            if ($p === null) {
                throw new ApiException(404, 'Preview not found.', 'not_found');
            }

            return ['preview' => $this->previewRow($p, true)];
        }

        return ['items' => array_map(fn (array $r): array => $this->previewRow($r, false), $repo->all(['limit' => 200]))];
    }

    /**
     * Email delivery log — recent outbound messages and their status. Read-only:
     * a straight view of what the studio has sent and whether it landed. Requires
     * the email-delivery view permission.
     *
     * @return array<string,mixed>
     */
    private function email(\Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewEmailDelivery($user)) {
            throw new ApiException(403, 'You can’t view email delivery.', 'forbidden');
        }
        $rows = $this->platform->outbound()->search(['limit' => $this->perPage()]);

        $items = array_map(static fn (array $r): array => [
            'id'         => (string) ($r['uuid'] ?? ''),
            'to'         => (string) ($r['to_email'] ?? $r['recipient'] ?? ''),
            'subject'    => (string) ($r['subject'] ?? ''),
            'type'       => (string) ($r['message_type'] ?? ''),
            'status'     => (string) ($r['status'] ?? ''),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ], $rows);

        return [
            'items'    => $items,
            'total'    => count($items),
            'provider' => $this->platform->mailProvider()->name(),
            'failures' => $this->platform->outbound()->recentFailureCount(),
            'can_send' => PanelGate::canSendEmail($user),
        ];
    }

    /**
     * Public-site overview — the key pages of the live website with their status
     * and public URL, so the studio can see and open what’s published. Content
     * editing itself stays in the CMS; this is the map, not the editor.
     *
     * @return array<string,mixed>
     */
    private function website(): array
    {
        $site = $this->kirby->site();
        $pages = [];

        $home = $site->homePage();
        if ($home !== null) {
            $pages[] = $this->pageRow($home, true);
        }
        foreach ($site->children()->listed() as $page) {
            if ($home !== null && $page->id() === $home->id()) {
                continue;
            }
            $pages[] = $this->pageRow($page, false);
        }

        return [
            'items' => $pages,
            'total' => count($pages),
            'url'   => (string) $site->url(),
        ];
    }

    /** @return array<string,mixed> */
    private function pageRow(\Kirby\Cms\Page $page, bool $isHome): array
    {
        return [
            'id'       => (string) $page->id(),
            'title'    => (string) $page->title()->value(),
            'url'      => (string) $page->url(),
            'template' => (string) $page->intendedTemplate()->name(),
            'status'   => (string) $page->status(),
            'home'     => $isHome,
            'children' => $page->children()->listed()->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function reports(): array
    {
        $crm = $this->platform->crm();

        return [
            'enquiries_by_source' => $this->platform->enquiries()->countBySource(),
            'pipeline_by_stage'   => $this->platform->opportunities()->pipelineByStage(),
            'stages'              => $this->stagesList(),
            'open_opportunities'  => $crm->opportunities()->countOpen(),
            'pipeline_value'      => $crm->opportunities()->openPipelineValue(),
        ];
    }

    /** @return array<string,mixed> */
    private function operations(\Kirby\Cms\User $user): array
    {
        if (!$user->isAdmin()) {
            throw new ApiException(403, 'Admins only.', 'forbidden');
        }
        $q = $this->platform->queue();
        $health = (new DashboardData($this->platform))->systemHealth();

        return [
            'queue'  => ['pending' => $q->pendingCount(), 'failed' => $q->failedCount()],
            // Read the provider name from config (constructing it can throw in
            // production when it isn't configured — that's what 'ready' reports).
            'mail'   => [
                'provider'        => (string) ($health['mail_provider'] ?? ''),
                'ready'           => (bool) ($health['mail_ready'] ?? false),
                'recent_failures' => $this->platform->outbound()->recentFailureCount(),
            ],
            'health' => $health,
        ];
    }

    // ==================================================================
    // Row mappers (redact — never leak internal/secret fields)
    // ==================================================================

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function enquiryRow(array $r): array
    {
        return [
            'id'         => (string) ($r['uuid'] ?? ''),
            'reference'  => (string) ($r['reference'] ?? ''),
            'form_type'  => (string) ($r['form_type'] ?? ''),
            'status'     => (string) ($r['status'] ?? 'new'),
            'name'       => (string) ($r['contact_name'] ?? ''),
            'email'      => (string) ($r['contact_email'] ?? ''),
            'company'    => (string) ($r['company_name'] ?? ''),
            'summary'    => (string) ($r['summary'] ?? ''),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function contactRow(array $r): array
    {
        return [
            'id'          => (string) ($r['uuid'] ?? ''),
            'name'        => (string) ($r['display_name'] ?? ''),
            'email'       => (string) ($r['email'] ?? ''),
            'phone'       => (string) ($r['phone'] ?? ''),
            'company'     => (string) ($r['company_name'] ?? ''),
            'status'      => (string) ($r['status'] ?? ''),
            'lead_source' => (string) ($r['lead_source'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function contactDetail(array $c): array
    {
        $uuid = (string) ($c['uuid'] ?? '');

        return [
            'contact'    => $this->contactRow($c),
            'timeline'   => array_map([$this, 'activityRow'], $this->platform->activities()->forEntity('contact', $uuid)),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function activityRow(array $a): array
    {
        return [
            'id'      => (string) ($a['uuid'] ?? ''),
            'type'    => (string) ($a['type'] ?? ''),
            'summary' => (string) ($a['summary'] ?? ''),
            'actor'   => (string) ($a['actor_type'] ?? ''),
            'at'      => (string) ($a['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function previewRow(array $r, bool $detail): array
    {
        $row = [
            'id'            => (string) ($r['uuid'] ?? ''),
            'name'         => (string) ($r['name'] ?? ''),
            'client'       => (string) ($r['client_display_name'] ?? ''),
            'slug'         => (string) ($r['public_slug'] ?? ''),
            'status'       => (string) ($r['status'] ?? ''),
            'visibility'   => (string) ($r['visibility'] ?? ''),
            'password'     => (bool) ($r['password_protected'] ?? false),
            'views'        => (int) ($r['view_count'] ?? 0),
            'last_viewed'  => (string) ($r['last_viewed_at'] ?? ''),
            'expires_at'   => (string) ($r['expires_at'] ?? ''),
            'version_count' => (int) ($r['version_count'] ?? 0),
        ];
        if ($detail) {
            $row['url'] = $this->platform->previewUrls()->url((string) ($r['public_slug'] ?? ''));
        }

        return $row;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function requireCsrf(): void
    {
        $token = (string) ($this->kirby->request()->header('X-CSRF-Token') ?? '');
        if ($token === '' || csrf($token) !== true) {
            throw new ApiException(403, 'Your session needs refreshing.', 'csrf');
        }
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        return $this->kirby->request()->body()->toArray();
    }

    /** @return array<string,string> */
    private function query(): array
    {
        $q = $this->kirby->request()->query()->toArray();

        return array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $q);
    }

    private function perPage(): int
    {
        $n = (int) ($this->query()['per_page'] ?? 50);

        return max(1, min(200, $n));
    }

    /**
     * The pipeline stages as an ordered, labelled list for the kanban and reports.
     *
     * @return list<array{key:string,label:string}>
     */
    private function stagesList(): array
    {
        $out = [];
        foreach ($this->platform->crm()->stages() as $stage) {
            $out[] = ['key' => $stage, 'label' => $this->stageLabel($stage)];
        }

        return $out;
    }

    private function stageLabel(string $stage): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $stage));
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $a = mb_substr($parts[0] ?? '', 0, 1);
        $b = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($a . $b) ?: '·';
    }

    private function greeting(): string
    {
        $h = (int) date('G');
        $when = $h < 12 ? 'Morning' : ($h < 18 ? 'Afternoon' : 'Evening');
        $name = (string) ($this->kirby->user()?->name()->value() ?? '');
        $first = $name !== '' ? explode(' ', $name)[0] : 'there';

        return $when . ', ' . $first;
    }

    private function ago(string $iso): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }

        return floor($diff / 86400) . 'd ago';
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'application/json',
            $status,
        );
    }
}
