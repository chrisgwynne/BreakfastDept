<?php

declare(strict_types=1);

use Breakfast\Platform\Security\PanelGate;
use Kirby\Exception\PermissionException;

/**
 * Panel API routes backing the CRM Vue views.
 *
 * These run inside the Panel session (Kirby authenticates the user) AND every
 * route re-checks the CRM permission server-side. Read routes require `access`;
 * mutating routes require `manage`; export requires `export`.
 */

$requireAccess = static function (): void {
    if (PanelGate::canAccess(kirby()->user()) === false) {
        throw new PermissionException('You do not have access to the CRM.');
    }
};

$requireManage = static function (): void {
    if (PanelGate::canManage(kirby()->user()) === false) {
        throw new PermissionException('You cannot modify CRM records.');
    }
};

$requireEmailSend = static function (): void {
    if (PanelGate::canSendEmail(kirby()->user()) === false) {
        throw new PermissionException('You do not have permission to send CRM email.');
    }
};

$requireEmailView = static function (): void {
    if (PanelGate::canViewEmailDelivery(kirby()->user()) === false) {
        throw new PermissionException('You do not have permission to view email delivery.');
    }
};

return [
    // -- Dashboard --------------------------------------------------------
    [
        'pattern' => 'breakfast/crm/dashboard',
        'method'  => 'GET',
        'action'  => function () use ($requireAccess) {
            $requireAccess();
            $p = breakfast();
            $data = $p->crm()->dashboard();
            $data['failed_jobs'] = $p->queue()->failedCount();
            $data['queue_depth'] = $p->queue()->pendingCount();

            return $data;
        },
    ],

    // -- Enquiries --------------------------------------------------------
    [
        'pattern' => 'breakfast/crm/enquiries',
        'method'  => 'GET',
        'action'  => function () use ($requireAccess) {
            $requireAccess();

            return ['data' => breakfast()->enquiries()->search([
                'status'    => get('status', ''),
                'form_type' => get('form_type', ''),
                'search'    => get('q', ''),
                'limit'     => 100,
            ])];
        },
    ],
    [
        'pattern' => 'breakfast/crm/enquiries/(:any)',
        'method'  => 'GET',
        'action'  => function (string $uuid) use ($requireAccess) {
            $requireAccess();
            $p = breakfast();
            $enquiry = $p->enquiries()->find($uuid);
            if ($enquiry === null) {
                return ['error' => 'Not found'];
            }
            $enquiry['activity'] = $p->activities()->forEntity('enquiry', $uuid);
            if (!empty($enquiry['contact_uuid'])) {
                $enquiry['contact'] = $p->contacts()->find((string) $enquiry['contact_uuid']);
            }

            return ['data' => $enquiry];
        },
    ],
    [
        'pattern' => 'breakfast/crm/enquiries/(:any)/status',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($requireManage) {
            $requireManage();
            $status = get('status', 'reviewing');
            $updated = breakfast()->crm()->setEnquiryStatus($uuid, $status, 'user', kirby()->user()?->email());

            return ['data' => $updated];
        },
    ],
    [
        'pattern' => 'breakfast/crm/enquiries/(:any)/note',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($requireManage) {
            $requireManage();
            $note = trim((string) get('note', ''));
            if ($note === '') {
                return ['error' => 'Note is empty'];
            }
            $activity = breakfast()->crm()->addNote('enquiry', $uuid, $note, 'user', kirby()->user()?->email());

            return ['data' => $activity];
        },
    ],
    [
        'pattern' => 'breakfast/crm/enquiries/(:any)/convert',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($requireManage) {
            $requireManage();
            $p = breakfast();
            $enquiry = $p->enquiries()->find($uuid);
            if ($enquiry === null) {
                return ['error' => 'Not found'];
            }
            $opp = $p->crm()->createOpportunity([
                'title'        => (string) ($enquiry['summary'] ?? 'New opportunity'),
                'contact_uuid' => $enquiry['contact_uuid'] ?? null,
                'company_uuid' => $enquiry['company_uuid'] ?? null,
                'enquiry_uuid' => $uuid,
                'stage'        => 'qualified',
            ], 'user', kirby()->user()?->email());
            $p->crm()->setEnquiryStatus($uuid, 'qualified', 'user', kirby()->user()?->email());

            return ['data' => $opp];
        },
    ],

    // -- Contacts ---------------------------------------------------------
    [
        'pattern' => 'breakfast/crm/contacts',
        'method'  => 'GET',
        'action'  => function () use ($requireAccess) {
            $requireAccess();

            return ['data' => breakfast()->contacts()->search([
                'search' => get('q', ''),
                'status' => get('status', ''),
                'limit'  => 100,
            ])];
        },
    ],
    [
        'pattern' => 'breakfast/crm/contacts/(:any)',
        'method'  => 'GET',
        'action'  => function (string $uuid) use ($requireAccess) {
            $requireAccess();
            $p = breakfast();
            $contact = $p->contacts()->find($uuid);
            if ($contact === null) {
                return ['error' => 'Not found'];
            }
            $contact['activity'] = $p->activities()->forEntity('contact', $uuid);

            return ['data' => $contact];
        },
    ],

    // -- Companies --------------------------------------------------------
    [
        'pattern' => 'breakfast/crm/companies',
        'method'  => 'GET',
        'action'  => function () use ($requireAccess) {
            $requireAccess();

            return ['data' => breakfast()->companies()->all()];
        },
    ],

    // -- Opportunities / pipeline ----------------------------------------
    [
        'pattern' => 'breakfast/crm/opportunities',
        'method'  => 'GET',
        'action'  => function () use ($requireAccess) {
            $requireAccess();

            return [
                'data'   => breakfast()->opportunities()->search(['limit' => 200]),
                'stages' => breakfast()->crm()->stages(),
            ];
        },
    ],
    [
        'pattern' => 'breakfast/crm/opportunities/(:any)/stage',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($requireManage) {
            $requireManage();
            $stage = get('stage', 'new');
            $updated = breakfast()->crm()->moveOpportunity($uuid, $stage, 'user', kirby()->user()?->email(), get('lost_reason'));

            return ['data' => $updated];
        },
    ],

    // -- Tasks ------------------------------------------------------------
    [
        'pattern' => 'breakfast/crm/tasks',
        'method'  => 'GET',
        'action'  => function () use ($requireAccess) {
            $requireAccess();

            return ['data' => breakfast()->tasks()->search([
                'status'  => get('status', ''),
                'overdue' => get('overdue') === 'true',
                'limit'   => 200,
            ])];
        },
    ],
    [
        'pattern' => 'breakfast/crm/tasks',
        'method'  => 'POST',
        'action'  => function () use ($requireManage) {
            $requireManage();
            $task = breakfast()->crm()->createTask([
                'title'            => (string) get('title', 'Follow up'),
                'contact_uuid'     => get('contact_uuid'),
                'opportunity_uuid' => get('opportunity_uuid'),
                'due_date'         => get('due_date'),
                'priority'         => get('priority', 'normal'),
                'notes'            => get('notes'),
            ], 'user', kirby()->user()?->email());

            return ['data' => $task];
        },
    ],
    [
        'pattern' => 'breakfast/crm/tasks/(:any)',
        'method'  => 'PATCH',
        'action'  => function (string $uuid) use ($requireManage) {
            $requireManage();
            $patch = array_filter([
                'status'   => get('status'),
                'priority' => get('priority'),
                'due_date' => get('due_date'),
                'title'    => get('title'),
            ], fn ($v) => $v !== null);
            $task = breakfast()->tasks()->update($uuid, $patch);

            return ['data' => $task];
        },
    ],

    // -- CRM email --------------------------------------------------------
    [
        'pattern' => 'breakfast/crm/email/preview',
        'method'  => 'POST',
        'action'  => function () use ($requireManage) {
            $requireManage();

            return ['data' => breakfast()->crmMail()->preview((string) get('subject', ''), (string) get('body', ''))];
        },
    ],
    [
        'pattern' => 'breakfast/crm/email/send',
        'method'  => 'POST',
        'action'  => function () use ($requireEmailSend) {
            $requireEmailSend();
            $result = breakfast()->crmMail()->send(
                (string) get('to', ''),
                (string) get('subject', ''),
                (string) get('body', ''),
                [
                    'contact_uuid'     => get('contact_uuid'),
                    'enquiry_uuid'     => get('enquiry_uuid'),
                    'opportunity_uuid' => get('opportunity_uuid'),
                ],
                kirby()->user()?->email()
            );

            return $result['ok'] ? ['data' => $result] : ['status' => 'error', 'error' => $result['error']];
        },
    ],
    [
        'pattern' => 'breakfast/crm/email/test',
        'method'  => 'POST',
        'action'  => function () use ($requireEmailSend) {
            $requireEmailSend();
            $me = kirby()->user()?->email();
            if ($me === null) {
                return ['status' => 'error', 'error' => 'no_user_email'];
            }
            $result = breakfast()->crmMail()->sendTest($me, (string) get('subject', 'Test'), (string) get('body', 'Test body'));

            return $result['ok'] ? ['data' => $result] : ['status' => 'error', 'error' => $result['error']];
        },
    ],
    [
        'pattern' => 'breakfast/crm/email/deliveries',
        'method'  => 'GET',
        'action'  => function () use ($requireEmailView) {
            $requireEmailView();

            return ['data' => breakfast()->outbound()->search(['status' => get('status', ''), 'type' => get('type', ''), 'limit' => 100])];
        },
    ],
    [
        'pattern' => 'breakfast/crm/mail/status',
        'method'  => 'GET',
        'action'  => function () use ($requireEmailView) {
            $requireEmailView();
            $p   = breakfast();
            $cfg = $p->mailConfig();

            return ['data' => [
                'provider'        => $p->mailProvider()->name(),
                'brevo_enabled'   => (bool) ($cfg['brevo']['enabled'] ?? false),
                'api_configured'  => ($cfg['brevo']['apiKey'] ?? '') !== '',
                'sender'          => (string) ($cfg['senderEmail'] ?? ''),
                'queue_depth'     => $p->queue()->pendingCount(),
                'failed_jobs'     => $p->queue()->failedCount(),
                'recent_failures' => $p->outbound()->recentFailureCount(),
                'suppressions'    => [
                    'transactional' => $p->suppressions()->count('transactional'),
                    'marketing'     => $p->suppressions()->count('marketing'),
                ],
                'contact_sync'    => (bool) ($cfg['contactSync']['enabled'] ?? false),
                'templates'       => $p->templates()->all(),
            ]];
        },
    ],

    // -- Failed jobs ------------------------------------------------------
    [
        'pattern' => 'breakfast/crm/jobs/failed',
        'method'  => 'GET',
        'action'  => function () use ($requireAccess) {
            $requireAccess();

            return ['data' => breakfast()->queue()->failedJobs()];
        },
    ],
    [
        'pattern' => 'breakfast/crm/jobs/(:any)/retry',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($requireManage) {
            $requireManage();
            breakfast()->queue()->retry($uuid);

            return ['data' => ['ok' => true]];
        },
    ],
];
