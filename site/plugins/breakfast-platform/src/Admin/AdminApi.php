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
            'email'         => $this->email($method, $seg, $user),
            'website'       => $this->website($method, $seg, $user),
            'hermes'        => $this->hermes($method, $seg, $user),
            'invoices'      => $this->invoices($method, $seg, $user),
            'proposals'     => $this->proposals($method, $seg, $user),
            'contracts'     => $this->contracts($method, $seg, $user),
            'contract-templates' => $this->contractTemplates($method, $seg, $user),
            'payments'      => $this->payments($method, $seg, $user),
            'projects'      => $this->projects($method, $seg, $user),
            'milestones'    => $this->milestones($method, $seg, $user),
            'project-tasks' => $this->projectTasks($method, $seg, $user),
            'project-templates' => $this->projectTemplatesApi($method, $seg, $user),
            'change-requests' => $this->changeRequests($method, $seg, $user),
            'portal'        => $this->portal($method, $seg, $user),
            'time'          => $this->time($method, $seg, $user),
            'onboarding'    => $this->onboarding($method, $seg, $user),
            'onboarding-templates' => $this->onboardingTemplatesApi($method, $seg, $user),
            'files'         => $this->files($method, $seg, $user),
            'vault'         => $this->vault($method, $seg, $user),
            'calendar'      => $this->calendar($method, $seg, $user),
            'search'        => $this->search(),
            'settings'      => $this->settings($method, $seg, $user),
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
        if (PanelGate::canViewHermes($user)) {
            $permissions[] = 'hermes.view';
        }
        if (PanelGate::canManageHermes($user)) {
            $permissions[] = 'hermes.manage';
        }
        if (PanelGate::canViewInvoices($user)) {
            $permissions[] = 'invoices.view';
        }
        if (PanelGate::canManageInvoices($user)) {
            $permissions[] = 'invoices.manage';
        }
        if (PanelGate::canEditWebsite($user)) {
            $permissions[] = 'website.edit';
        }
        if (PanelGate::canPublishWebsite($user)) {
            $permissions[] = 'website.publish';
        }
        if (PanelGate::canViewBrevo($user)) {
            $permissions[] = 'brevo.view';
        }
        if (PanelGate::canManageBrevo($user)) {
            $permissions[] = 'brevo.manage';
        }
        if (PanelGate::canViewCalendar($user)) {
            $permissions[] = 'calendar.view';
        }
        if (PanelGate::canManageCalendar($user)) {
            $permissions[] = 'calendar.manage';
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

        // Upcoming calendar events (next 7 days) — real data for the dashboard.
        $upcoming = [];
        foreach ($this->platform->calendar()->range(date('c'), date('c', time() + 7 * 86400)) as $ev) {
            $upcoming[] = [
                'id'         => (string) ($ev['uuid'] ?? ''),
                'title'      => (string) ($ev['title'] ?? ''),
                'starts_at'  => (string) ($ev['starts_at'] ?? ''),
                'event_type' => (string) ($ev['event_type'] ?? 'meeting'),
                'all_day'    => (bool) ($ev['all_day'] ?? false),
            ];
            if (count($upcoming) >= 6) {
                break;
            }
        }

        return [
            'greeting' => $this->greeting(),
            'date'     => date('l, j F'),
            'upcoming_events' => $upcoming,
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
        // Create a lead (contact + optional company + enquiry) — a real, atomic write.
        if ($method === 'POST' && !isset($seg[1])) {
            $actor = (string) $this->requireManage()->email();
            $created = $this->crmWrite()->createLead($this->body(), $actor);

            return [
                'lead'    => $this->enquiryRow(is_array($created['enquiry']) ? $created['enquiry'] : []),
                'contact' => $this->contactRow(is_array($created['contact']) ? $created['contact'] : []),
            ];
        }

        // Add a note to an enquiry's timeline.
        if ($method === 'POST' && ($seg[2] ?? '') === 'note') {
            $actor = (string) $this->requireManage()->email();
            $this->crmWrite()->addNote('enquiry', (string) $seg[1], (string) ($this->body()['note'] ?? ''), $actor);

            return ['ok' => true];
        }

        // Edit a lead.
        if ($method === 'PATCH' && isset($seg[1]) && !isset($seg[2])) {
            $actor = (string) $this->requireManage()->email();
            $res = $this->crmWrite()->editLead((string) $seg[1], $this->body(), $actor);

            return ['lead' => $this->enquiryRow(is_array($res['enquiry']) ? $res['enquiry'] : [])];
        }

        // Convert a lead to an opportunity (atomic).
        if ($method === 'POST' && ($seg[2] ?? '') === 'convert') {
            $actor = (string) $this->requireManage()->email();
            $res = $this->crmWrite()->convertLead((string) $seg[1], $this->body(), $actor);

            return ['opportunity' => $this->opportunityRow(is_array($res['opportunity']) ? $res['opportunity'] : [])];
        }

        // Archive a lead.
        if ($method === 'POST' && ($seg[2] ?? '') === 'archive') {
            $actor = (string) $this->requireManage()->email();
            $res = $this->crmWrite()->archiveLead((string) $seg[1], $actor);

            return ['lead' => $this->enquiryRow(is_array($res['enquiry']) ? $res['enquiry'] : [])];
        }

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
        // Create a contact (+ optional company), de-duplicated by email.
        if ($method === 'POST' && !isset($seg[1])) {
            $actor = (string) $this->requireManage()->email();
            $created = $this->crmWrite()->createContact($this->body(), $actor);

            return ['contact' => $this->contactRow(is_array($created['contact']) ? $created['contact'] : [])];
        }

        // Add a note to a contact's timeline.
        if ($method === 'POST' && ($seg[2] ?? '') === 'note') {
            $actor = (string) $this->requireManage()->email();
            $this->crmWrite()->addNote('contact', (string) $seg[1], (string) ($this->body()['note'] ?? ''), $actor);

            return ['ok' => true];
        }

        // Edit a contact.
        if ($method === 'PATCH' && isset($seg[1]) && !isset($seg[2])) {
            $actor = (string) $this->requireManage()->email();
            $res = $this->crmWrite()->editContact((string) $seg[1], $this->body(), $actor);

            return ['contact' => $this->contactRow(is_array($res['contact']) ? $res['contact'] : [])];
        }

        // Archive a contact.
        if ($method === 'POST' && ($seg[2] ?? '') === 'archive') {
            $actor = (string) $this->requireManage()->email();
            $res = $this->crmWrite()->archiveContact((string) $seg[1], $actor);

            return ['contact' => $this->contactRow(is_array($res['contact']) ? $res['contact'] : [])];
        }

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
        if ($method === 'POST' && !isset($seg[1])) {
            $actor = (string) $this->requireManage()->email();

            return ['company' => $this->companyRow($this->crmWrite()->createCompany($this->body(), $actor))];
        }

        $id     = (string) ($seg[1] ?? '');
        $action = (string) ($seg[2] ?? '');
        if ($id !== '') {
            if ($method === 'PATCH' && $action === '') {
                $actor = (string) $this->requireManage()->email();

                return ['company' => $this->companyRow($this->crmWrite()->editCompany($id, $this->body(), $actor)['company'])];
            }
            if ($method === 'POST' && $action === 'archive') {
                $actor = (string) $this->requireManage()->email();
                $res   = $this->crmWrite()->archiveCompany($id, $actor);

                return ['ok' => true, 'company' => $this->companyRow($res['company']), 'related' => $res['related']];
            }
            if ($method === 'POST' && $action === 'restore') {
                $actor = (string) $this->requireManage()->email();

                return ['ok' => true, 'company' => $this->companyRow($this->crmWrite()->restoreCompany($id, $actor)['company'])];
            }
        }

        $includeArchived = ($this->query()['archived'] ?? '') === '1';
        $items = $this->platform->companies()->all($this->perPage(), 0, $includeArchived);

        return ['items' => array_map([$this, 'companyRow'], $items), 'total' => $this->platform->companies()->count($includeArchived)];
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function companyRow(array $c): array
    {
        return [
            'id'            => (string) ($c['uuid'] ?? ''),
            'name'          => (string) ($c['name'] ?? ''),
            'website'       => (string) ($c['website'] ?? ''),
            'sector'        => (string) ($c['industry'] ?? ''),
            'location'      => (string) ($c['address'] ?? ''),
            'notes'         => (string) ($c['notes'] ?? ''),
            'contact_count' => (int) ($c['contact_count'] ?? 0),
            'archived'      => ($c['archived_at'] ?? null) !== null,
        ];
    }

    /**
     * @param list<string> $seg
     * @return mixed
     */
    private function opportunities(string $method, array $seg)
    {
        if ($method === 'POST' && !isset($seg[1])) {
            $actor = (string) $this->requireManage()->email();
            $opp = $this->crmWrite()->createOpportunity($this->body(), $actor);

            return ['opportunity' => [
                'id'          => (string) ($opp['uuid'] ?? ''),
                'title'       => (string) ($opp['title'] ?? ''),
                'stage'       => (string) ($opp['stage'] ?? ''),
                'value'       => (int) ($opp['estimated_value'] ?? 0),
                'probability' => (int) ($opp['probability'] ?? 0),
            ]];
        }

        $id     = (string) ($seg[1] ?? '');
        $action = (string) ($seg[2] ?? '');
        if ($id !== '') {
            $requireDeals = function (): string {
                if (!PanelGate::canManage($this->kirby->user())) {
                    throw new ApiException(403, 'You can’t change deals.', 'forbidden');
                }

                return (string) $this->kirby->user()?->email();
            };

            if ($method === 'POST' && $action === 'move') {
                $actor = $requireDeals();
                $stage = (string) ($this->body()['stage'] ?? '');
                $moved = $this->platform->crm()->moveOpportunity($id, $stage, 'user', $actor);

                return ['opportunity' => $moved];
            }
            if ($method === 'PATCH' && $action === '') {
                $actor = $requireDeals();

                return ['opportunity' => $this->opportunityRow($this->crmWrite()->editOpportunity($id, $this->body(), $actor)['opportunity'])];
            }
            if ($method === 'POST' && $action === 'close') {
                $actor   = $requireDeals();
                $outcome = (string) ($this->body()['outcome'] ?? '');

                return $this->crmWrite()->closeOpportunity($id, $outcome, $this->body(), $actor);
            }
            if ($method === 'POST' && $action === 'archive') {
                $actor = $requireDeals();

                return $this->crmWrite()->archiveOpportunity($id, $actor);
            }
            if ($method === 'POST' && $action === 'restore') {
                $actor = $requireDeals();

                return $this->crmWrite()->restoreOpportunity($id, $actor);
            }
        }

        $filters = ['limit' => $this->perPage()];
        if (($this->query()['archived'] ?? '') === '1') {
            $filters['include_archived'] = true;
        }
        $items = $this->platform->opportunities()->search($filters);

        return ['items' => array_map([$this, 'opportunityRow'], $items), 'total' => count($items), 'stages' => $this->stagesList()];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function opportunityRow(array $r): array
    {
        return [
            'id'            => (string) ($r['uuid'] ?? ''),
            'title'         => (string) ($r['title'] ?? ''),
            'stage'         => (string) ($r['stage'] ?? ''),
            'value'         => (int) ($r['estimated_value'] ?? 0),
            'probability'   => (int) ($r['probability'] ?? 0),
            'contact'       => (string) ($r['contact_name'] ?? ''),
            'next_action'   => (string) ($r['next_action_date'] ?? ''),
            'archived'      => ($r['archived_at'] ?? null) !== null,
            'close_outcome' => (string) ($r['close_outcome'] ?? ''),
        ];
    }

    /**
     * @param list<string> $seg
     * @return mixed
     */
    private function tasks(string $method, array $seg)
    {
        if ($method === 'POST' && !isset($seg[1])) {
            $actor = (string) $this->requireManage()->email();
            $task = $this->crmWrite()->createTask($this->body(), $actor);

            return ['task' => [
                'id'       => (string) ($task['uuid'] ?? ''),
                'title'    => (string) ($task['title'] ?? ''),
                'status'   => (string) ($task['status'] ?? 'open'),
                'due_date' => (string) ($task['due_date'] ?? ''),
                'assigned' => (string) ($task['assigned_to'] ?? ''),
            ]];
        }

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
     * Email: GET returns the delivery log (requires the email-delivery view
     * permission); POST /email/send and POST /email/test compose + queue a real
     * message (requires the send permission).
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function email(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        // Compose + send: queues a real message through the provider/outbox.
        if ($method === 'POST' && (($seg[1] ?? '') === 'send' || ($seg[1] ?? '') === 'test')) {
            return $this->sendEmail($user, ($seg[1] ?? '') === 'test');
        }

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
     * Compose and queue a real email through the provider/outbox. A success here
     * means QUEUED (accepted into the durable outbox), never "delivered" — the UI
     * labels it accurately. Suppressed/invalid recipients are refused server-side.
     *
     * @return array<string,mixed>
     */
    private function sendEmail(\Kirby\Cms\User $user, bool $test): array
    {
        if (!PanelGate::canSendEmail($user)) {
            throw new ApiException(403, 'You don’t have permission to send email.', 'forbidden');
        }
        $body    = $this->body();
        $to      = strtolower(trim((string) ($body['to'] ?? '')));
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = (string) ($body['body'] ?? '');
        $actor   = (string) $user->email();

        $errors = [];
        if (!$test && $to === '') {
            $errors['to'] = 'Enter a recipient.';
        } elseif (!$test && filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $errors['to'] = 'Enter a valid email address.';
        }
        if ($subject === '') {
            $errors['subject'] = 'Enter a subject.';
        }
        if (trim($message) === '') {
            $errors['body'] = 'Write a message.';
        }
        if ($errors !== []) {
            throw new ApiException(422, 'Please fix the highlighted fields.', 'invalid', $errors);
        }

        $links = [];
        foreach (['contact_uuid', 'enquiry_uuid', 'opportunity_uuid'] as $k) {
            $v = trim((string) ($body[$k] ?? ''));
            if ($v !== '') {
                $links[$k] = $v;
            }
        }

        $result = $test
            ? $this->platform->crmMail()->sendTest($actor, $subject, $message)
            : $this->platform->crmMail()->send($to, $subject, $message, $links, $actor);

        if (($result['ok'] ?? false) !== true) {
            $err = (string) ($result['error'] ?? 'send_failed');
            $msg = match ($err) {
                'invalid_recipient'     => 'That recipient address isn’t valid.',
                'recipient_suppressed'  => 'That recipient has unsubscribed or bounced — sending is blocked.',
                'subject_required'      => 'Enter a subject.',
                default                 => 'The message couldn’t be queued.',
            };
            throw new ApiException(422, $msg, $err);
        }

        $this->platform->audit()->event(
            $test ? 'email.test' : 'email.queued',
            'email',
            (string) ($result['uuid'] ?? ''),
            $actor,
            ['to' => $test ? $actor : $to]
        );

        return [
            'status'  => 'queued',
            'id'      => (string) ($result['uuid'] ?? ''),
            'message' => $test ? ('Test email queued to ' . $actor) : 'Email queued for delivery',
        ];
    }

    /**
     * Public-site overview — the key pages of the live website with their status
     * and public URL, so the studio can see and open what’s published. Content
     * editing itself stays in the CMS; this is the map, not the editor.
     *
     * @return array<string,mixed>
     */
    /**
     * Website content editing over the real Kirby content model.
     *
     * GET    /website                     — list editable pages + global settings
     * GET    /website/page/{id}           — load one model's fields + values
     * PATCH  /website/page/{id}           — save edits to the draft (changes)
     * POST   /website/page/{id}/publish   — apply the draft to live content
     * POST   /website/page/{id}/discard   — throw the draft away
     * POST   /website/page/{id}/unpublish — take a page offline (where allowed)
     * POST   /website/page/{id}/republish — put a page back online
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function website(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewWebsite($user)) {
            throw new ApiException(403, 'You don’t have access to the website.', 'forbidden');
        }
        $svc   = new \Breakfast\Platform\Website\WebsiteContent($this->kirby, $this->platform);
        $actor = (string) $user->email();
        $sub   = $seg[1] ?? '';

        $requireEdit = function () use ($user): void {
            if (!PanelGate::canEditWebsite($user)) {
                throw new ApiException(403, 'You can’t edit the website.', 'forbidden');
            }
        };
        $requirePublish = function () use ($user): void {
            if (!PanelGate::canPublishWebsite($user)) {
                throw new ApiException(403, 'You can’t publish website changes.', 'forbidden');
            }
        };

        try {
            if ($sub === '') {
                return $svc->index();
            }
            if ($sub !== 'page' || !isset($seg[2])) {
                throw new ApiException(404, 'Unknown website endpoint.', 'not_found');
            }
            $id     = $seg[2];
            $action = $seg[3] ?? '';

            // Media library: GET/POST /website/page/:id/media[/…]
            if ($action === 'media') {
                return $this->websiteMedia($method, $seg, $user, $id);
            }
            // Content sections: GET/POST/PATCH/DELETE /website/page/:id/sections[/…]
            if ($action === 'sections') {
                return $this->websiteSections($method, $seg, $user, $id);
            }

            if ($method === 'GET' && $action === '') {
                return $svc->load($id);
            }
            if ($method === 'PATCH' && $action === '') {
                $requireEdit();

                return $svc->save($id, $this->body(), $actor);
            }
            if ($method === 'POST') {
                return match ($action) {
                    'publish'   => $this->guarded($requirePublish, fn () => $svc->publish($id, $actor)),
                    'discard'   => $this->guarded($requireEdit, fn () => $svc->discard($id, $actor)),
                    'unpublish' => $this->guarded($requirePublish, fn () => $svc->unpublish($id, $actor)),
                    'republish' => $this->guarded($requirePublish, fn () => $svc->republish($id, $actor)),
                    default     => throw new ApiException(404, 'Unknown website action.', 'not_found'),
                };
            }

            throw new ApiException(404, 'Unknown website endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Website\WebsiteException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'website', $e->fields);
        }
    }

    /**
     * Website media library routes:
     *   GET    /website/page/:id/media                     list files
     *   POST   /website/page/:id/media                     upload (multipart, field "file")
     *   POST   /website/page/:id/media/:filename/replace   replace (multipart)
     *   DELETE /website/page/:id/media/:filename[?force=1]  delete
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function websiteMedia(string $method, array $seg, \Kirby\Cms\User $user, string $modelId): array
    {
        $media    = new \Breakfast\Platform\Website\WebsiteMedia($this->kirby, $this->platform);
        $actor    = (string) $user->email();
        $filename = isset($seg[4]) ? rawurldecode((string) $seg[4]) : '';
        $op       = (string) ($seg[5] ?? '');

        try {
            if ($method === 'GET' && $filename === '') {
                if (!PanelGate::canViewWebsiteMedia($user)) {
                    throw new ApiException(403, 'You can’t view website media.', 'forbidden');
                }

                return $media->list($modelId);
            }
            if (!PanelGate::canManageWebsiteMedia($user)) {
                throw new ApiException(403, 'You can’t manage website media.', 'forbidden');
            }
            if ($method === 'POST' && $filename === '') {
                return $media->upload($modelId, $this->uploadedFile(), $actor);
            }
            if ($method === 'POST' && $filename !== '' && $op === 'replace') {
                return $media->replace($modelId, $filename, $this->uploadedFile(), $actor);
            }
            if ($method === 'DELETE' && $filename !== '') {
                return $media->delete($modelId, $filename, $actor, ($this->query()['force'] ?? '') === '1');
            }

            throw new ApiException(404, 'Unknown media endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Website\WebsiteException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'website', $e->fields);
        }
    }

    /**
     * Website content-section routes (page-builder blocks):
     *   GET    /website/page/:id/sections                 list + concurrency hash
     *   POST   /website/page/:id/sections                 add {type, hash}
     *   POST   /website/page/:id/sections/reorder         reorder {order[], hash}
     *   PATCH  /website/page/:id/sections/:blockId         edit {text, hash}
     *   DELETE /website/page/:id/sections/:blockId         delete {hash}
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function websiteSections(string $method, array $seg, \Kirby\Cms\User $user, string $pageId): array
    {
        $svc     = new \Breakfast\Platform\Website\WebsiteSections($this->kirby, $this->platform);
        $actor   = (string) $user->email();
        $blockId = isset($seg[4]) ? rawurldecode((string) $seg[4]) : '';

        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageWebsiteSections($user)) {
                throw new ApiException(403, 'You can’t change website sections.', 'forbidden');
            }
        };

        try {
            if ($method === 'GET' && $blockId === '') {
                if (!PanelGate::canViewWebsite($user)) {
                    throw new ApiException(403, 'You can’t view the website.', 'forbidden');
                }

                return $svc->list($pageId);
            }
            $body = $this->body();
            $hash = (string) ($body['hash'] ?? '');
            if ($method === 'POST' && $blockId === 'reorder') {
                $requireManage();
                $order = array_values(array_map('strval', is_array($body['order'] ?? null) ? $body['order'] : []));

                return $svc->reorder($pageId, $order, $hash, $actor);
            }
            if ($method === 'POST' && $blockId === '') {
                $requireManage();

                return $svc->add($pageId, (string) ($body['type'] ?? ''), $hash, $actor);
            }
            if ($method === 'PATCH' && $blockId !== '') {
                $requireManage();

                return $svc->updateSection($pageId, $blockId, (string) ($body['text'] ?? ''), $hash, $actor);
            }
            if ($method === 'DELETE' && $blockId !== '') {
                $requireManage();

                return $svc->remove($pageId, $blockId, $hash, $actor);
            }

            throw new ApiException(404, 'Unknown sections endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Website\WebsiteException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'website', $e->fields);
        }
    }

    /**
     * The single uploaded file from a multipart request (field "file").
     *
     * @return array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}
     */
    private function uploadedFile(): array
    {
        $files = $this->kirby->request()->files()->get('file');
        if (!is_array($files) || !isset($files['tmp_name'])) {
            throw new ApiException(422, 'No file was uploaded.', 'invalid');
        }

        /** @var array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $files */
        return $files;
    }

    /**
     * Run $requirePermission (which throws on denial) then the action.
     *
     * @param callable():void $requirePermission
     * @param callable():array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function guarded(callable $requirePermission, callable $action): array
    {
        $requirePermission();

        return $action();
    }

    /**
     * Calendar events over the shared CRM model.
     *
     * GET    /calendar/events?from=&to=&contact_uuid=…  — occurrences in a range
     * GET    /calendar/events/:id                       — one event
     * POST   /calendar/events                           — create
     * PATCH  /calendar/events/:id                       — edit (scope/occurrence_date in body)
     * POST   /calendar/events/:id/move                  — move/resize
     * POST   /calendar/events/:id/complete              — mark completed
     * DELETE /calendar/events/:id                       — delete (scope/occurrence_date in body)
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function calendar(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewCalendar($user)) {
            throw new ApiException(403, 'You don’t have access to the calendar.', 'forbidden');
        }
        if (($seg[1] ?? '') !== 'events') {
            throw new ApiException(404, 'Unknown calendar endpoint.', 'not_found');
        }
        $svc    = $this->platform->calendar();
        $actor  = (string) $user->email();
        $id     = $seg[2] ?? '';
        $action = $seg[3] ?? '';

        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageCalendar($user)) {
                throw new ApiException(403, 'You can’t change the calendar.', 'forbidden');
            }
        };

        try {
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();

                    return ['event' => $this->calendarRow($svc->create($this->body(), $actor))];
                }
                // GET range
                $q    = $this->query();
                $from = (string) ($q['from'] ?? date('Y-m-01T00:00:00\Z'));
                $to   = (string) ($q['to'] ?? date('Y-m-t\T23:59:59\Z'));
                $events = $svc->range($from, $to, [
                    'contact_uuid'     => $q['contact_uuid'] ?? null,
                    'company_uuid'     => $q['company_uuid'] ?? null,
                    'opportunity_uuid' => $q['opportunity_uuid'] ?? null,
                    'type'             => $q['type'] ?? null,
                ]);

                return ['items' => array_map([$this, 'calendarRow'], $events), 'total' => count($events)];
            }

            if ($method === 'GET' && $action === '') {
                $event = $svc->find($id);
                if ($event === null) {
                    throw new ApiException(404, 'Event not found.', 'not_found');
                }

                return ['event' => $this->calendarRow($event)];
            }
            if ($method === 'PATCH' && $action === '') {
                $requireManage();
                $body = $this->body();
                $scope = (string) ($body['scope'] ?? 'series');
                $occ   = (string) ($body['occurrence_date'] ?? '');

                return ['event' => $this->calendarRow($svc->update($id, $body, $actor, $scope, $occ))];
            }
            if ($method === 'POST' && $action === 'move') {
                $requireManage();
                $body = $this->body();

                return ['event' => $this->calendarRow($svc->move($id, (string) ($body['starts_at'] ?? ''), (string) ($body['ends_at'] ?? ''), $actor))];
            }
            if ($method === 'POST' && $action === 'complete') {
                $requireManage();

                return ['event' => $this->calendarRow($svc->complete($id, $actor))];
            }
            if ($method === 'DELETE' && $action === '') {
                $requireManage();
                $body = $this->body();

                $svc->delete($id, $actor, (string) ($body['scope'] ?? 'series'), (string) ($body['occurrence_date'] ?? ''));

                return ['ok' => true];
            }

            throw new ApiException(404, 'Unknown calendar endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Calendar\CalendarException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'calendar', $e->fields);
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function calendarRow(array $r): array
    {
        return [
            'id'               => (string) ($r['uuid'] ?? ''),
            'title'            => (string) ($r['title'] ?? ''),
            'description'      => (string) ($r['description'] ?? ''),
            'starts_at'        => (string) ($r['starts_at'] ?? ''),
            'ends_at'          => (string) ($r['ends_at'] ?? ''),
            'all_day'          => (bool) ($r['all_day'] ?? false),
            'timezone'         => (string) ($r['timezone'] ?? 'Europe/London'),
            'location'         => (string) ($r['location'] ?? ''),
            'meeting_link'     => (string) ($r['meeting_link'] ?? ''),
            'event_type'       => (string) ($r['event_type'] ?? 'meeting'),
            'status'           => (string) ($r['status'] ?? 'confirmed'),
            'contact_uuid'     => (string) ($r['contact_uuid'] ?? ''),
            'company_uuid'     => (string) ($r['company_uuid'] ?? ''),
            'opportunity_uuid' => (string) ($r['opportunity_uuid'] ?? ''),
            'recurrence'       => (string) ($r['recurrence'] ?? ''),
            'recurrence_interval' => (int) ($r['recurrence_interval'] ?? 1),
            'recurrence_until' => (string) ($r['recurrence_until'] ?? ''),
            'recurrence_count' => (int) ($r['recurrence_count'] ?? 0),
            'reminder_minutes' => (int) ($r['reminder_minutes'] ?? 0),
            'occurrence_date'  => (string) ($r['occurrence_date'] ?? ''),
            'recurring'        => (bool) ($r['recurring'] ?? ($r['recurrence'] ?? '') !== ''),
        ];
    }

    /**
     * Application-managed settings. Currently the secure Brevo integration:
     *
     * GET    /settings/brevo       — overview (masked key hint + config, no secret)
     * PUT    /settings/brevo       — update non-secret config
     * POST   /settings/brevo/key   — set/replace the API key (stored encrypted)
     * DELETE /settings/brevo/key   — remove the API key
     * POST   /settings/brevo/test  — test authentication, account & sender
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function settings(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        $area = $seg[1] ?? '';
        if ($area === 'stripe') {
            return $this->stripeSettings($method, $seg, $user);
        }
        if ($area !== 'brevo') {
            throw new ApiException(404, 'Unknown settings area.', 'not_found');
        }
        if (!PanelGate::canViewBrevo($user)) {
            throw new ApiException(403, 'You don’t have access to integration settings.', 'forbidden');
        }
        $svc    = $this->platform->brevoSettings();
        $actor  = (string) $user->email();
        $action = $seg[2] ?? '';

        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageBrevo($user)) {
                throw new ApiException(403, 'You can’t change integration settings.', 'forbidden');
            }
        };

        try {
            if ($method === 'GET' && $action === '') {
                return ['brevo' => $svc->overview()];
            }
            if ($method === 'PUT' && $action === '') {
                $requireManage();

                return ['brevo' => $svc->update($this->body(), $actor)];
            }
            if ($action === 'key') {
                $requireManage();
                if ($method === 'POST') {
                    return ['brevo' => $svc->setKey((string) ($this->body()['api_key'] ?? ''), $actor)];
                }
                if ($method === 'DELETE') {
                    return ['brevo' => $svc->clearKey($actor)];
                }
            }
            if ($method === 'POST' && $action === 'test') {
                if (!PanelGate::canTestBrevo($user)) {
                    throw new ApiException(403, 'You can’t test the connection.', 'forbidden');
                }

                return $svc->test($actor);
            }

            throw new ApiException(404, 'Unknown settings endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Settings\SettingsException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'settings', $e->fields);
        }
    }

    /**
     * Stripe configuration. Viewing exposes only masked hints; storing keys,
     * the webhook secret and toggling live mode are admin-only. The secret key
     * and signing secret are write-only — never returned once stored. A live
     * self-test hits Stripe's /account with the stored key.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function stripeSettings(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canManageStripeSettings($user)) {
            throw new ApiException(403, 'Only an administrator can manage payment settings.', 'forbidden');
        }
        $svc    = $this->platform->stripeSettings();
        $actor  = (string) $user->email();
        $action = $seg[2] ?? '';

        try {
            if ($method === 'GET' && $action === '') {
                return ['stripe' => $svc->overview()];
            }
            if ($method === 'PUT' && $action === '') {
                return ['stripe' => $svc->update($this->body(), $actor)];
            }
            if ($action === 'key') {
                if ($method === 'POST') {
                    $svc->setSecretKey((string) ($this->body()['secret_key'] ?? ''), $actor);

                    return ['stripe' => $svc->overview()];
                }
                if ($method === 'DELETE') {
                    $svc->clearSecret($actor);

                    return ['stripe' => $svc->overview()];
                }
            }
            if ($method === 'POST' && $action === 'webhook-secret') {
                $svc->setWebhookSecret((string) ($this->body()['webhook_secret'] ?? ''), $actor);

                return ['stripe' => $svc->overview()];
            }
            if ($method === 'POST' && $action === 'test') {
                if ($svc->secretKey() === '') {
                    throw new ApiException(409, 'Add a Stripe secret key first.', 'payment');
                }
                $client  = new \Breakfast\Platform\Payments\StripeClient($svc->secretKey(), $svc->baseUrl());
                $account = $client->retrieveAccount();

                return ['ok' => true, 'account_id' => (string) ($account['id'] ?? ''), 'business' => (string) ($account['business_profile']['name'] ?? ($account['email'] ?? ''))];
            }

            throw new ApiException(404, 'Unknown settings endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Payments\PaymentException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'payment');
        }
    }

    // ==================================================================
    // Hermes integration console
    // ==================================================================

    /**
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function hermes(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        // Viewing the integration requires the Hermes view grant; operational
        // actions (self-test, credential generation) are admin-only. CSRF for the
        // POST actions is already enforced by route().
        if (!PanelGate::canViewHermes($user)) {
            throw new ApiException(403, 'You don’t have access to the Hermes integration.', 'forbidden');
        }
        $h        = new HermesAdmin($this->platform);
        $resource = $seg[1] ?? 'overview';
        $actor    = (string) $user->email();

        // ---- Actions (POST) — admin only ----
        if ($method === 'POST') {
            if (!PanelGate::canManageHermes($user)) {
                throw new ApiException(403, 'Only administrators can perform Hermes actions.', 'forbidden');
            }
            if ($resource === 'test') {
                $id = (string) ($this->body()['credential'] ?? '');
                if ($id === '') {
                    throw new ApiException(422, 'Choose a credential to test.', 'invalid', ['credential' => 'Required.']);
                }

                return $h->selfTest($id, $actor);
            }
            if ($resource === 'credentials' && ($seg[2] ?? '') === 'generate') {
                $body   = $this->body();
                $id     = (string) ($body['id'] ?? '');
                $scopes = is_array($body['scopes'] ?? null)
                    ? array_values(array_map(static fn ($s): string => (string) $s, $body['scopes']))
                    : [];

                return $h->generateCredentialLine($id, $scopes, $actor);
            }
            throw new ApiException(404, 'Unknown Hermes action.', 'not_found');
        }

        // ---- Reads (GET) ----
        return match ($resource) {
            'overview'    => $h->overview(),
            'credentials' => ['items' => $h->credentials()],
            'scopes'      => $h->scopes(),
            'health'      => $h->health(),
            'settings'    => $h->settings(),
            'activity'    => $this->hermesActivity($h, $seg),
            default       => throw new ApiException(404, 'Unknown Hermes endpoint.', 'not_found'),
        };
    }

    /**
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function hermesActivity(HermesAdmin $h, array $seg): array
    {
        if (isset($seg[2]) && $seg[2] !== '') {
            $detail = $h->activityDetail($seg[2]);
            if ($detail === null) {
                throw new ApiException(404, 'Activity entry not found.', 'not_found');
            }

            return ['entry' => $detail];
        }
        $q = $this->query();

        return $h->activity(
            ['result' => $q['result'] ?? '', 'credential' => $q['credential'] ?? ''],
            max(1, (int) ($q['page'] ?? 1)),
            $this->perPage(),
        );
    }

    // ==================================================================
    // Invoicing
    // ==================================================================

    /**
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function invoices(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewInvoices($user)) {
            throw new ApiException(403, 'You don’t have access to invoicing.', 'forbidden');
        }
        $svc   = $this->platform->invoices();
        $actor = (string) $user->email();
        $sub   = $seg[1] ?? '';

        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageInvoices($user)) {
                throw new ApiException(403, 'You can’t change invoices.', 'forbidden');
            }
        };

        try {
            if ($sub === 'settings') {
                if ($method === 'PATCH') {
                    $requireManage();

                    return ['settings' => $this->settingsRow($svc->updateSettings($this->body()))];
                }

                return ['settings' => $this->settingsRow($svc->settings())];
            }

            if ($sub === '') {
                if ($method === 'POST') {
                    $requireManage();

                    return ['invoice' => $this->invoiceRow($svc->create($this->body(), $actor), true)];
                }
                $q = $this->query();

                return ['items' => array_map(
                    fn (array $r): array => $this->invoiceRow($r, false),
                    $svc->list(['status' => $q['status'] ?? null, 'limit' => $this->perPage()])
                )];
            }

            $id     = $sub;
            $action = $seg[2] ?? '';

            if ($method === 'GET' && $action === '') {
                $inv = $svc->find($id);
                if ($inv === null) {
                    throw new ApiException(404, 'Invoice not found.', 'not_found');
                }

                return ['invoice' => $this->invoiceRow($inv, true)];
            }
            if ($method === 'PATCH' && $action === '') {
                $requireManage();

                return ['invoice' => $this->invoiceRow($svc->update($id, $this->body(), $actor), true)];
            }
            if ($method === 'POST' && $action === 'issue') {
                $requireManage();
                $issued = $svc->issue($id, $actor);
                // Generate the immutable PDF synchronously from the frozen snapshot.
                try {
                    $this->platform->invoiceDocuments()->generateIssued($id, $actor);
                } catch (\Breakfast\Platform\Invoicing\InvoiceException) {
                    // Invoice stays issued; document_status is 'failed' and sending
                    // remains blocked until a safe retry succeeds.
                }

                return ['invoice' => $this->invoiceRow($svc->find($id) ?? $issued, true)];
            }
            if ($method === 'POST' && $action === 'payment') {
                $requireManage();

                return ['invoice' => $this->invoiceRow($svc->recordPayment($id, $this->body(), $actor), true)];
            }
            if ($method === 'POST' && $action === 'payment-link') {
                if (!PanelGate::canCreatePaymentLink($user)) {
                    throw new ApiException(403, 'You can’t create payment links.', 'forbidden');
                }
                try {
                    $siteUrl = rtrim((string) $this->kirby->site()->url(), '/');
                    $link = $this->platform->payments()->createCheckout($id, $actor, $siteUrl);
                } catch (\Breakfast\Platform\Payments\PaymentException $e) {
                    throw new ApiException($e->status, $e->getMessage(), 'payment');
                }

                return ['link' => $link, 'invoice' => $this->invoiceRow($svc->find($id) ?? [], true)];
            }
            if ($method === 'GET' && $action === 'payments') {
                if (!PanelGate::canViewPayments($user)) {
                    throw new ApiException(403, 'You can’t view payments.', 'forbidden');
                }

                return ['items' => array_map([$this, 'paymentRow'], $this->platform->payments()->list(['invoice_uuid' => $id]))];
            }
            if ($method === 'POST' && $action === 'void') {
                $requireManage();

                return ['invoice' => $this->invoiceRow($svc->void($id, $actor), true)];
            }
            if ($method === 'POST' && $action === 'send') {
                $requireManage();

                return $this->sendInvoice($id, $user);
            }
            // PDF: POST /invoices/:id/pdf (draft), POST /invoices/:id/pdf/regenerate,
            // GET /invoices/:id/pdf/versions. Binary download is a separate route.
            if ($action === 'pdf') {
                $docs = $this->platform->invoiceDocuments();
                $sub2 = $seg[3] ?? '';
                if ($method === 'POST' && $sub2 === '') {
                    if (!PanelGate::canGenerateInvoicePdf($user)) {
                        throw new ApiException(403, 'You can’t generate invoice documents.', 'forbidden');
                    }

                    return ['document' => $this->documentRow($docs->generateDraft($id, $actor))];
                }
                if ($method === 'POST' && $sub2 === 'regenerate') {
                    if (!PanelGate::canRegenerateInvoicePdf($user)) {
                        throw new ApiException(403, 'Only an administrator can regenerate an issued invoice’s document.', 'forbidden');
                    }

                    return ['document' => $this->documentRow($docs->regenerateIssued($id, $actor, (string) ($this->body()['reason'] ?? '')))];
                }
                if ($method === 'GET' && $sub2 === 'versions') {
                    return ['items' => array_map([$this, 'documentRow'], $docs->versions($id))];
                }
            }

            throw new ApiException(404, 'Unknown invoice endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Invoicing\InvoiceException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'invoice');
        }
    }

    /**
     * Proposals module. All staff routes require the CRM 'manage' grant; the
     * public client link + acceptance live on a separate tokened path.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function proposals(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewProposals($user)) {
            throw new ApiException(403, 'You don’t have access to proposals.', 'forbidden');
        }
        $svc   = $this->platform->proposals();
        $actor = (string) $user->email();
        $id    = $seg[1] ?? '';

        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageProposals($user)) {
                throw new ApiException(403, 'You can’t change proposals.', 'forbidden');
            }
        };

        try {
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();

                    return ['proposal' => $this->proposalRow($svc->create($this->body(), $actor), true)];
                }
                $q = $this->query();

                return ['items' => array_map(fn (array $r): array => $this->proposalRow($r, false), $svc->list(['status' => $q['status'] ?? null, 'limit' => $this->perPage()]))];
            }

            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $p = $svc->find($id);
                if ($p === null) {
                    throw new ApiException(404, 'Proposal not found.', 'not_found');
                }

                return ['proposal' => $this->proposalRow($p, true)];
            }
            if ($method === 'PATCH' && $action === '') {
                $requireManage();

                return ['proposal' => $this->proposalRow($svc->update($id, $this->body(), $actor), true)];
            }
            if ($method === 'POST') {
                $requireManage();
                switch ($action) {
                    case 'ready':
                        return ['proposal' => $this->proposalRow($svc->markReady($id, $actor), true)];
                    case 'send':
                        $sent = $svc->send($id, $actor, $this->platform->invoices()->settings());
                        try {
                            $this->platform->proposalDocuments()->generate($id, $actor);
                        } catch (\Breakfast\Platform\Proposals\ProposalException) {
                            // stays sent; document_status is 'failed'
                        }
                        $this->proposalActivity($svc->find($id) ?? $sent, 'proposal.sent', 'Proposal sent');

                        return ['proposal' => $this->proposalRow($svc->find($id) ?? $sent, true)];
                    case 'withdraw':
                        return ['proposal' => $this->proposalRow($svc->withdraw($id, $actor), true)];
                    case 'supersede':
                        return ['proposal' => $this->proposalRow($svc->supersede($id, $actor), true)];
                    case 'reject':
                        return ['proposal' => $this->proposalRow($svc->reject($id, (string) ($this->body()['reason'] ?? ''), 'staff'), true)];
                    case 'convert':
                        $steps = array_values(array_map('strval', is_array($this->body()['steps'] ?? null) ? $this->body()['steps'] : ['won', 'deposit']));
                        $result = $this->platform->proposalConversion()->convert($id, $steps, $actor);

                        return ['result' => $result, 'proposal' => $this->proposalRow($svc->find($id) ?? [], true)];
                }
            }

            throw new ApiException(404, 'Unknown proposal endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Proposals\ProposalException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'proposal');
        }
    }

    /** @param array<string,mixed> $p */
    private function proposalActivity(array $p, string $type, string $summary): void
    {
        $contact = (string) ($p['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, $type, $summary . ' (' . (string) ($p['number'] ?? '') . ')', 'user', null, ['proposal' => (string) ($p['uuid'] ?? '')]);
        }
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function proposalRow(array $p, bool $detail): array
    {
        $row = [
            'id'         => (string) ($p['uuid'] ?? ''),
            'number'     => (string) ($p['number'] ?? ''),
            'status'     => (string) ($p['status'] ?? 'draft'),
            'title'      => (string) ($p['title'] ?? ''),
            'client'     => (string) ($p['client_name'] ?? ''),
            'total'      => (int) ($p['total'] ?? 0) / 100,
            'currency'   => (string) ($p['currency'] ?? 'GBP'),
            'created_at' => (string) ($p['created_at'] ?? ''),
            'sent_at'    => (string) ($p['sent_at'] ?? ''),
            'accepted_at' => (string) ($p['accepted_at'] ?? ''),
            'pdf_ready'  => ((string) ($p['document_status'] ?? '')) === 'generated',
            'public_url' => (string) ($p['public_token'] ?? '') !== '' ? rtrim((string) $this->kirby->site()->url(), '/') . '/proposal/' . (string) $p['public_token'] : '',
        ];
        if (!$detail) {
            return $row;
        }
        $row['contact_uuid']     = (string) ($p['contact_uuid'] ?? '');
        $row['company_uuid']     = (string) ($p['company_uuid'] ?? '');
        $row['opportunity_uuid'] = (string) ($p['opportunity_uuid'] ?? '');
        $row['client_email']     = (string) ($p['client_email'] ?? '');
        $row['expiry_date']      = (string) ($p['expiry_date'] ?? '');
        $row['owner']            = (string) ($p['owner'] ?? '');
        $row['deposit_amount']   = (int) ($p['deposit_amount'] ?? 0) / 100;
        $row['subtotal']         = (int) ($p['subtotal'] ?? 0) / 100;
        $row['tax_total']        = (int) ($p['tax_total'] ?? 0) / 100;
        $row['recurring_total']  = (int) ($p['recurring_total'] ?? 0) / 100;
        $row['document_status']  = (string) ($p['document_status'] ?? 'none');
        foreach (['introduction', 'client_problem', 'recommended_solution', 'scope', 'deliverables', 'exclusions', 'assumptions', 'timeline', 'payment_schedule', 'terms', 'internal_notes'] as $f) {
            $row[$f] = (string) ($p[$f] ?? '');
        }
        $row['items'] = array_map(static fn (array $i): array => [
            'id'          => (string) ($i['uuid'] ?? ''),
            'kind'        => (string) ($i['kind'] ?? 'fixed'),
            'description' => (string) ($i['description'] ?? ''),
            'quantity'    => (int) ($i['quantity'] ?? 0) / 1000,
            'unit_price'  => (int) ($i['unit_price'] ?? 0) / 100,
            'tax_rate'    => (int) ($i['tax_rate'] ?? 0) / 100,
            'discount'    => (int) ($i['discount'] ?? 0) / 100,
            'recurrence'  => (string) ($i['recurrence'] ?? ''),
            'is_selected' => (int) ($i['is_selected'] ?? 0) === 1,
            'line_total'  => (int) ($i['line_total'] ?? 0) / 100,
        ], is_array($p['items'] ?? null) ? $p['items'] : []);
        $row['events'] = array_map(static fn (array $e): array => [
            'type' => (string) ($e['type'] ?? ''), 'detail' => (string) ($e['detail'] ?? ''),
            'actor' => (string) ($e['actor'] ?? ''), 'created_at' => (string) ($e['created_at'] ?? ''),
        ], is_array($p['events'] ?? null) ? $p['events'] : []);
        $row['acceptances'] = array_map(static fn (array $a): array => [
            'name' => (string) ($a['accepted_name'] ?? ''), 'email' => (string) ($a['accepted_email'] ?? ''),
            'total' => (int) ($a['total_accepted'] ?? 0) / 100, 'hash' => (string) ($a['document_hash'] ?? ''),
            'created_at' => (string) ($a['created_at'] ?? ''),
        ], is_array($p['acceptances'] ?? null) ? $p['acceptances'] : []);

        return $row;
    }

    // ==================================================================
    // Contracts
    // ==================================================================

    /**
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function contracts(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewContracts($user)) {
            throw new ApiException(403, 'You don’t have access to contracts.', 'forbidden');
        }
        $svc   = $this->platform->contracts();
        $actor = (string) $user->email();
        $id    = $seg[1] ?? '';

        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageContracts($user)) {
                throw new ApiException(403, 'You can’t change contracts.', 'forbidden');
            }
        };

        try {
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();
                    $body = $this->body();

                    return ['contract' => $this->contractRow($this->createContract($body, $actor), true)];
                }
                $q = $this->query();

                return ['items' => array_map(fn (array $r): array => $this->contractRow($r, false), $svc->list(['status' => $q['status'] ?? null, 'limit' => $this->perPage()]))];
            }

            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $c = $svc->find($id);
                if ($c === null) {
                    throw new ApiException(404, 'Contract not found.', 'not_found');
                }

                return ['contract' => $this->contractRow($c, true)];
            }
            if ($method === 'GET' && $action === 'evidence') {
                if (!PanelGate::canViewContractEvidence($user)) {
                    throw new ApiException(403, 'You can’t view signature evidence.', 'forbidden');
                }
                $c = $svc->find($id);

                return ['evidence' => array_map([$this, 'signatureRow'], is_array($c['signatures'] ?? null) ? $c['signatures'] : [])];
            }
            if ($method === 'PATCH' && $action === '') {
                $requireManage();

                return ['contract' => $this->contractRow($svc->update($id, $this->body(), $actor), true)];
            }
            if ($method === 'POST') {
                switch ($action) {
                    case 'ready':
                        $requireManage();

                        return ['contract' => $this->contractRow($svc->markReady($id, $actor), true)];
                    case 'send':
                        $requireManage();

                        return ['contract' => $this->contractRow($this->sendContract($id, $actor), true)];
                    case 'remind':
                        $requireManage();
                        $this->emailContract($svc->find($id) ?? [], 'reminder');

                        return ['contract' => $this->contractRow($svc->find($id) ?? [], true)];
                    case 'withdraw':
                        $requireManage();

                        return ['contract' => $this->contractRow($svc->withdraw($id, $actor), true)];
                    case 'supersede':
                        $requireManage();

                        return ['contract' => $this->contractRow($svc->supersede($id, (string) ($this->body()['reason'] ?? ''), $actor), true)];
                    case 'void':
                        if (!PanelGate::canVoidContracts($user)) {
                            throw new ApiException(403, 'Only an administrator can void a contract.', 'forbidden');
                        }

                        return ['contract' => $this->contractRow($svc->void($id, (string) ($this->body()['reason'] ?? ''), $actor), true)];
                    case 'sign-internal':
                        if (!PanelGate::canSignContractInternal($user)) {
                            throw new ApiException(403, 'You can’t countersign contracts.', 'forbidden');
                        }
                        $result = $svc->signInternal($id, ['name' => (string) ($this->body()['name'] ?? $user->name()->or($actor)->value()), 'email' => $actor, 'user_agent' => 'admin', 'wording' => 'Countersigned by Breakfast via the admin.']);
                        $this->afterContractSignature($id, $actor, $result['completed']);

                        return ['contract' => $this->contractRow($svc->find($id) ?? [], true), 'completed' => $result['completed']];
                }
            }

            throw new ApiException(404, 'Unknown contract endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Contracts\ContractException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'contract');
        }
    }

    /**
     * Create a contract, pre-filling from an accepted proposal when given.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function createContract(array $body, string $actor): array
    {
        $proposalUuid = trim((string) ($body['proposal_uuid'] ?? ''));
        if ($proposalUuid !== '') {
            $p = $this->platform->proposals()->find($proposalUuid);
            if ($p === null) {
                throw new ApiException(404, 'Proposal not found.', 'not_found');
            }
            // Prevent accidental duplicates: block if a non-terminal contract
            // already exists for this proposal (a new version is intentional).
            $existing = array_filter($this->platform->contracts()->list(['limit' => 200]), static fn (array $c): bool => (string) ($c['proposal_uuid'] ?? '') === $proposalUuid && !in_array((string) $c['status'], ['void', 'declined', 'withdrawn', 'superseded'], true));
            if ($existing !== [] && empty($body['force'])) {
                throw new ApiException(409, 'A contract already exists for this proposal.', 'conflict');
            }
            $body = array_merge([
                'title'            => (string) ($p['title'] ?? 'Contract') . ' — agreement',
                'template_key'     => 'website_build',
                'company_uuid'     => $p['company_uuid'] ?? null,
                'contact_uuid'     => $p['contact_uuid'] ?? null,
                'opportunity_uuid' => $p['opportunity_uuid'] ?? null,
                'proposal_uuid'    => $proposalUuid,
                'client_name'      => (string) ($p['client_name'] ?? ''),
                'client_email'     => (string) ($p['client_email'] ?? ''),
                'seller_name'      => (string) ($p['seller_name'] ?? '') ?: (string) ($this->platform->invoices()->settings()['company_legal_name'] ?? 'Breakfast'),
                'contract_value'   => (int) ($p['total'] ?? 0) / 100,
                'deposit_amount'   => (int) ($p['deposit_amount'] ?? 0) / 100,
            ], $body);
        }

        return $this->platform->contracts()->create($body, $actor);
    }

    /**
     * Send: resolve merge fields, freeze, generate the unsigned PDF, email the
     * signing link and record a CRM activity.
     *
     * @return array<string,mixed>
     */
    private function sendContract(string $id, string $actor): array
    {
        $svc  = $this->platform->contracts();
        $sent = $svc->send($id, $actor, $this->contractMergeValues($id));
        try {
            $this->platform->contractDocuments()->generateUnsigned($id, $actor);
        } catch (\Breakfast\Platform\Contracts\ContractException) {
            // stays sent; document_status is 'failed'
        }
        $fresh = $svc->find($id) ?? $sent;
        $this->emailContract($fresh, 'invite');
        $this->contractActivity($fresh, 'contract.sent', 'Contract sent for signature');

        return $fresh;
    }

    /**
     * Resolve merge fields from the contract's linked records + settings.
     *
     * @return array<string,string>
     */
    private function contractMergeValues(string $id): array
    {
        $c = $this->platform->contracts()->find($id) ?? [];
        $money = static fn (int $p): string => '£' . number_format($p / 100, 2);
        $proposalNumber = '';
        if (($c['proposal_uuid'] ?? '') !== '') {
            $p = $this->platform->proposals()->find((string) $c['proposal_uuid']);
            $proposalNumber = (string) ($p['number'] ?? '');
        }

        return [
            'client_name'     => (string) ($c['client_name'] ?? ''),
            'company_name'    => (string) ($c['client_name'] ?? ''),
            'contact_name'    => (string) ($c['client_name'] ?? ''),
            'seller_name'     => (string) ($c['seller_name'] ?? '') ?: 'Breakfast',
            'proposal_number' => $proposalNumber ?: 'the agreed proposal',
            'project_name'    => (string) ($c['title'] ?? ''),
            'contract_value'  => $money((int) ($c['contract_value'] ?? 0)),
            'deposit_amount'  => $money((int) ($c['deposit_amount'] ?? 0)),
            'start_date'      => (string) ($c['effective_date'] ?? '') ?: 'the agreed start date',
            'target_date'     => (string) ($c['expiry_date'] ?? '') ?: 'the agreed target date',
            'revision_limit'  => (string) ((int) ($c['revision_limit'] ?? 0)),
            'governing_law'   => (string) ($c['governing_law'] ?? 'the laws of England and Wales'),
        ];
    }

    /** After a countersignature, generate the signed PDF + email parties if complete. */
    private function afterContractSignature(string $id, string $actor, bool $completed): void
    {
        if (!$completed) {
            return;
        }
        try {
            $this->platform->contractDocuments()->generateSigned($id, $actor);
            $this->emailContract($this->platform->contracts()->find($id) ?? [], 'completed');
            $this->contractActivity($this->platform->contracts()->find($id) ?? [], 'contract.completed', 'Contract fully signed');
        } catch (\Breakfast\Platform\Contracts\ContractException) {
            // non-fatal
        }
    }

    /** @param array<string,mixed> $c */
    private function emailContract(array $c, string $kind): void
    {
        $to = trim((string) ($c['client_email'] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $token  = (string) ($c['public_token'] ?? '');
        $url    = rtrim((string) $this->kirby->site()->url(), '/') . '/contract/' . $token;
        $number = (string) ($c['number'] ?? '');
        $seller = (string) ($c['seller_name'] ?? '') ?: 'Breakfast';
        [$subject, $body, $attachments] = match ($kind) {
            'completed' => [
                'Your signed contract ' . $number,
                "Hi,\n\nThank you — contract {$number} is now fully signed. Your signed copy is attached.\n\n{$seller}",
                ['contract-signed:' . (string) ($c['uuid'] ?? '')],
            ],
            'reminder' => [
                'Reminder: please sign contract ' . $number,
                "Hi,\n\nA quick reminder to review and sign contract {$number}:\n{$url}\n\nThank you,\n{$seller}",
                [],
            ],
            default => [
                'Please sign contract ' . $number . ' from ' . $seller,
                "Hi,\n\nYour contract {$number} is ready to review and sign here:\n{$url}\n\nThank you,\n{$seller}",
                [],
            ],
        };
        $this->platform->crmMail()->send($to, $subject, $body, [], 'system', $attachments);
    }

    /** @param array<string,mixed> $c */
    private function contractActivity(array $c, string $type, string $summary): void
    {
        $contact = (string) ($c['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, $type, $summary . ' (' . (string) ($c['number'] ?? '') . ')', 'user', null, ['contract' => (string) ($c['uuid'] ?? '')]);
        }
    }

    /**
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function contractTemplates(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewContracts($user)) {
            throw new ApiException(403, 'You don’t have access to contract templates.', 'forbidden');
        }
        $items = [];
        foreach (\Breakfast\Platform\Contracts\ContractTemplates::all() as $key => $t) {
            $items[] = ['key' => $key, 'name' => $t['name'], 'description' => $t['description'], 'sections' => $t['sections'], 'default_revision_limit' => $t['default_revision_limit'], 'default_payment_terms' => $t['default_payment_terms']];
        }

        return ['items' => $items];
    }

    /**
     * Online payments (Stripe). Listing + viewing follow invoice viewing;
     * refunding is admin-only. Payment links are created from the invoice route
     * (POST /invoices/:id/payment-link) so the amount is always invoice-derived.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function payments(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewPayments($user)) {
            throw new ApiException(403, 'You don’t have access to payments.', 'forbidden');
        }
        $id = $seg[1] ?? '';
        try {
            if ($id === '' && $method === 'GET') {
                $q = $this->query();

                return ['items' => array_map([$this, 'paymentRow'], $this->platform->payments()->list(['status' => $q['status'] ?? null, 'limit' => $this->perPage()]))];
            }
            $action = $seg[2] ?? '';
            if ($id !== '' && $method === 'GET' && $action === '') {
                $p = $this->platform->payments()->find($id);
                if ($p === null) {
                    throw new ApiException(404, 'Payment not found.', 'not_found');
                }

                return ['payment' => $this->paymentRow($p, true)];
            }
            if ($id !== '' && $method === 'POST' && $action === 'refund') {
                if (!PanelGate::canRefundPayments($user)) {
                    throw new ApiException(403, 'Only an administrator can issue refunds.', 'forbidden');
                }
                $body   = $this->body();
                $amount = (int) round(((float) ($body['amount'] ?? 0)) * 100);
                $reason = (string) ($body['reason'] ?? '');

                return $this->platform->payments()->refund($id, $amount, $reason, (string) $user->email());
            }

            throw new ApiException(404, 'Unknown payment endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Payments\PaymentException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'payment');
        }
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function paymentRow(array $p, bool $detail = false): array
    {
        $row = [
            'id'              => (string) ($p['uuid'] ?? ''),
            'invoice_uuid'    => (string) ($p['invoice_uuid'] ?? ''),
            'status'          => (string) ($p['status'] ?? 'pending'),
            'amount'          => (int) ($p['amount'] ?? 0) / 100,
            'amount_refunded' => (int) ($p['amount_refunded'] ?? 0) / 100,
            'currency'        => (string) ($p['currency'] ?? 'GBP'),
            'method_summary'  => (string) ($p['method_summary'] ?? ''),
            'receipt_number'  => (string) ($p['receipt_number'] ?? ''),
            'mode'            => (string) ($p['mode'] ?? 'test'),
            'created_at'      => (string) ($p['created_at'] ?? ''),
            'paid_at'         => (string) ($p['paid_at'] ?? ''),
        ];
        if (!$detail) {
            return $row;
        }
        $row['provider_payment_id'] = (string) ($p['provider_payment_id'] ?? '');
        $row['failure_message']     = (string) ($p['failure_message'] ?? '');
        $row['has_receipt']         = is_array($p['receipt'] ?? null) && $p['receipt'] !== [];
        $row['events'] = array_map(static fn (array $e): array => [
            'type' => (string) ($e['type'] ?? ''), 'detail' => (string) ($e['detail'] ?? ''), 'created_at' => (string) ($e['created_at'] ?? ''),
        ], is_array($p['events'] ?? null) ? $p['events'] : []);
        $row['refunds'] = array_map(static fn (array $r): array => [
            'amount' => (int) ($r['amount'] ?? 0) / 100, 'reason' => (string) ($r['reason'] ?? ''),
            'status' => (string) ($r['status'] ?? ''), 'created_at' => (string) ($r['created_at'] ?? ''),
        ], is_array($p['refunds'] ?? null) ? $p['refunds'] : []);

        return $row;
    }

    /**
     * Projects (Phase 2 — Delivery). All staff routes require CRM access to view
     * and the 'manage' grant to mutate. A project can be created manually or from
     * an accepted proposal / completed contract / won opportunity.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function projects(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewProjects($user)) {
            throw new ApiException(403, 'You don’t have access to projects.', 'forbidden');
        }
        $svc   = $this->platform->projects();
        $actor = (string) $user->email();
        $id    = $seg[1] ?? '';

        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageProjects($user)) {
                throw new ApiException(403, 'You can’t change projects.', 'forbidden');
            }
        };

        try {
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();

                    return ['project' => $this->projectRow($this->createProject($this->body(), $actor), true)];
                }
                $q = $this->query();

                return ['items' => array_map(fn (array $r): array => $this->projectRow($r, false), $svc->list([
                    'status'   => $q['status'] ?? null,
                    'archived' => ($q['archived'] ?? '') === '1',
                    'limit'    => $this->perPage(),
                ]))];
            }

            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $p = $svc->find($id);
                if ($p === null) {
                    throw new ApiException(404, 'Project not found.', 'not_found');
                }

                return ['project' => $this->projectRow($p, true)];
            }
            if ($method === 'PATCH' && $action === '') {
                $requireManage();
                $body = $this->body();
                $rev  = array_key_exists('revision', $body) ? (int) $body['revision'] : null;

                return ['project' => $this->projectRow($svc->update($id, $body, $actor, $rev), true)];
            }
            if ($method === 'POST') {
                switch ($action) {
                    case 'status':
                        $requireManage();
                        $body = $this->body();

                        return ['project' => $this->projectRow($svc->transition($id, (string) ($body['status'] ?? ''), $body, $actor), true)];
                    case 'reopen':
                        $requireManage();

                        return ['project' => $this->projectRow($svc->reopen($id, $actor), true)];
                    case 'archive':
                        if (!PanelGate::canArchiveProjects($user)) {
                            throw new ApiException(403, 'You can’t archive projects.', 'forbidden');
                        }

                        return ['project' => $this->projectRow($svc->archive($id, $actor), true)];
                    case 'restore':
                        if (!PanelGate::canArchiveProjects($user)) {
                            throw new ApiException(403, 'You can’t restore projects.', 'forbidden');
                        }

                        return ['project' => $this->projectRow($svc->restore($id, $actor), true)];
                    case 'members':
                        $requireManage();
                        $members = is_array($this->body()['members'] ?? null) ? $this->body()['members'] : [];

                        return ['project' => $this->projectRow($svc->setMembers($id, $members, $actor), true)];
                    case 'milestones':
                        $requireManage();

                        return ['milestone' => $this->platform->milestones()->create($id, $this->body(), $actor)];
                    case 'tasks':
                        $requireManage();

                        return ['task' => $this->platform->projectTasks()->create($id, $this->body(), $actor)];
                    case 'apply-template':
                        $requireManage();
                        $body = $this->body();
                        $applied = $this->platform->projectTemplates()->applyToProject($id, (string) ($body['template_uuid'] ?? ''), $actor, ((string) ($body['start_date'] ?? '')) ?: null);
                        $svc->logEvent($id, 'template_applied', 'Applied template (v' . $applied['version'] . '): ' . $applied['milestones'] . ' milestones, ' . $applied['tasks'] . ' tasks', $actor);

                        return ['applied' => $applied, 'project' => $this->projectRow($svc->find($id) ?? [], true)];
                }
            }
            // Nested collections (GET): /projects/:id/milestones, /tasks, /board.
            if ($method === 'GET' && $action === 'milestones') {
                return ['items' => $this->platform->milestones()->forProject($id)];
            }
            if ($method === 'GET' && $action === 'tasks') {
                return ['items' => $this->platform->projectTasks()->forProject($id, ['status' => $this->query()['status'] ?? null, 'milestone_uuid' => $this->query()['milestone'] ?? null])];
            }
            if ($method === 'GET' && $action === 'onboarding') {
                return ['items' => $this->platform->onboarding()->forProject($id)];
            }
            if ($method === 'POST' && $action === 'onboarding') {
                $requireManage();
                $body = $this->body();
                try {
                    return ['instance' => $this->platform->onboarding()->createForProject($id, (string) ($body['template_uuid'] ?? ''), $body, $actor)];
                } catch (\Breakfast\Platform\Onboarding\OnboardingException $e) {
                    throw new ApiException($e->status, $e->getMessage(), 'onboarding');
                }
            }
            if ($method === 'GET' && $action === 'board') {
                $columns = [];
                foreach (\Breakfast\Platform\Projects\ProjectTasks::STATUSES as $col) {
                    $columns[$col] = [];
                }
                foreach ($this->platform->projectTasks()->forProject($id) as $t) {
                    $columns[(string) $t['status']][] = $t;
                }

                return ['columns' => $columns];
            }

            throw new ApiException(404, 'Unknown project endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Projects\ProjectException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'project');
        }
    }

    /**
     * Change requests + scope control (Phase 2B). Viewing follows CRM access;
     * creating/editing/sending require the 'manage' grant; applying an approved
     * change to the project is admin-only. Clients decide through the separate
     * tokened public /change/<token> route — never here.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function changeRequests(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewChangeRequests($user)) {
            throw new ApiException(403, 'You don’t have access to change requests.', 'forbidden');
        }
        $svc   = $this->platform->changeRequests();
        $actor = (string) $user->email();
        $id    = $seg[1] ?? '';
        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageChangeRequests($user)) {
                throw new ApiException(403, 'You can’t change requests.', 'forbidden');
            }
        };

        try {
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();
                    $body = $this->body();

                    return ['change_request' => $this->changeRequestRow($svc->create((string) ($body['project_uuid'] ?? ''), $body, $actor))];
                }
                $q = $this->query();

                return ['items' => array_map(fn (array $r): array => $this->changeRequestRow($r), $svc->list([
                    'project_uuid' => $q['project'] ?? null,
                    'status'       => $q['status'] ?? null,
                    'limit'        => $this->perPage(),
                ]))];
            }

            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $cr = $svc->find($id);
                if ($cr === null) {
                    throw new ApiException(404, 'Change request not found.', 'not_found');
                }

                return ['change_request' => $this->changeRequestRow($cr)];
            }
            if ($method === 'PATCH' && $action === '') {
                $requireManage();
                $body = $this->body();
                $rev  = array_key_exists('revision', $body) ? (int) $body['revision'] : null;

                return ['change_request' => $this->changeRequestRow($svc->update($id, $body, $actor, $rev))];
            }
            if ($method === 'POST') {
                $body = $this->body();
                switch ($action) {
                    case 'status':
                        $requireManage();

                        return ['change_request' => $this->changeRequestRow($svc->transition($id, (string) ($body['status'] ?? ''), $actor, (string) ($body['reason'] ?? '')))];
                    case 'send':
                        $requireManage();
                        $siteUrl = rtrim((string) $this->kirby->site()->url(), '/');
                        $result  = $svc->send($id, $siteUrl, $actor);

                        return ['change_request' => $this->changeRequestRow($result['change_request']), 'url' => $result['url']];
                    case 'decision':
                        // Staff recording a decision taken offline (e.g. by email).
                        $requireManage();

                        return ['change_request' => $this->changeRequestRow($svc->decide($id, (string) ($body['decision'] ?? ''), [
                            'name'    => (string) ($body['name'] ?? $actor),
                            'email'   => (string) ($body['email'] ?? ''),
                            'comment' => (string) ($body['comment'] ?? ''),
                            'wording' => 'Recorded by ' . $actor . ' on the client’s behalf.',
                        ], $actor))];
                    case 'apply':
                        if (!PanelGate::canApplyChangeRequests($user)) {
                            throw new ApiException(403, 'Only an administrator can apply a change to the project.', 'forbidden');
                        }

                        return ['result' => $svc->apply($id, is_array($body['options'] ?? null) ? $body['options'] : [], $actor), 'change_request' => $this->changeRequestRow($svc->find($id) ?? [])];
                }
            }

            throw new ApiException(404, 'Unknown change-request endpoint.', 'not_found');
        } catch (\Breakfast\Platform\ChangeRequests\ChangeRequestException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'change_request');
        }
    }

    /**
     * Time tracking (Phase 4 — Operations). Viewing follows CRM access; logging,
     * editing an unbilled entry, the live timer and marking billed require the
     * 'manage' grant. Billed entries are locked server-side. The timer is scoped
     * to the calling user.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function time(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewTime($user)) {
            throw new ApiException(403, 'You don’t have access to time tracking.', 'forbidden');
        }
        $svc   = $this->platform->time();
        $actor = (string) $user->email();
        $id    = $seg[1] ?? '';
        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageTime($user)) {
                throw new ApiException(403, 'You can’t log or change time.', 'forbidden');
            }
        };

        try {
            if ($id === 'start' && $method === 'POST') {
                $requireManage();
                $body = $this->body();

                return ['entry' => $svc->startTimer((string) ($body['project_uuid'] ?? ''), $body, $actor)];
            }
            if ($id === 'stop' && $method === 'POST') {
                $requireManage();

                return ['entry' => $svc->stopTimer($actor)];
            }
            if ($id === 'running' && $method === 'GET') {
                return ['entry' => $svc->runningTimer($actor)];
            }
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();
                    $body = $this->body();

                    return ['entry' => $svc->create((string) ($body['project_uuid'] ?? ''), $body, $actor)];
                }
                $q = $this->query();
                $project = (string) ($q['project'] ?? '');

                return [
                    'items' => $svc->list(['project_uuid' => $project ?: null, 'task_uuid' => $q['task'] ?? null, 'limit' => $this->perPage()]),
                    'rollup' => $project !== '' ? $svc->rollup($project) : null,
                    'running' => $svc->runningTimer($actor),
                ];
            }
            if ($method === 'PATCH') {
                $requireManage();
                $body = $this->body();

                return ['entry' => $svc->update($id, $body, $actor, array_key_exists('revision', $body) ? (int) $body['revision'] : null)];
            }
            if ($method === 'DELETE') {
                $requireManage();
                $svc->delete($id, $actor);

                return ['ok' => true];
            }

            throw new ApiException(404, 'Unknown time endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Time\TimeException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'time');
        }
    }

    /**
     * Client portal administration (Phase 3). Staff create a client identity,
     * grant/revoke per-project access, mint a sign-in link to send, and suspend
     * an account. Clients authenticate through the separate public portal flow.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function portal(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewPortalAdmin($user)) {
            throw new ApiException(403, 'You don’t have access to the client portal.', 'forbidden');
        }
        $svc   = $this->platform->portal();
        $actor = (string) $user->email();
        $id    = $seg[1] ?? '';
        $requireManage = function () use ($user): void {
            if (!PanelGate::canManagePortalAdmin($user)) {
                throw new ApiException(403, 'You can’t change portal access.', 'forbidden');
            }
        };

        try {
            if ($id === 'invite' && $method === 'POST') {
                $requireManage();
                $body = $this->body();
                $result = $svc->inviteToProject((string) ($body['project_uuid'] ?? ''), $body, rtrim((string) $this->kirby->site()->url(), '/'), $actor);

                return ['identity' => $result['identity'], 'url' => $result['url']];
            }
            // Portal messages: GET /portal/messages?project=X&identity=Y (staff view,
            // clears client-unread), POST /portal/messages/reply.
            if ($id === 'messages') {
                if (($seg[2] ?? '') === 'reply' && $method === 'POST') {
                    $requireManage();
                    $body = $this->body();

                    return ['message' => $svc->postStaffMessage((string) ($body['project_uuid'] ?? ''), (string) ($body['identity_uuid'] ?? ''), (string) ($body['body'] ?? ''), $actor)];
                }
                if ($method === 'GET') {
                    $q = $this->query();

                    return ['items' => $svc->messageThread((string) ($q['project'] ?? ''), (string) ($q['identity'] ?? ''), 'staff')];
                }
                throw new ApiException(404, 'Unknown portal messages endpoint.', 'not_found');
            }
            // Portal feedback: GET /portal/feedback?project=X, POST /portal/feedback/<uuid>/status
            if ($id === 'feedback') {
                $fbId = $seg[2] ?? '';
                if ($fbId === '' && $method === 'GET') {
                    return ['items' => $svc->feedbackForProject((string) ($this->query()['project'] ?? ''))];
                }
                if ($fbId !== '' && ($seg[3] ?? '') === 'status' && $method === 'POST') {
                    $requireManage();

                    return ['feedback' => $svc->setFeedbackStatus($fbId, (string) ($this->body()['status'] ?? ''), $actor)];
                }
                throw new ApiException(404, 'Unknown portal feedback endpoint.', 'not_found');
            }
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();

                    return ['identity' => $svc->createIdentity($this->body(), $actor)];
                }
                $q = $this->query();
                $items = $svc->listIdentities(['contact_uuid' => $q['contact'] ?? null]);
                // Optionally filter to those with access to a given project.
                if (!empty($q['project'])) {
                    $project = (string) $q['project'];
                    $items = array_values(array_filter($items, fn (array $i): bool => $svc->canAccessProject((string) $i['uuid'], $project)));
                }

                return ['items' => $items];
            }
            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $identity = $svc->findIdentity($id);
                if ($identity === null) {
                    throw new ApiException(404, 'Portal identity not found.', 'not_found');
                }

                return ['identity' => $identity];
            }
            if ($method === 'POST') {
                $body = $this->body();
                switch ($action) {
                    case 'grant':
                        $requireManage();

                        return ['identity' => $svc->grantAccess($id, (string) ($body['entity_type'] ?? 'project'), (string) ($body['entity_uuid'] ?? ''), (string) ($body['role'] ?? 'viewer'), $actor)];
                    case 'revoke':
                        $requireManage();
                        $svc->revokeAccess($id, (string) ($body['entity_type'] ?? 'project'), (string) ($body['entity_uuid'] ?? ''), $actor);

                        return ['ok' => true];
                    case 'status':
                        $requireManage();

                        return ['identity' => $svc->setStatus($id, (string) ($body['status'] ?? ''), $actor)];
                    case 'magic-link':
                        $requireManage();
                        $identity = $svc->findIdentity($id);
                        if ($identity === null) {
                            throw new ApiException(404, 'Portal identity not found.', 'not_found');
                        }
                        $result = $svc->requestLogin((string) $identity['email']);
                        $url = ($result['sent'] && $result['token'] !== null)
                            ? rtrim((string) $this->kirby->site()->url(), '/') . '/portal/verify/' . $result['token']
                            : '';

                        return ['url' => $url];
                }
            }

            throw new ApiException(404, 'Unknown portal endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Portal\PortalException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'portal');
        }
    }

    /**
     * Shape a change request for the admin UI: money in pounds, a staff download
     * URL when a document exists, and a client URL when sent.
     *
     * @param array<string,mixed> $cr
     * @return array<string,mixed>
     */
    private function changeRequestRow(array $cr): array
    {
        $pounds = static fn ($pence): float => (int) $pence / 100;
        $token  = (string) ($cr['public_token'] ?? '');
        $items  = is_array($cr['items'] ?? null) ? $cr['items'] : [];
        $row = [
            'uuid'        => (string) ($cr['uuid'] ?? ''),
            'number'      => (string) ($cr['number'] ?? ''),
            'title'       => (string) ($cr['title'] ?? ''),
            'project_uuid' => (string) ($cr['project_uuid'] ?? ''),
            'status'      => (string) ($cr['status'] ?? ''),
            'source'      => (string) ($cr['source'] ?? ''),
            'description' => (string) ($cr['description'] ?? ''),
            'reason'      => (string) ($cr['reason'] ?? ''),
            'scope_impact' => (string) ($cr['scope_impact'] ?? ''),
            'time_impact' => (string) ($cr['time_impact'] ?? ''),
            'target_date_impact' => (string) ($cr['target_date_impact'] ?? ''),
            'internal_notes' => (string) ($cr['internal_notes'] ?? ''),
            'currency'    => (string) ($cr['currency'] ?? 'GBP'),
            'discount'    => (int) ($cr['discount_bps'] ?? 0) / 100,
            'tax_rate'    => (int) ($cr['tax_bps'] ?? 0) / 100,
            'subtotal'    => $pounds($cr['subtotal'] ?? 0),
            'tax_total'   => $pounds($cr['tax_total'] ?? 0),
            'total'       => $pounds($cr['total'] ?? 0),
            'applied'     => (int) ($cr['applied'] ?? 0) === 1,
            'expiry_date' => (string) ($cr['expiry_date'] ?? ''),
            'revision'    => (int) ($cr['revision'] ?? 1),
            'created_at'  => (string) ($cr['created_at'] ?? ''),
            'sent_at'     => (string) ($cr['sent_at'] ?? ''),
            'decided_at'  => (string) ($cr['decided_at'] ?? ''),
            'document_status' => (string) ($cr['document_status'] ?? 'none'),
            'has_document' => (string) ($cr['document_key'] ?? '') !== '',
            'download_url' => (string) ($cr['document_key'] ?? '') !== '' ? '/breakfast-admin/change-requests/' . (string) ($cr['uuid'] ?? '') . '/download' : '',
            'public_url'  => $token !== '' ? rtrim((string) $this->kirby->site()->url(), '/') . '/change/' . $token : '',
            'items' => array_map(static fn (array $it): array => [
                'uuid'        => (string) ($it['uuid'] ?? ''),
                'kind'        => (string) ($it['kind'] ?? 'fixed'),
                'description' => (string) ($it['description'] ?? ''),
                'quantity'    => (int) ($it['quantity'] ?? 1000) / 1000,
                'unit_price'  => (int) ($it['unit_price'] ?? 0) / 100,
                'line_total'  => (int) ($it['line_total'] ?? 0) / 100,
                'estimate_hours' => (int) ($it['estimate_seconds'] ?? 0) / 3600,
            ], $items),
            'events' => array_map(static fn (array $ev): array => [
                'type' => (string) ($ev['type'] ?? ''), 'detail' => (string) ($ev['detail'] ?? ''),
                'actor' => (string) ($ev['actor'] ?? ''), 'created_at' => (string) ($ev['created_at'] ?? ''),
            ], is_array($cr['events'] ?? null) ? $cr['events'] : []),
            'acceptances' => array_map(static fn (array $a): array => [
                'decision' => (string) ($a['decision'] ?? ''), 'name' => (string) ($a['name'] ?? ''),
                'email' => (string) ($a['email'] ?? ''), 'approved_total' => (int) ($a['approved_total'] ?? 0) / 100,
                'created_at' => (string) ($a['created_at'] ?? ''),
            ], is_array($cr['acceptances'] ?? null) ? $cr['acceptances'] : []),
        ];

        return $row;
    }

    /**
     * Milestone item operations: GET/PATCH /milestones/:id and POST actions
     * (status, depend, undepend, delete). Reorder is POST /milestones/reorder.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function milestones(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewProjects($user)) {
            throw new ApiException(403, 'You don’t have access to projects.', 'forbidden');
        }
        $manage = PanelGate::canManageProjects($user);
        $svc    = $this->platform->milestones();
        $actor  = (string) $user->email();
        $id     = $seg[1] ?? '';
        try {
            if ($id === 'reorder' && $method === 'POST') {
                $this->requireProjectManage($manage);
                $body = $this->body();
                $svc->reorder((string) ($body['project_uuid'] ?? ''), is_array($body['order'] ?? null) ? $body['order'] : [], $actor);

                return ['ok' => true];
            }
            if ($id === '') {
                throw new ApiException(404, 'Unknown milestone endpoint.', 'not_found');
            }
            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $m = $svc->find($id);
                if ($m === null) {
                    throw new ApiException(404, 'Milestone not found.', 'not_found');
                }

                return ['milestone' => $m];
            }
            $this->requireProjectManage($manage);
            if ($method === 'PATCH' && $action === '') {
                $body = $this->body();

                return ['milestone' => $svc->update($id, $body, $actor, array_key_exists('revision', $body) ? (int) $body['revision'] : null)];
            }
            if ($method === 'POST') {
                switch ($action) {
                    case 'status':
                        return ['milestone' => $svc->setStatus($id, (string) ($this->body()['status'] ?? ''), $actor)];
                    case 'depend':
                        return ['milestone' => $svc->addDependency($id, (string) ($this->body()['blocked_by'] ?? ''), $actor)];
                    case 'undepend':
                        $svc->removeDependency($id, (string) ($this->body()['blocked_by'] ?? ''));

                        return ['ok' => true];
                    case 'delete':
                        return $svc->delete($id);
                }
            }

            throw new ApiException(404, 'Unknown milestone endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Projects\ProjectException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'project');
        }
    }

    /**
     * Delivery task item operations: PATCH /project-tasks/:id and POST actions
     * (move, depend, undepend, checklist, checklist-toggle, archive, restore).
     * Bulk is POST /project-tasks/bulk; reorder is POST /project-tasks/reorder.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function projectTasks(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewProjects($user)) {
            throw new ApiException(403, 'You don’t have access to projects.', 'forbidden');
        }
        $manage = PanelGate::canManageProjects($user);
        $svc    = $this->platform->projectTasks();
        $actor  = (string) $user->email();
        $id     = $seg[1] ?? '';
        try {
            if ($id === 'bulk' && $method === 'POST') {
                $this->requireProjectManage($manage);
                $body = $this->body();

                return $svc->bulk(is_array($body['ids'] ?? null) ? $body['ids'] : [], is_array($body['changes'] ?? null) ? $body['changes'] : [], $actor);
            }
            if ($id === 'reorder' && $method === 'POST') {
                $this->requireProjectManage($manage);
                $body = $this->body();
                $svc->reorder((string) ($body['project_uuid'] ?? ''), is_array($body['order'] ?? null) ? $body['order'] : [], $actor);

                return ['ok' => true];
            }
            if ($id === 'checklist-toggle' && $method === 'POST') {
                $this->requireProjectManage($manage);
                $body = $this->body();

                return ['task' => $svc->toggleChecklistItem((string) ($body['item'] ?? ''), !empty($body['done']))];
            }
            if ($id === '') {
                throw new ApiException(404, 'Unknown task endpoint.', 'not_found');
            }
            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $t = $svc->find($id);
                if ($t === null) {
                    throw new ApiException(404, 'Task not found.', 'not_found');
                }

                return ['task' => $t];
            }
            $this->requireProjectManage($manage);
            if ($method === 'PATCH' && $action === '') {
                $body = $this->body();

                return ['task' => $svc->update($id, $body, $actor, array_key_exists('revision', $body) ? (int) $body['revision'] : null)];
            }
            if ($method === 'POST') {
                $body = $this->body();
                switch ($action) {
                    case 'move':
                        return ['task' => $svc->move($id, (string) ($body['status'] ?? ''), $actor, array_key_exists('revision', $body) ? (int) $body['revision'] : null)];
                    case 'depend':
                        return ['task' => $svc->addDependency($id, (string) ($body['blocked_by'] ?? ''), $actor)];
                    case 'undepend':
                        $svc->removeDependency($id, (string) ($body['blocked_by'] ?? ''));

                        return ['ok' => true];
                    case 'checklist':
                        return ['task' => $svc->addChecklistItem($id, (string) ($body['label'] ?? ''), $actor)];
                    case 'archive':
                        $svc->archive($id);

                        return ['ok' => true];
                    case 'restore':
                        $svc->restore($id);

                        return ['ok' => true];
                }
            }

            throw new ApiException(404, 'Unknown task endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Projects\ProjectException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'project');
        }
    }

    private function requireProjectManage(bool $manage): void
    {
        if (!$manage) {
            throw new ApiException(403, 'You can’t change projects.', 'forbidden');
        }
    }

    /**
     * Secure credential vault. Viewing returns MASKED metadata only; create/edit
     * secrets/rotate are admin-only; reveal and copy additionally require a
     * recent step-up re-authentication (POST /vault/reauth with the current
     * password). Never exposed to Hermes or the client portal.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function vault(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewVaultMetadata($user)) {
            throw new ApiException(403, 'You don’t have access to the vault.', 'forbidden');
        }
        $svc   = $this->platform->vault();
        $actor = (string) $user->email();
        $id    = $seg[1] ?? '';
        $requireManage = function () use ($user): void {
            if (!PanelGate::canManageVault($user)) {
                throw new ApiException(403, 'Only an administrator can change vault items.', 'forbidden');
            }
        };
        $requireReveal = function () use ($user): void {
            if (!PanelGate::canRevealVault($user)) {
                throw new ApiException(403, 'You can’t reveal secrets.', 'forbidden');
            }
        };
        try {
            // Step-up re-authentication: verify the current password, then grant
            // a short-lived vault reveal session for this user.
            if ($id === 'reauth' && $method === 'POST') {
                $requireReveal();
                $password = (string) ($this->body()['password'] ?? '');
                $ok = false;
                try {
                    $ok = $user->validatePassword($password);
                } catch (\Throwable) {
                    $ok = false;
                }
                if (!$ok) {
                    throw new ApiException(401, 'That password is incorrect.', 'reauth_failed');
                }
                $svc->grantReauth($actor);

                return ['ok' => true, 'expires_in' => 300];
            }
            if ($id === 'rotate-keys' && $method === 'POST') {
                $requireManage();

                return $svc->rotateKeys($actor);
            }
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();

                    return ['item' => $svc->create($this->body(), $actor)];
                }
                $q = $this->query();

                return ['items' => $svc->list(['company_uuid' => $q['company'] ?? null, 'project_uuid' => $q['project'] ?? null])];
            }
            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $item = $svc->find($id);
                if ($item === null) {
                    throw new ApiException(404, 'Vault item not found.', 'not_found');
                }

                return ['item' => $item];
            }
            if ($method === 'GET' && $action === 'access-log') {
                $requireReveal();

                return ['items' => $svc->accessLog($id)];
            }
            if ($method === 'PATCH' && $action === '') {
                $requireManage();
                $body = $this->body();

                return ['item' => $svc->updateMetadata($id, $body, $actor, array_key_exists('revision', $body) ? (int) $body['revision'] : null)];
            }
            if ($method === 'POST') {
                $body = $this->body();
                switch ($action) {
                    case 'secret':
                        $requireManage();

                        return ['item' => $svc->setSecret($id, (string) ($body['fkey'] ?? ''), (string) ($body['label'] ?? ''), (string) ($body['value'] ?? ''), $actor)];
                    case 'reveal':
                        $requireReveal();

                        return ['value' => $svc->reveal($id, (string) ($body['fkey'] ?? ''), $actor)];
                    case 'copy':
                        $requireReveal();

                        return ['value' => $svc->copy($id, (string) ($body['fkey'] ?? ''), $actor)];
                    case 'archive':
                        $requireManage();

                        return ['item' => $svc->archive($id, $actor)];
                    case 'restore':
                        $requireManage();

                        return ['item' => $svc->restore($id, $actor)];
                }
            }

            throw new ApiException(404, 'Unknown vault endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Vault\VaultException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'vault');
        }
    }

    /**
     * Client file library. Upload/replace are multipart (field "file"); metadata
     * comes from the multipart body. Downloads are a separate streaming route.
     * Viewing/uploading follow the CRM grant; permanent delete is admin-only.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function files(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewFiles($user)) {
            throw new ApiException(403, 'You don’t have access to files.', 'forbidden');
        }
        $manage = PanelGate::canManageFiles($user);
        $svc    = $this->platform->files();
        $actor  = (string) $user->email();
        $id     = $seg[1] ?? '';
        $requireManage = function () use ($manage): void {
            if (!$manage) {
                throw new ApiException(403, 'You can’t change files.', 'forbidden');
            }
        };
        $mbody = fn (string $k, string $d = ''): string => (string) ($this->kirby->request()->body()->get($k) ?? $d);
        try {
            if ($id === '') {
                if ($method === 'POST') {
                    $requireManage();
                    $f = $this->uploadedFile();
                    $links = [];
                    if ($mbody('entity_type') !== '' && $mbody('entity_uuid') !== '') {
                        $links[] = ['entity_type' => $mbody('entity_type'), 'entity_uuid' => $mbody('entity_uuid')];
                    }
                    $meta = [
                        'display_name' => $mbody('display_name', (string) ($f['name'] ?? 'file')), 'category' => $mbody('category', 'general'),
                        'project_uuid' => $mbody('project_uuid'), 'company_uuid' => $mbody('company_uuid'),
                        'client_visible' => $mbody('client_visible') === '1', 'links' => $links,
                    ];
                    $file = $svc->upload((string) ($f['tmp_name'] ?? ''), (string) ($f['name'] ?? 'file'), (string) ($f['type'] ?? ''), $meta, $actor);
                    if ($file['project_uuid'] ?? '') {
                        $this->platform->projects()->logEvent((string) $file['project_uuid'], 'file_uploaded', 'File uploaded: ' . $file['display_name'], $actor);
                    }

                    return ['file' => $this->fileRow($file, true)];
                }
                $q = $this->query();

                return ['items' => array_map(fn (array $r): array => $this->fileRow($r, false), $svc->list([
                    'project_uuid' => $q['project'] ?? null, 'company_uuid' => $q['company'] ?? null,
                    'category' => $q['category'] ?? null, 'include_archived' => ($q['archived'] ?? '') === '1', 'limit' => $this->perPage(),
                ]))];
            }
            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $f = $svc->find($id);
                if ($f === null) {
                    throw new ApiException(404, 'File not found.', 'not_found');
                }

                return ['file' => $this->fileRow($f, true)];
            }
            $requireManage();
            if ($method === 'POST') {
                switch ($action) {
                    case 'replace':
                        $f = $this->uploadedFile();

                        return ['file' => $this->fileRow($svc->replace($id, (string) ($f['tmp_name'] ?? ''), (string) ($f['name'] ?? 'file'), (string) ($f['type'] ?? ''), $mbody('change_note'), $actor), true)];
                    case 'restore-version':
                        return ['file' => $this->fileRow($svc->restoreVersion($id, (int) ($this->body()['version'] ?? 0), $actor), true)];
                    case 'link':
                        $body = $this->body();

                        return ['file' => $this->fileRow($svc->link($id, (string) ($body['entity_type'] ?? ''), (string) ($body['entity_uuid'] ?? ''), $actor), true)];
                    case 'unlink':
                        $body = $this->body();
                        $svc->unlink($id, (string) ($body['entity_type'] ?? ''), (string) ($body['entity_uuid'] ?? ''));

                        return ['file' => $this->fileRow($svc->find($id) ?? [], true)];
                    case 'archive':
                        return ['file' => $this->fileRow($svc->archive($id, $actor), true)];
                    case 'restore':
                        return ['file' => $this->fileRow($svc->restore($id, $actor), true)];
                    case 'delete':
                        if (!PanelGate::canDeleteFiles($user)) {
                            throw new ApiException(403, 'Only an administrator can permanently delete a file.', 'forbidden');
                        }

                        return $svc->delete($id, (string) ($this->body()['reason'] ?? ''), $actor);
                }
            }

            throw new ApiException(404, 'Unknown file endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Files\FileException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'file');
        }
    }

    /**
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private function fileRow(array $f, bool $detail): array
    {
        $cur = is_array($f['current'] ?? null) ? $f['current'] : [];
        $row = [
            'id'             => (string) ($f['uuid'] ?? ''),
            'display_name'   => (string) ($f['display_name'] ?? ''),
            'category'       => (string) ($f['category'] ?? 'general'),
            'current_version' => (int) ($f['current_version'] ?? 1),
            'immutable'      => (int) ($f['immutable'] ?? 0) === 1,
            'client_visible' => (int) ($f['client_visible'] ?? 0) === 1,
            'archived'       => (int) ($f['archived'] ?? 0) === 1,
            'project_uuid'   => (string) ($f['project_uuid'] ?? ''),
            'reference_count' => (int) ($f['reference_count'] ?? 0),
            'extension'      => (string) ($cur['extension'] ?? ''),
            'byte_size'      => (int) ($cur['byte_size'] ?? 0),
            'thumb_state'    => (string) ($cur['thumb_state'] ?? 'none'),
            'tags'           => is_array($f['tags'] ?? null) ? $f['tags'] : [],
            'updated_at'     => (string) ($f['updated_at'] ?? ''),
        ];
        if (!$detail) {
            return $row;
        }
        $row['description'] = (string) ($f['description'] ?? '');
        $row['source'] = (string) ($f['source'] ?? '');
        $row['versions'] = array_map(static fn (array $v): array => [
            'version' => (int) ($v['version'] ?? 0), 'original_name' => (string) ($v['original_name'] ?? ''), 'byte_size' => (int) ($v['byte_size'] ?? 0),
            'change_note' => (string) ($v['change_note'] ?? ''), 'uploader' => (string) ($v['uploader'] ?? ''), 'created_at' => (string) ($v['created_at'] ?? ''),
        ], is_array($f['versions'] ?? null) ? $f['versions'] : []);
        $row['links'] = array_map(static fn (array $l): array => [
            'entity_type' => (string) ($l['entity_type'] ?? ''), 'entity_uuid' => (string) ($l['entity_uuid'] ?? ''),
        ], is_array($f['links'] ?? null) ? $f['links'] : []);

        return $row;
    }

    /**
     * Onboarding instances. Item-level ops (invite, review, decide mapping,
     * complete, withdraw). Staff only; the client fills via a tokened route.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function onboarding(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewOnboarding($user)) {
            throw new ApiException(403, 'You don’t have access to onboarding.', 'forbidden');
        }
        $manage = PanelGate::canManageOnboarding($user);
        $svc    = $this->platform->onboarding();
        $actor  = (string) $user->email();
        $id     = $seg[1] ?? '';
        $requireManage = function () use ($manage): void {
            if (!$manage) {
                throw new ApiException(403, 'You can’t change onboarding.', 'forbidden');
            }
        };
        try {
            if ($id === 'mapping' && ($seg[2] ?? '') === 'decide' && $method === 'POST') {
                $requireManage();
                $body = $this->body();

                return ['instance' => $svc->decideMapping((string) ($body['review'] ?? ''), (string) ($body['decision'] ?? ''), $actor)];
            }
            if ($id === '') {
                throw new ApiException(404, 'Unknown onboarding endpoint.', 'not_found');
            }
            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $i = $svc->find($id);
                if ($i === null) {
                    throw new ApiException(404, 'Onboarding not found.', 'not_found');
                }

                return ['instance' => $this->onboardingRow($i)];
            }
            $requireManage();
            if ($method === 'POST') {
                $body = $this->body();
                switch ($action) {
                    case 'invite':
                        $result = $svc->invite($id, (string) ($body['email'] ?? ''), (int) ($body['expires_days'] ?? 14), $actor);
                        $token = $result['token'];
                        $url = rtrim((string) $this->kirby->site()->url(), '/') . '/onboarding/' . $token;
                        // Email the client the link (link only; never the token in the record).
                        $to = (string) ($body['email'] ?? '');
                        if (filter_var($to, FILTER_VALIDATE_EMAIL) !== false) {
                            $this->platform->crmMail()->send($to, 'Your project onboarding', "Hi,\n\nPlease complete your onboarding here:\n{$url}\n\nThank you.", [], $actor);
                        }

                        return ['instance' => $this->onboardingRow($result['instance']), 'url' => $url];
                    case 'review':
                        return ['instance' => $this->onboardingRow($svc->startReview($id, $actor))];
                    case 'clarify':
                        return ['instance' => $this->onboardingRow($svc->requestClarification($id, (string) ($body['note'] ?? ''), $actor))];
                    case 'complete':
                        return ['instance' => $this->onboardingRow($svc->complete($id, $actor))];
                    case 'withdraw':
                        return ['instance' => $this->onboardingRow($svc->withdraw($id, $actor))];
                }
            }

            throw new ApiException(404, 'Unknown onboarding endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Onboarding\OnboardingException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'onboarding');
        }
    }

    /**
     * Onboarding templates: GET list, GET /:id, POST create, POST /:id/version,
     * POST /:id/publish. Also serves the frozen structure for a project's
     * onboarding via GET /onboarding-templates/:id/structure?version=N.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function onboardingTemplatesApi(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewOnboarding($user)) {
            throw new ApiException(403, 'You don’t have access to onboarding.', 'forbidden');
        }
        $manage = PanelGate::canManageOnboarding($user);
        $svc    = $this->platform->onboardingTemplates();
        $actor  = (string) $user->email();
        $id     = $seg[1] ?? '';
        try {
            if ($id === '') {
                if ($method === 'POST') {
                    if (!$manage) {
                        throw new ApiException(403, 'You can’t manage onboarding templates.', 'forbidden');
                    }

                    return ['template' => $svc->create($this->body(), $actor)];
                }

                return ['items' => $svc->list()];
            }
            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $t = $svc->find($id);
                if ($t === null) {
                    throw new ApiException(404, 'Template not found.', 'not_found');
                }

                return ['template' => $t];
            }
            if ($method === 'GET' && $action === 'structure') {
                $v = (int) ($this->query()['version'] ?? 0);
                $structure = $v > 0 ? $svc->versionStructure($id, $v) : $svc->publishedStructure($id);

                return ['structure' => $structure];
            }
            if (!$manage) {
                throw new ApiException(403, 'You can’t manage onboarding templates.', 'forbidden');
            }
            if ($method === 'POST' && $action === 'version') {
                return ['template' => $svc->newDraftVersion($id, $actor)];
            }
            if ($method === 'POST' && $action === 'publish') {
                return ['template' => $svc->publish($id, (int) ($this->body()['version'] ?? 0), $actor)];
            }

            throw new ApiException(404, 'Unknown template endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Onboarding\OnboardingException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'onboarding');
        }
    }

    /**
     * Staff-facing onboarding view — includes answers + reviews but never the
     * raw token.
     *
     * @param array<string,mixed> $i
     * @return array<string,mixed>
     */
    private function onboardingRow(array $i): array
    {
        unset($i['token_hash']);

        return $i;
    }

    /**
     * Project templates: GET list, GET /:id, POST create, POST /:id/version
     * (new draft), POST /:id/publish. Managing requires the projects grant.
     *
     * @param list<string> $seg
     * @return array<string,mixed>
     */
    private function projectTemplatesApi(string $method, array $seg, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canViewProjects($user)) {
            throw new ApiException(403, 'You don’t have access to projects.', 'forbidden');
        }
        $manage = PanelGate::canManageProjects($user);
        $svc    = $this->platform->projectTemplates();
        $actor  = (string) $user->email();
        $id     = $seg[1] ?? '';
        try {
            if ($id === '') {
                if ($method === 'POST') {
                    $this->requireProjectManage($manage);

                    return ['template' => $svc->create($this->body(), $actor)];
                }

                return ['items' => $svc->list()];
            }
            $action = $seg[2] ?? '';
            if ($method === 'GET' && $action === '') {
                $t = $svc->find($id);
                if ($t === null) {
                    throw new ApiException(404, 'Template not found.', 'not_found');
                }

                return ['template' => $t];
            }
            $this->requireProjectManage($manage);
            if ($method === 'POST' && $action === 'version') {
                return ['template' => $svc->newDraftVersion($id, $actor)];
            }
            if ($method === 'POST' && $action === 'publish') {
                return ['template' => $svc->publish($id, (int) ($this->body()['version'] ?? 0), $actor)];
            }

            throw new ApiException(404, 'Unknown template endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Projects\ProjectException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'project');
        }
    }

    /**
     * Create a project manually or from a commercial record, and record the
     * creation on the CRM timeline + audit.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function createProject(array $body, string $actor): array
    {
        $conv = $this->platform->projectConversion();
        $options = ['overrides' => is_array($body['overrides'] ?? null) ? $body['overrides'] : []];
        foreach (['require_contract', 'require_deposit', 'require_won'] as $flag) {
            if (array_key_exists($flag, $body)) {
                $options[$flag] = (bool) $body[$flag];
            }
        }
        try {
            if (!empty($body['proposal_uuid'])) {
                $project = $conv->fromProposal((string) $body['proposal_uuid'], $options, $actor);
            } elseif (!empty($body['contract_uuid'])) {
                $project = $conv->fromContract((string) $body['contract_uuid'], $options, $actor);
            } elseif (!empty($body['opportunity_uuid'])) {
                $project = $conv->fromOpportunity((string) $body['opportunity_uuid'], $options, $actor);
            } else {
                $project = $this->platform->projects()->create($body, $actor);
            }
        } catch (\Breakfast\Platform\Projects\ProjectException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'project');
        }

        $contact = (string) ($project['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, 'project.created', 'Project ' . (string) ($project['number'] ?? '') . ' created', 'user', $actor, ['project' => $project['uuid'] ?? '']);
        }
        $this->platform->audit()->event('project.created', 'project', (string) ($project['uuid'] ?? ''), $actor, ['number' => $project['number'] ?? '']);

        return $project;
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function projectRow(array $p, bool $detail): array
    {
        $row = [
            'id'             => (string) ($p['uuid'] ?? ''),
            'number'         => (string) ($p['number'] ?? ''),
            'name'           => (string) ($p['name'] ?? ''),
            'status'         => (string) ($p['status'] ?? 'draft'),
            'priority'       => (string) ($p['priority'] ?? 'normal'),
            'health'         => (string) ($p['health'] ?? 'on_track'),
            'company_uuid'   => (string) ($p['company_uuid'] ?? ''),
            'contact_uuid'   => (string) ($p['contact_uuid'] ?? ''),
            'owner'          => (string) ($p['owner'] ?? ''),
            'start_date'     => (string) ($p['start_date'] ?? ''),
            'target_date'    => (string) ($p['target_date'] ?? ''),
            'quoted_value'   => (int) ($p['quoted_value'] ?? 0) / 100,
            'invoiced_value' => (int) ($p['invoiced_value'] ?? 0) / 100,
            'paid_value'     => (int) ($p['paid_value'] ?? 0) / 100,
            'currency'       => (string) ($p['currency'] ?? 'GBP'),
            'archived'       => (int) ($p['archived'] ?? 0) === 1,
            'revision'       => (int) ($p['revision'] ?? 1),
            'created_at'     => (string) ($p['created_at'] ?? ''),
        ];
        if (!$detail) {
            return $row;
        }
        foreach (['project_type', 'service_category', 'description', 'internal_summary', 'client_summary', 'scope', 'exclusions', 'blocked_reason', 'cancel_reason', 'completed_at', 'proposal_uuid', 'contract_uuid', 'opportunity_uuid', 'template_uuid'] as $f) {
            $row[$f] = (string) ($p[$f] ?? '');
        }
        $row['tags'] = is_array($p['tags'] ?? null) ? $p['tags'] : [];
        $row['approved_variations'] = (int) ($p['approved_variations'] ?? 0) / 100;
        $row['estimated_cost'] = (int) ($p['estimated_cost'] ?? 0) / 100;
        $row['awaiting_seconds'] = (int) ($p['awaiting_seconds_live'] ?? $p['awaiting_seconds'] ?? 0);
        $row['progress_percent'] = (int) ($p['progress_percent'] ?? 0);
        $row['tasks_total'] = (int) ($p['tasks_total'] ?? 0);
        $row['tasks_completed'] = (int) ($p['tasks_completed'] ?? 0);
        $row['milestones_total'] = (int) ($p['milestones_total'] ?? 0);
        $row['milestones_done'] = (int) ($p['milestones_done'] ?? 0);
        $row['members'] = array_map(static fn (array $m): array => [
            'user_email' => (string) ($m['user_email'] ?? ''), 'role' => (string) ($m['role'] ?? 'member'),
        ], is_array($p['members'] ?? null) ? $p['members'] : []);
        $row['events'] = array_map(static fn (array $e): array => [
            'type' => (string) ($e['type'] ?? ''), 'detail' => (string) ($e['detail'] ?? ''), 'actor' => (string) ($e['actor'] ?? ''), 'created_at' => (string) ($e['created_at'] ?? ''),
        ], is_array($p['events'] ?? null) ? $p['events'] : []);

        return $row;
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function contractRow(array $c, bool $detail): array
    {
        $row = [
            'id'         => (string) ($c['uuid'] ?? ''),
            'number'     => (string) ($c['number'] ?? ''),
            'status'     => (string) ($c['status'] ?? 'draft'),
            'title'      => (string) ($c['title'] ?? ''),
            'client'     => (string) ($c['client_name'] ?? ''),
            'value'      => (int) ($c['contract_value'] ?? 0) / 100,
            'currency'   => (string) ($c['currency'] ?? 'GBP'),
            'created_at' => (string) ($c['created_at'] ?? ''),
            'sent_at'    => (string) ($c['sent_at'] ?? ''),
            'completed_at' => (string) ($c['completed_at'] ?? ''),
            'unsigned_ready' => (string) ($c['unsigned_key'] ?? '') !== '',
            'signed_ready'   => (string) ($c['signed_key'] ?? '') !== '',
            'public_url' => (string) ($c['public_token'] ?? '') !== '' ? rtrim((string) $this->kirby->site()->url(), '/') . '/contract/' . (string) $c['public_token'] : '',
        ];
        if (!$detail) {
            return $row;
        }
        foreach (['template_key', 'contact_uuid', 'company_uuid', 'opportunity_uuid', 'proposal_uuid', 'client_email', 'effective_date', 'expiry_date', 'governing_law', 'signature_wording', 'internal_notes', 'owner'] as $f) {
            $row[$f] = (string) ($c[$f] ?? '');
        }
        $row['deposit_amount'] = (int) ($c['deposit_amount'] ?? 0) / 100;
        $row['revision_limit'] = (int) ($c['revision_limit'] ?? 0);
        $row['sections'] = is_array($c['sections_list'] ?? null) ? $c['sections_list'] : [];
        $row['parties'] = array_map(static fn (array $p): array => [
            'id' => (string) ($p['uuid'] ?? ''), 'role' => (string) ($p['role'] ?? ''), 'name' => (string) ($p['name'] ?? ''),
            'email' => (string) ($p['email'] ?? ''), 'order' => (int) ($p['signing_order'] ?? 1),
            'required' => (int) ($p['required'] ?? 1) === 1, 'status' => (string) ($p['status'] ?? 'pending'), 'signed_at' => (string) ($p['signed_at'] ?? ''),
        ], is_array($c['parties'] ?? null) ? $c['parties'] : []);
        $row['events'] = array_map(static fn (array $e): array => [
            'type' => (string) ($e['type'] ?? ''), 'detail' => (string) ($e['detail'] ?? ''), 'actor' => (string) ($e['actor'] ?? ''), 'created_at' => (string) ($e['created_at'] ?? ''),
        ], is_array($c['events'] ?? null) ? $c['events'] : []);
        $row['signatures_count'] = is_array($c['signatures'] ?? null) ? count($c['signatures']) : 0;

        return $row;
    }

    /**
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private function signatureRow(array $s): array
    {
        return [
            'name' => (string) ($s['signer_name'] ?? ''), 'email' => (string) ($s['signer_email'] ?? ''),
            'role' => (string) ($s['signer_role'] ?? ''), 'method' => (string) ($s['method'] ?? 'typed'),
            'wording' => (string) ($s['wording'] ?? ''), 'ip_hash' => (string) ($s['ip_hash'] ?? ''),
            'user_agent' => (string) ($s['user_agent'] ?? ''), 'document_hash' => (string) ($s['document_hash'] ?? ''),
            'snapshot_version' => (int) ($s['snapshot_version'] ?? 1), 'signed_at' => (string) ($s['signed_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function documentRow(array $d): array
    {
        return [
            'id'         => (string) ($d['uuid'] ?? ''),
            'version'    => (int) ($d['version'] ?? 1),
            'kind'       => (string) ($d['kind'] ?? 'issued'),
            'filename'   => (string) ($d['filename'] ?? ''),
            'byte_size'  => (int) ($d['byte_size'] ?? 0),
            'sha256'     => (string) ($d['sha256'] ?? ''),
            'renderer'   => (string) ($d['renderer'] ?? '') . ' ' . (string) ($d['renderer_version'] ?? ''),
            'state'      => (string) ($d['state'] ?? ''),
            'created_at' => (string) ($d['created_at'] ?? ''),
        ];
    }

    /**
     * Email the client a link to the signed invoice view and mark it sent.
     *
     * @return array<string,mixed>
     */
    private function sendInvoice(string $id, \Kirby\Cms\User $user): array
    {
        if (!PanelGate::canSendEmail($user)) {
            throw new ApiException(403, 'You don’t have permission to send email.', 'forbidden');
        }
        $svc = $this->platform->invoices();
        $inv = $svc->find($id);
        if ($inv === null) {
            throw new ApiException(404, 'Invoice not found.', 'not_found');
        }
        if (($inv['status'] ?? 'draft') === 'draft' || ($inv['public_token'] ?? '') === '') {
            throw new ApiException(409, 'Issue the invoice before sending it.', 'invoice');
        }
        // The genuine PDF must exist before we email it (document_pending gate):
        // sending is blocked until generation has succeeded.
        if ((string) ($inv['document_status'] ?? '') !== 'generated') {
            throw new ApiException(409, 'The invoice PDF is still being generated (or failed). It can’t be emailed until the document is ready.', 'document_pending');
        }
        $to = trim((string) ($this->body()['to'] ?? $inv['bill_to_email'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Enter a valid client email address.', 'invalid', ['to' => 'Required.']);
        }

        $url    = rtrim((string) $this->kirby->site()->url(), '/') . '/invoice/' . (string) $inv['public_token'];
        $number = (string) $inv['number'];
        $seller = (string) ($inv['seller_name'] ?? '') ?: 'Breakfast';
        $subject = 'Invoice ' . $number . ' from ' . $seller;
        $body = "Hi,\n\nYour invoice {$number} is attached as a PDF. You can also view it online here:\n{$url}\n\nThank you,\n{$seller}";

        // Attach the immutable issued PDF by REFERENCE — the queue never holds
        // the bytes; the worker resolves + base64-encodes it at send time.
        $result = $this->platform->crmMail()->send($to, $subject, $body, [], (string) $user->email(), ['invoice:' . $id]);
        if (($result['ok'] ?? false) !== true) {
            $err = (string) ($result['error'] ?? 'send_failed');
            throw new ApiException(422, $err === 'recipient_suppressed' ? 'That recipient can’t be emailed.' : 'The invoice email couldn’t be queued.', $err);
        }

        $svc->markSent($id, (string) $user->email(), 'Emailed to ' . $to);

        return ['status' => 'queued', 'invoice' => $this->invoiceRow($svc->find($id) ?? [], true)];
    }

    /**
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private function settingsRow(array $s): array
    {
        return [
            'company_legal_name' => (string) ($s['company_legal_name'] ?? ''),
            'company_address'    => (string) ($s['company_address'] ?? ''),
            'company_email'      => (string) ($s['company_email'] ?? ''),
            'payment_details'    => (string) ($s['payment_details'] ?? ''),
            'vat_registered'     => (bool) ($s['vat_registered'] ?? false),
            'vat_number'         => (string) ($s['vat_number'] ?? ''),
            'default_vat_rate'   => (int) ($s['default_vat_rate'] ?? 0) / 100,
            'currency'           => (string) ($s['currency'] ?? 'GBP'),
            'invoice_prefix'     => (string) ($s['invoice_prefix'] ?? 'INV'),
            'default_terms_days' => (int) ($s['default_terms_days'] ?? 14),
            'default_notes'      => (string) ($s['default_notes'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function invoiceRow(array $r, bool $detail): array
    {
        $row = [
            'id'          => (string) ($r['uuid'] ?? ''),
            'number'      => (string) ($r['number'] ?? ''),
            'status'      => (string) ($r['status'] ?? 'draft'),
            'client'      => (string) ($r['bill_to_name'] ?? ''),
            'project'     => (string) ($r['project'] ?? ''),
            'currency'    => (string) ($r['currency'] ?? 'GBP'),
            'total'       => (int) ($r['total'] ?? 0) / 100,
            'amount_due'  => (int) ($r['amount_due'] ?? 0) / 100,
            'issue_date'  => (string) ($r['issue_date'] ?? ''),
            'due_date'    => (string) ($r['due_date'] ?? ''),
            'overdue'     => (bool) ($r['overdue'] ?? false),
            'created_at'  => (string) ($r['created_at'] ?? ''),
        ];
        if ($detail) {
            $row['contact_uuid']    = (string) ($r['contact_uuid'] ?? '');
            $row['company_uuid']    = (string) ($r['company_uuid'] ?? '');
            $row['bill_to_email']   = (string) ($r['bill_to_email'] ?? '');
            $row['bill_to_address'] = (string) ($r['bill_to_address'] ?? '');
            $row['notes']           = (string) ($r['notes'] ?? '');
            $row['terms']           = (string) ($r['terms'] ?? '');
            $row['subtotal']        = (int) ($r['subtotal'] ?? 0) / 100;
            $row['tax_total']       = (int) ($r['tax_total'] ?? 0) / 100;
            $row['amount_paid']     = (int) ($r['amount_paid'] ?? 0) / 100;
            $row['seller_name']     = (string) ($r['seller_name'] ?? '');
            $row['payment_details'] = (string) ($r['payment_details'] ?? '');
            $row['document_status'] = (string) ($r['document_status'] ?? 'none');
            $row['pdf_ready']       = ((string) ($r['document_status'] ?? '')) === 'generated';
            $token = (string) ($r['public_token'] ?? '');
            $row['public_url']      = $token !== '' ? rtrim((string) $this->kirby->site()->url(), '/') . '/invoice/' . $token : '';
            $row['online_payments_enabled'] = $this->platform->stripeSettings()->enabled();
            $row['items'] = array_map(static fn (array $i): array => [
                'description' => (string) ($i['description'] ?? ''),
                'quantity'    => (int) ($i['quantity'] ?? 0) / 1000,
                'unit_price'  => (int) ($i['unit_price'] ?? 0) / 100,
                'tax_rate'    => (int) ($i['tax_rate'] ?? 0) / 100,
                'discount'    => (int) ($i['discount'] ?? 0) / 100,
                'line_total'  => (int) ($i['line_total'] ?? 0) / 100,
            ], is_array($r['items'] ?? null) ? $r['items'] : []);
            $row['payments'] = array_map(static fn (array $p): array => [
                'amount'    => (int) ($p['amount'] ?? 0) / 100,
                'paid_on'   => (string) ($p['paid_on'] ?? ''),
                'method'    => (string) ($p['method'] ?? ''),
                'reference' => (string) ($p['reference'] ?? ''),
            ], is_array($r['payments'] ?? null) ? $r['payments'] : []);
            $row['events'] = array_map(static fn (array $e): array => [
                'type'   => (string) ($e['type'] ?? ''),
                'detail' => (string) ($e['detail'] ?? ''),
                'at'     => (string) ($e['created_at'] ?? ''),
            ], is_array($r['events'] ?? null) ? $r['events'] : []);
        }

        return $row;
    }

    /**
     * Global search across CRM entities. Guarded by admin access (the route()
     * gate already enforces authentication); results carry the in-app route.
     *
     * @return array<string,mixed>
     */
    private function search(): array
    {
        $q       = (string) ($this->query()['q'] ?? '');
        $results = (new \Breakfast\Platform\Crm\SearchService($this->platform->db()))->search($q);

        return ['items' => $results, 'total' => count($results), 'query' => $q];
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
        $ws   = (new \Breakfast\Platform\Crm\ClientWorkspace($this->platform))->forContact($c);

        return [
            'contact'    => $this->contactRow($c),
            'timeline'   => array_map([$this, 'activityRow'], is_array($ws['timeline']) ? $ws['timeline'] : []),
            'workspace'  => [
                'company'       => is_array($ws['company'] ?? null) ? [
                    'id'   => (string) ($ws['company']['uuid'] ?? ''),
                    'name' => (string) ($ws['company']['name'] ?? ''),
                ] : null,
                'summary'       => $ws['summary'],
                'opportunities' => array_map(static fn (array $o): array => [
                    'id' => (string) $o['uuid'], 'title' => (string) $o['title'], 'stage' => (string) $o['stage'],
                    'value' => (int) $o['estimated_value'], 'probability' => (int) $o['probability'],
                ], is_array($ws['opportunities']) ? $ws['opportunities'] : []),
                'tasks'         => array_map(static fn (array $t): array => [
                    'id' => (string) $t['uuid'], 'title' => (string) $t['title'], 'status' => (string) $t['status'], 'due_date' => (string) ($t['due_date'] ?? ''),
                ], is_array($ws['tasks']) ? $ws['tasks'] : []),
                'invoices'      => array_map(static fn (array $i): array => [
                    'id' => (string) $i['uuid'], 'number' => (string) ($i['number'] ?? ''), 'status' => (string) $i['status'],
                    'total' => (int) $i['total'] / 100, 'amount_due' => ((int) $i['total'] - (int) $i['amount_paid']) / 100, 'issue_date' => (string) ($i['issue_date'] ?? ''),
                ], is_array($ws['invoices']) ? $ws['invoices'] : []),
                'previews'      => array_map(static fn (array $p): array => [
                    'id' => (string) $p['uuid'], 'name' => (string) $p['name'], 'slug' => (string) $p['slug'], 'status' => (string) $p['status'], 'views' => (int) $p['view_count'],
                ], is_array($ws['previews']) ? $ws['previews'] : []),
                'emails'        => array_map(fn (array $e): array => [
                    'id' => (string) $e['uuid'], 'subject' => (string) ($e['subject'] ?? ''), 'status' => (string) $e['status'], 'to' => (string) ($e['to_email'] ?? ''), 'at' => $this->ago((string) ($e['created_at'] ?? '')),
                ], is_array($ws['emails']) ? $ws['emails'] : []),
                'events'        => array_map([$this, 'calendarRow'], is_array($ws['events']) ? $ws['events'] : []),
            ],
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

    /**
     * Require the CRM 'manage' permission for a write, returning the acting user.
     */
    private function requireManage(): \Kirby\Cms\User
    {
        $user = $this->kirby->user();
        if ($user === null || !PanelGate::canManage($user)) {
            throw new ApiException(403, 'You don’t have permission to make changes.', 'forbidden');
        }

        return $user;
    }

    private function crmWrite(): CrmWrite
    {
        return new CrmWrite($this->platform);
    }

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
