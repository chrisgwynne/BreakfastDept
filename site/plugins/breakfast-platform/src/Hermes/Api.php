<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;
use Throwable;

/**
 * The Hermes integration boundary.
 *
 * A dedicated, versioned API surface — NOT Kirby's REST API or KQL, and NOT a
 * Panel admin session. Every request is HMAC-authenticated, every endpoint
 * declares and enforces a single scope, and every call is written to the
 * immutable audit trail. Write actions are strictly limited: Hermes can create
 * unpublished drafts and add CRM notes/tasks/classifications, but can never
 * publish, delete, change users/permissions, read secrets, or export the full
 * CRM without the privileged crm:export scope.
 */
final class Api
{
    /** @var list<array{method:string,pattern:string,scope:string,handler:string}> */
    private array $routes;

    public function __construct(
        private readonly Platform $platform,
        private readonly Authenticator $auth,
        private readonly AuditLog $audit,
        private readonly ?DraftFactory $drafts = null
    ) {
        $this->routes = $this->routeTable();
    }

    /**
     * @param array<string,string> $headers lowercase header => value
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $method, string $path, string $body, array $headers): array
    {
        $requestId = Uuid::v4();
        $method    = strtoupper($method);
        $path      = '/' . trim($path, '/');

        // /health is the only unauthenticated endpoint (no secrets exposed).
        if ($method === 'GET' && $path === '/health') {
            return $this->respond(200, $this->health(), $requestId);
        }

        $route = $this->match($method, $path);
        if ($route === null) {
            return $this->respond(404, ['error' => 'not_found'], $requestId);
        }

        // Authenticate.
        $authResult = $this->auth->authenticate($method, $path, $body, $headers);
        if ($authResult->ok === false) {
            $this->audit->record($headers['x-hermes-key'] ?? 'unknown', null, $method, $path, 'denied', $authResult->status, $requestId, null, null, ['reason' => $authResult->error]);

            return $this->respond($authResult->status, ['error' => 'unauthorized'], $requestId);
        }

        $credential = $authResult->credential;

        // Authorize scope.
        if ($this->auth->authorize($credential, $route['scope']) === false) {
            $this->audit->record($credential->id, $route['scope'], $method, $path, 'denied', 403, $requestId, null, null, ['reason' => 'missing_scope']);

            return $this->respond(403, ['error' => 'forbidden', 'required_scope' => $route['scope']], $requestId);
        }

        // Parse body for write endpoints.
        $input = [];
        if (in_array($method, ['POST', 'PATCH', 'PUT'], true) && $body !== '') {
            $decoded = json_decode($body, true);
            $input   = is_array($decoded) ? $decoded : [];
        }

        try {
            $matches = $this->matchParams($route['pattern'], $path);
            $result  = $this->{$route['handler']}($matches, $input, $credential);
            $status  = $result['status'] ?? 200;

            $this->audit->record(
                $credential->id,
                $route['scope'],
                $method,
                $path,
                $status < 400 ? 'ok' : 'error',
                $status,
                $requestId,
                $result['target_type'] ?? null,
                $result['target_uuid'] ?? null,
                $result['audit'] ?? []
            );

            return $this->respond($status, $result['body'] ?? [], $requestId);
        } catch (Throwable $e) {
            $this->platform->logger()->error('hermes', 'Endpoint error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            $this->audit->record($credential->id, $route['scope'], $method, $path, 'error', 500, $requestId);

            return $this->respond(500, ['error' => 'internal_error'], $requestId);
        }
    }

    // -- Endpoint handlers -------------------------------------------------

    /** @return array<string,mixed> */
    private function health(): array
    {
        $queue = $this->platform->queue();

        return [
            'status'      => 'ok',
            'time'        => Clock::nowIso(),
            'queue_depth' => $queue->pendingCount(),
            'failed_jobs' => $queue->failedCount(),
        ];
    }

    /** @return array{body:array<string,mixed>} */
    private function siteSummary(array $m, array $in, Credential $c): array
    {
        return ['body' => [
            'name'     => 'Breakfast',
            'projects' => $this->kirbyCount('project'),
            'articles' => $this->kirbyCount('article'),
            'services' => $this->kirbyCount('service'),
        ]];
    }

