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
            $company = $this->crmWrite()->createCompany($this->body(), $actor);

            return ['company' => [
                'id'            => (string) ($company['uuid'] ?? ''),
                'name'          => (string) ($company['name'] ?? ''),
                'website'       => (string) ($company['website'] ?? ''),
                'sector'        => (string) ($company['industry'] ?? ''),
                'location'      => (string) ($company['address'] ?? ''),
                'contact_count' => (int) ($company['contact_count'] ?? 0),
            ]];
        }

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

                return ['invoice' => $this->invoiceRow($svc->issue($id, $actor), true)];
            }
            if ($method === 'POST' && $action === 'payment') {
                $requireManage();

                return ['invoice' => $this->invoiceRow($svc->recordPayment($id, $this->body(), $actor), true)];
            }
            if ($method === 'POST' && $action === 'void') {
                $requireManage();

                return ['invoice' => $this->invoiceRow($svc->void($id, $actor), true)];
            }
            if ($method === 'POST' && $action === 'send') {
                $requireManage();

                return $this->sendInvoice($id, $user);
            }

            throw new ApiException(404, 'Unknown invoice endpoint.', 'not_found');
        } catch (\Breakfast\Platform\Invoicing\InvoiceException $e) {
            throw new ApiException($e->status, $e->getMessage(), 'invoice');
        }
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
        $to = trim((string) ($this->body()['to'] ?? $inv['bill_to_email'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Enter a valid client email address.', 'invalid', ['to' => 'Required.']);
        }

        $url    = rtrim((string) $this->kirby->site()->url(), '/') . '/invoice/' . (string) $inv['public_token'];
        $number = (string) $inv['number'];
        $seller = (string) ($inv['seller_name'] ?? '') ?: 'Breakfast';
        $subject = 'Invoice ' . $number . ' from ' . $seller;
        $body = "Hi,\n\nYour invoice {$number} is ready. You can view and download it here:\n{$url}\n\nThank you,\n{$seller}";

        $result = $this->platform->crmMail()->send($to, $subject, $body, [], (string) $user->email());
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
            $token = (string) ($r['public_token'] ?? '');
            $row['public_url']      = $token !== '' ? rtrim((string) $this->kirby->site()->url(), '/') . '/invoice/' . $token : '';
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