    /** @return array{body:array<string,mixed>} */
    private function crmSummary(array $m, array $in, Credential $c): array
    {
        $d = $this->platform->crm()->dashboard();

        return ['body' => [
            'new_enquiries'      => $d['new_enquiries'],
            'qualified'          => $d['qualified_enquiries'],
            'open_opportunities' => $d['open_opportunities'],
            'pipeline_value'     => $d['pipeline_value'],
            'overdue_tasks'      => $d['overdue_tasks'],
            'upcoming_tasks'     => $d['upcoming_tasks'],
        ]];
    }

    /** @return array{body:array<string,mixed>} */
    private function listEnquiries(array $m, array $in, Credential $c): array
    {
        $rows = $this->platform->enquiries()->search(['limit' => 50]);

        return ['body' => ['enquiries' => array_map([$this, 'presentEnquiry'], $rows)]];
    }

    /** @return array<string,mixed> */
    private function getEnquiry(array $m, array $in, Credential $c): array
    {
        $enquiry = $this->platform->enquiries()->find($m['uuid'] ?? '');
        if ($enquiry === null) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }

        return [
            'body'        => ['enquiry' => $this->presentEnquiry($enquiry, true)],
            'target_type' => 'enquiry',
            'target_uuid' => $enquiry['uuid'],
        ];
    }

    /** @return array<string,mixed> */
    private function classifyEnquiry(array $m, array $in, Credential $c): array
    {
        $uuid = $m['uuid'] ?? '';
        $updated = $this->platform->crm()->classifyEnquiry($uuid, [
            'status'      => $in['status'] ?? null,
            'spam_status' => $in['spam_status'] ?? null,
            'risk_score'  => isset($in['risk_score']) ? (int) $in['risk_score'] : null,
            'summary'     => isset($in['summary']) ? (string) $in['summary'] : null,
            'services'    => $in['services'] ?? null,
        ], 'hermes', $c->id);

        if ($updated === null) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }

        return ['body' => ['ok' => true, 'enquiry' => $this->presentEnquiry($updated)], 'target_type' => 'enquiry', 'target_uuid' => $uuid, 'audit' => ['status' => $in['status'] ?? null]];
    }

    /** @return array<string,mixed> */
    private function noteEnquiry(array $m, array $in, Credential $c): array
    {
        return $this->addNote('enquiry', $m['uuid'] ?? '', $in, $c);
    }

    /** @return array<string,mixed> */
    private function noteContact(array $m, array $in, Credential $c): array
    {
        return $this->addNote('contact', $m['uuid'] ?? '', $in, $c);
    }

    /** @return array<string,mixed> */
    private function noteOpportunity(array $m, array $in, Credential $c): array
    {
        return $this->addNote('opportunity', $m['uuid'] ?? '', $in, $c);
    }

    /** @return array<string,mixed> */
    private function getContact(array $m, array $in, Credential $c): array
    {
        $contact = $this->platform->contacts()->find($m['uuid'] ?? '');
        if ($contact === null) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }

        return ['body' => ['contact' => [
            'uuid'    => $contact['uuid'],
            'name'    => $contact['display_name'],
            'email'   => $contact['email'],
            'company' => $contact['company_name'] ?? null,
            'status'  => $contact['status'],
        ]], 'target_type' => 'contact', 'target_uuid' => $contact['uuid']];
    }

    /** @return array{body:array<string,mixed>} */
    private function listOpportunities(array $m, array $in, Credential $c): array
    {
        $rows = $this->platform->opportunities()->search(['open' => true, 'limit' => 50]);

        return ['body' => ['opportunities' => array_map(static fn ($o) => [
            'uuid'  => $o['uuid'],
            'title' => $o['title'],
            'stage' => $o['stage'],
            'value' => $o['estimated_value'],
            'contact' => $o['contact_name'] ?? null,
        ], $rows)]];
    }

    /** @return array{body:array<string,mixed>} */
    private function listTasks(array $m, array $in, Credential $c): array
    {
        $rows = $this->platform->tasks()->search(['status' => 'open', 'limit' => 50]);

        return ['body' => ['tasks' => array_map(static fn ($t) => [
            'uuid'     => $t['uuid'],
            'title'    => $t['title'],
            'due_date' => $t['due_date'],
            'priority' => $t['priority'],
        ], $rows)]];
    }

    /** @return array<string,mixed> */
    private function createTask(array $m, array $in, Credential $c): array
    {
        if (empty($in['title'])) {
            return ['status' => 422, 'body' => ['error' => 'title_required']];
        }

        $task = $this->platform->crm()->createTask([
            'title'            => (string) $in['title'],
            'contact_uuid'     => $in['contact_uuid'] ?? null,
            'opportunity_uuid' => $in['opportunity_uuid'] ?? null,
            'due_date'         => $in['due_date'] ?? null,
            'priority'         => $in['priority'] ?? 'normal',
            'notes'            => $in['notes'] ?? null,
        ], 'hermes', $c->id);

        return ['status' => 201, 'body' => ['task' => ['uuid' => $task['uuid'], 'title' => $task['title']]], 'target_type' => 'task', 'target_uuid' => $task['uuid']];
    }

    /**
     * Create a TENTATIVE internal calendar event for human review. Hermes can
     * only ever create events in the 'tentative' state and cannot invite external
     * attendees, email anyone, cancel/reschedule confirmed meetings or delete —
     * those fields/actions are simply not exposed on this endpoint.
     *
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function createCalendarEvent(array $m, array $in, Credential $c): array
    {
        if (empty($in['title']) || empty($in['starts_at']) || empty($in['ends_at'])) {
            return ['status' => 422, 'body' => ['error' => 'title_starts_ends_required']];
        }
        $allowedTypes = ['call', 'meeting', 'follow_up', 'reminder', 'deadline'];
        $type = (string) ($in['event_type'] ?? 'follow_up');
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'follow_up';
        }

        try {
            $event = $this->platform->calendar()->create([
                'title'            => (string) $in['title'],
                'description'      => (string) ($in['description'] ?? ''),
                'starts_at'        => (string) $in['starts_at'],
                'ends_at'          => (string) $in['ends_at'],
                'event_type'       => $type,
                'status'           => 'tentative', // always tentative — awaits human confirmation
                'contact_uuid'     => $in['contact_uuid'] ?? null,
                'company_uuid'     => $in['company_uuid'] ?? null,
                'opportunity_uuid' => $in['opportunity_uuid'] ?? null,
                'reminder_minutes' => (int) ($in['reminder_minutes'] ?? 0),
            ], $c->id, 'hermes');
        } catch (\Breakfast\Platform\Calendar\CalendarException $e) {
            return ['status' => $e->status, 'body' => ['error' => 'invalid', 'fields' => $e->fields]];
        }

        return ['status' => 201, 'body' => ['event' => ['uuid' => $event['uuid'], 'title' => $event['title'], 'status' => $event['status']]], 'target_type' => 'calendar_event', 'target_uuid' => $event['uuid']];
    }

    /**
     * Create a lead through the same validated, transactional, audited service a
     * human uses. Hermes cannot delete, merge or edit protected fields.
     *
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function createLead(array $m, array $in, Credential $c): array
    {
        try {
            $result = (new \Breakfast\Platform\Admin\CrmWrite($this->platform))->createLead($in, 'hermes:' . $c->id);
        } catch (\Breakfast\Platform\Admin\ApiException $e) {
            return ['status' => $e->status, 'body' => ['error' => 'invalid', 'fields' => $e->fields]];
        }
        $enquiry = is_array($result['enquiry']) ? $result['enquiry'] : [];

        return ['status' => 201, 'body' => ['lead' => ['uuid' => $enquiry['uuid'] ?? null, 'reference' => $enquiry['reference'] ?? null]], 'target_type' => 'enquiry', 'target_uuid' => (string) ($enquiry['uuid'] ?? '')];
    }

    /**
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function createContactRecord(array $m, array $in, Credential $c): array
    {
        try {
            $result = (new \Breakfast\Platform\Admin\CrmWrite($this->platform))->createContact($in, 'hermes:' . $c->id);
        } catch (\Breakfast\Platform\Admin\ApiException $e) {
            return ['status' => $e->status, 'body' => ['error' => 'invalid', 'fields' => $e->fields]];
        }
        $contact = is_array($result['contact']) ? $result['contact'] : [];

        return ['status' => 201, 'body' => ['contact' => ['uuid' => $contact['uuid'] ?? null]], 'target_type' => 'contact', 'target_uuid' => (string) ($contact['uuid'] ?? '')];
    }

    /**
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function createCompanyRecord(array $m, array $in, Credential $c): array
    {
        try {
            $company = (new \Breakfast\Platform\Admin\CrmWrite($this->platform))->createCompany($in, 'hermes:' . $c->id);
        } catch (\Breakfast\Platform\Admin\ApiException $e) {
            return ['status' => $e->status, 'body' => ['error' => 'invalid', 'fields' => $e->fields]];
        }

        return ['status' => 201, 'body' => ['company' => ['uuid' => $company['uuid'] ?? null, 'name' => $company['name'] ?? null]], 'target_type' => 'company', 'target_uuid' => (string) ($company['uuid'] ?? '')];
    }

    /** @return array<string,mixed> */
    private function updateTask(array $m, array $in, Credential $c): array
    {
        $uuid = $m['uuid'] ?? '';
        $patch = array_intersect_key($in, array_flip(['title', 'due_date', 'priority', 'status', 'notes']));
        $task = $this->platform->tasks()->update($uuid, $patch);

        if ($task === null) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }

        return ['body' => ['task' => ['uuid' => $task['uuid'], 'status' => $task['status']]], 'target_type' => 'task', 'target_uuid' => $uuid];
    }

    /** @return array{body:array<string,mixed>} */
    private function contentChanged(array $m, array $in, Credential $c): array
    {
        return ['body' => ['changed' => $this->kirbyChanged()]];
    }

    /** @return array{body:array<string,mixed>} */
    private function listJournal(array $m, array $in, Credential $c): array
    {
        return ['body' => ['articles' => $this->kirbyList('journal', 'article')]];
    }

    /** @return array{body:array<string,mixed>} */
    private function listProjects(array $m, array $in, Credential $c): array
    {
        return ['body' => ['projects' => $this->kirbyList('work', 'project')]];
    }

    /** @return array<string,mixed> */
    private function createDraft(string $type, array $in, Credential $c): array
    {
        if ($this->drafts === null) {
            return ['status' => 503, 'body' => ['error' => 'drafts_unavailable']];
        }

        $result = $this->drafts->create($type, $in);
        if ($result['ok'] === false) {
            return ['status' => 422, 'body' => ['error' => $result['error']]];
        }

        return [
            'status'      => 201,
            'body'        => ['draft' => ['id' => $result['id'], 'status' => 'draft']],
            'target_type' => $type,
            'target_uuid' => $result['id'],
            'audit'       => ['title' => $in['title'] ?? null],
        ];
    }

    /** @return array<string,mixed> */
    private function draftJournal(array $m, array $in, Credential $c): array
    {
        return $this->createDraft('journal', $in, $c);
    }

    /** @return array<string,mixed> */
    private function draftProject(array $m, array $in, Credential $c): array
    {
        return $this->createDraft('project', $in, $c);
    }

    /** @return array<string,mixed> */
    private function draftPage(array $m, array $in, Credential $c): array
    {
        return $this->createDraft('page', $in, $c);
    }

    /** @return array{body:array<string,mixed>} */
    private function webhookTest(array $m, array $in, Credential $c): array
    {
        $this->platform->webhooks()->dispatch('webhooks.test', [
            'message' => 'Hermes test event',
            'by'      => $c->id,
        ]);

        return ['body' => ['ok' => true, 'dispatched' => true]];
    }

    // -- Helpers -----------------------------------------------------------

    /** @return array<string,mixed> */
    private function addNote(string $entityType, string $uuid, array $in, Credential $c): array
    {
        $note = trim((string) ($in['note'] ?? ''));
        if ($note === '') {
            return ['status' => 422, 'body' => ['error' => 'note_required']];
        }

        // Confirm the entity exists.
        $exists = match ($entityType) {
            'enquiry'     => $this->platform->enquiries()->find($uuid),
            'contact'     => $this->platform->contacts()->find($uuid),
            'opportunity' => $this->platform->opportunities()->find($uuid),
            default       => null,
        };

        if ($exists === null) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }

        $activity = $this->platform->crm()->addNote($entityType, $uuid, $note, 'hermes', $c->id);

        return ['status' => 201, 'body' => ['ok' => true, 'activity_uuid' => $activity['uuid']], 'target_type' => $entityType, 'target_uuid' => $uuid];
    }

    /**
     * @param array<string,mixed> $enquiry
     * @return array<string,mixed>
     */
    private function presentEnquiry(array $enquiry, bool $full = false): array
    {
        $out = [
            'uuid'       => $enquiry['uuid'],
            'reference'  => $enquiry['reference'],
            'form_type'  => $enquiry['form_type'],
            'summary'    => $enquiry['summary'],
            'status'     => $enquiry['status'],
            'spam_status' => $enquiry['spam_status'],
            'created_at' => $enquiry['created_at'],
        ];

        if ($full) {
            $out['payload']      = $enquiry['payload'] ?? [];
            $out['contact_uuid'] = $enquiry['contact_uuid'] ?? null;
            $out['utm']          = $enquiry['utm'] ?? [];
        }

        return $out;
    }

    private function kirbyCount(string $template): int
    {
        if (function_exists('kirby') === false || kirby() === null) {
            return 0;
        }

        return kirby()->site()->index()->filterBy('intendedTemplate', $template)->count();
    }

    /** @return list<array<string,mixed>> */
    private function kirbyList(string $parent, string $template): array
    {
        if (function_exists('kirby') === false || kirby() === null) {
            return [];
        }

        $page = kirby()->page($parent);
        if ($page === null) {
            return [];
        }

        $out = [];
        foreach ($page->children()->listed() as $child) {
            $out[] = [
                'uuid'  => $child->uuid()->id(),
                'title' => $child->title()->value(),
                'slug'  => $child->slug(),
                'url'   => $child->url(),
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function kirbyChanged(): array
    {
        if (function_exists('kirby') === false || kirby() === null) {
            return [];
        }

        $out = [];
        foreach (kirby()->site()->index() as $page) {
            $out[] = [
                'uuid'     => $page->uuid()->id(),
                'title'    => $page->title()->value(),
                'modified' => date('c', $page->modified()),
                'status'   => $page->status(),
            ];
        }

        usort($out, static fn ($a, $b) => strcmp($b['modified'], $a['modified']));

        return array_slice($out, 0, 50);
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    private function respond(int $status, array $body, string $requestId): array
    {
        $body['request_id'] = $requestId;

        return ['status' => $status, 'body' => $body];
    }

    private function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($this->compile($route['pattern']), $path) === 1) {
                return $route;
            }
        }

        return null;
    }

    /** @return array<string,string> */
    private function matchParams(string $pattern, string $path): array
    {
        preg_match($this->compile($pattern), $path, $m);

        return array_filter($m, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace('/\{(\w+)\}/', '(?<$1>[0-9a-zA-Z\-]+)', $pattern);

        return '#^' . $regex . '$#';
    }

    /**
     * @return list<array{method:string,pattern:string,scope:string,handler:string}>
     */
    private function routeTable(): array
    {
        return [
            ['method' => 'GET',   'pattern' => '/site-summary',                    'scope' => Scopes::CONTENT_READ,  'handler' => 'siteSummary'],
            ['method' => 'GET',   'pattern' => '/content/changed',                 'scope' => Scopes::CONTENT_READ,  'handler' => 'contentChanged'],
            ['method' => 'GET',   'pattern' => '/journal',                         'scope' => Scopes::CONTENT_READ,  'handler' => 'listJournal'],
            ['method' => 'GET',   'pattern' => '/projects',                        'scope' => Scopes::CONTENT_READ,  'handler' => 'listProjects'],
            ['method' => 'GET',   'pattern' => '/crm/summary',                     'scope' => Scopes::CRM_SUMMARY,   'handler' => 'crmSummary'],
            ['method' => 'GET',   'pattern' => '/crm/enquiries',                   'scope' => Scopes::CRM_READ,      'handler' => 'listEnquiries'],
            ['method' => 'GET',   'pattern' => '/crm/enquiries/{uuid}',            'scope' => Scopes::CRM_READ,      'handler' => 'getEnquiry'],
            ['method' => 'GET',   'pattern' => '/crm/contacts/{uuid}',             'scope' => Scopes::CRM_READ,      'handler' => 'getContact'],
            ['method' => 'GET',   'pattern' => '/crm/opportunities',               'scope' => Scopes::CRM_READ,      'handler' => 'listOpportunities'],
            ['method' => 'GET',   'pattern' => '/crm/tasks',                       'scope' => Scopes::CRM_READ,      'handler' => 'listTasks'],
            ['method' => 'POST',  'pattern' => '/crm/enquiries/{uuid}/classify',   'scope' => Scopes::CRM_CLASSIFY,  'handler' => 'classifyEnquiry'],
            ['method' => 'POST',  'pattern' => '/crm/enquiries/{uuid}/note',       'scope' => Scopes::CRM_NOTES,     'handler' => 'noteEnquiry'],
            ['method' => 'POST',  'pattern' => '/crm/contacts/{uuid}/note',        'scope' => Scopes::CRM_NOTES,     'handler' => 'noteContact'],
            ['method' => 'POST',  'pattern' => '/crm/opportunities/{uuid}/note',   'scope' => Scopes::CRM_NOTES,     'handler' => 'noteOpportunity'],
            ['method' => 'POST',  'pattern' => '/crm/tasks',                       'scope' => Scopes::CRM_TASKS,     'handler' => 'createTask'],
            ['method' => 'PATCH', 'pattern' => '/crm/tasks/{uuid}',                'scope' => Scopes::CRM_TASKS,     'handler' => 'updateTask'],
            ['method' => 'POST',  'pattern' => '/calendar/events',                 'scope' => Scopes::CALENDAR_CREATE, 'handler' => 'createCalendarEvent'],
            ['method' => 'POST',  'pattern' => '/crm/leads',                       'scope' => Scopes::CRM_LEADS_CREATE,     'handler' => 'createLead'],
            ['method' => 'POST',  'pattern' => '/crm/contacts',                    'scope' => Scopes::CRM_CONTACTS_CREATE,  'handler' => 'createContactRecord'],
            ['method' => 'POST',  'pattern' => '/crm/companies',                   'scope' => Scopes::CRM_COMPANIES_CREATE, 'handler' => 'createCompanyRecord'],
            ['method' => 'POST',  'pattern' => '/drafts/journal',                  'scope' => Scopes::CONTENT_DRAFT, 'handler' => 'draftJournal'],
            ['method' => 'POST',  'pattern' => '/drafts/project',                  'scope' => Scopes::CONTENT_DRAFT, 'handler' => 'draftProject'],
            ['method' => 'POST',  'pattern' => '/drafts/page',                     'scope' => Scopes::CONTENT_DRAFT, 'handler' => 'draftPage'],
            ['method' => 'POST',  'pattern' => '/webhooks/test',                   'scope' => Scopes::WEBHOOKS_TEST, 'handler' => 'webhookTest'],
            // Client Previews — read / analyse / draft only. No upload, publish,
            // URL change, password reveal, delete or send.
            ['method' => 'GET',   'pattern' => '/previews/summary',                'scope' => Scopes::PREVIEWS_SUMMARY, 'handler' => 'previewsSummary'],
            ['method' => 'GET',   'pattern' => '/previews',                        'scope' => Scopes::PREVIEWS_READ,    'handler' => 'listPreviews'],
            ['method' => 'GET',   'pattern' => '/previews/{uuid}',                 'scope' => Scopes::PREVIEWS_READ,    'handler' => 'getPreview'],
            ['method' => 'GET',   'pattern' => '/previews/{uuid}/analyse',         'scope' => Scopes::PREVIEWS_ANALYSE, 'handler' => 'analysePreview'],
            ['method' => 'POST',  'pattern' => '/previews/draft',                  'scope' => Scopes::PREVIEWS_DRAFT,   'handler' => 'draftPreview'],
        ];
    }

    /**
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function previewsSummary(array $m, array $in, $credential): array
    {
        $repo = $this->platform->previews();

        return ['body' => ['previews' => [
            'total'    => $repo->count(),
            'active'   => $repo->count('active'),
            'draft'    => $repo->count('draft'),
            'expired'  => $repo->count('expired'),
            'disabled' => $repo->count('disabled'),
        ]]];
    }

    /**
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function listPreviews(array $m, array $in, $credential): array
    {
        $rows = $this->platform->previews()->all(['limit' => 100]);

        return ['body' => ['previews' => array_map(static fn ($p) => [
            'uuid'       => $p['uuid'],
            'name'       => $p['name'],
            'slug'       => $p['public_slug'],
            'status'     => $p['status'],
            'visibility' => $p['visibility'],
            'created_at' => $p['created_at'],
        ], $rows)]];
    }

    /**
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function getPreview(array $m, array $in, $credential): array
    {
        $preview = $this->platform->previews()->find($m['uuid'] ?? '');
        if ($preview === null) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }

        // Never expose the password hash or storage paths to Hermes.
        return ['body' => ['preview' => [
            'uuid'       => $preview['uuid'],
            'name'       => $preview['name'],
            'slug'       => $preview['public_slug'],
            'status'     => $preview['status'],
            'visibility' => $preview['visibility'],
            'expires_at' => $preview['expires_at'],
            'versions'   => count($this->platform->previewVersions()->forPreview((string) $preview['uuid'])),
        ]]];
    }

    /**
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function analysePreview(array $m, array $in, $credential): array
    {
        $preview = $this->platform->previews()->find($m['uuid'] ?? '');
        if ($preview === null) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }
        $current = (string) ($preview['current_version_uuid'] ?? '');
        $version = $current !== '' ? $this->platform->previewVersions()->find($current) : null;
        $report  = $version !== null && is_string($version['validation_report'] ?? null)
            ? (json_decode((string) $version['validation_report'], true) ?: [])
            : [];

        return ['body' => [
            'analysis' => [
                'warnings'  => is_array($report) ? ($report['warnings'] ?? []) : [],
                'analytics' => $this->platform->previewAnalytics()->summary((string) $preview['uuid']),
            ],
        ]];
    }

    /**
     * Create an UNPUBLISHED draft preview record for later human review. Hermes
     * may suggest a name/slug/links; it can never upload, publish or make live.
     *
     * @param array<string,string> $m
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function draftPreview(array $m, array $in, $credential): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return ['status' => 422, 'body' => ['error' => 'name_required']];
        }
        $slug = \Breakfast\Platform\ClientPreviews\PreviewSlug::normalise((string) ($in['slug'] ?? $name));
        $check = \Breakfast\Platform\ClientPreviews\PreviewSlug::validate($slug);
        if ($check['ok'] === false || $this->platform->previews()->slugTaken($slug)) {
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 4);
        }

        $preview = $this->platform->previews()->create([
            'name'             => $name,
            'public_slug'      => $slug,
            'contact_uuid'     => $in['contact_uuid'] ?? null,
            'opportunity_uuid' => $in['opportunity_uuid'] ?? null,
            'note'             => 'Drafted by Hermes — needs human review, upload and publish.',
            'created_by'       => 'hermes:' . $credential->id,
            'status'           => \Breakfast\Platform\ClientPreviews\PreviewStatus::DRAFT,
        ]);

        return [
            'status'      => 201,
            'body'        => ['preview' => ['uuid' => $preview['uuid'], 'slug' => $slug, 'status' => 'draft']],
            'target_type' => 'preview',
            'target_uuid' => $preview['uuid'],
            'audit'       => ['name' => $name, 'slug' => $slug],
        ];
    }
}
