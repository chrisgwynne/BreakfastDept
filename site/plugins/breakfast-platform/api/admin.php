<?php

declare(strict_types=1);

use Breakfast\Platform\Admin\DashboardData;
use Breakfast\Platform\Hermes\CredentialStore;
use Breakfast\Platform\Security\PanelGate;
use Kirby\Exception\PermissionException;

/**
 * Panel API routes backing the Breakfast Admin dashboard and Operations area.
 *
 * All run inside the authenticated Panel session AND re-check permission on the
 * server. The dashboard payload is shaped by permission (a Writer receives no
 * CRM figures; only admins receive system health). Operational routes are
 * admin-only. No secret ever appears in a response — only safe summaries.
 */

$requireAdmin = static function (): void {
    if (kirby()->user()?->isAdmin() !== true) {
        throw new PermissionException('Administrators only.');
    }
};

$requireCrm = static function (): void {
    if (PanelGate::canAccess(kirby()->user()) === false) {
        throw new PermissionException('You do not have CRM access.');
    }
};

return [
    // -- Dashboard overview (permission-shaped) ---------------------------
    [
        'pattern' => 'breakfast/admin/overview',
        'method'  => 'GET',
        'action'  => function () {
            $p    = breakfast();
            $user = kirby()->user();
            $data = new DashboardData($p);

            $payload = [
                'can_crm'      => PanelGate::canAccess($user),
                'can_previews' => \Breakfast\Platform\Security\PreviewGate::canView($user),
                'is_admin'     => $user?->isAdmin() === true,
            ];

            // Preview figures only for roles that can see previews (a Writer with
            // no previews grant receives nothing).
            if (\Breakfast\Platform\Security\PreviewGate::canView($user)) {
                $payload['previews'] = $data->previewsSummary();
            }

            // CRM figures only for roles with CRM access.
            if (PanelGate::canAccess($user)) {
                $payload['metrics']    = $data->metrics();
                $payload['lead_inbox'] = $data->leadInbox();
                $payload['pipeline']   = $data->pipeline();
                $payload['tasks']      = $data->tasks();
            }

            // Website activity for content roles.
            if ($user !== null && ($user->isAdmin() || $user->role()->permissions()->for('access', 'site') === true)) {
                $payload['website'] = array_map(static fn ($page) => [
                    'title'    => (string) $page->title(),
                    'url'      => (string) $page->panel()->path(),
                    'status'   => (string) $page->status(),
                    'modified' => date('Y-m-d', (int) $page->modified()),
                ], kirby()->site()->index()->sortBy('modified', 'desc')->limit(6)->values());
            }

            // Restricted operational health for admins only.
            if ($user?->isAdmin() === true) {
                $payload['system'] = $data->systemHealth();
            }

            return ['data' => $payload];
        },
    ],

    // -- Operations: integrations / mail / hermes / queue -----------------
    [
        'pattern' => 'breakfast/admin/ops',
        'method'  => 'GET',
        'action'  => function () use ($requireAdmin) {
            $requireAdmin();
            $p   = breakfast();
            $cfg = $p->mailConfig();

            $keys = 0;
            try {
                $keys = count((new CredentialStore())->all());
            } catch (\Throwable) {
                $keys = 0;
            }

            return ['data' => [
                'mail' => [
                    'provider'        => $p->mailProvider()->name(),
                    'brevo_enabled'   => (bool) ($cfg['brevo']['enabled'] ?? false),
                    'api_configured'  => ($cfg['brevo']['apiKey'] ?? '') !== '',
                    'sender'          => (string) ($cfg['senderEmail'] ?? ''),
                    'recent_failures' => $p->outbound()->recentFailureCount(),
                    'suppressions'    => [
                        'transactional' => $p->suppressions()->count('transactional'),
                        'marketing'     => $p->suppressions()->count('marketing'),
                    ],
                ],
                'hermes' => [
                    'enabled'      => ($p->config('hermes')['enabled'] ?? false) === true,
                    'replayWindow' => (int) ($p->config('hermes')['replayWindow'] ?? 300),
                    'credentials'  => $keys,
                ],
                'queue' => [
                    'depth'  => $p->queue()->pendingCount(),
                    'failed' => $p->queue()->failedCount(),
                    'jobs'   => $p->queue()->failedJobs(),
                ],
            ]];
        },
    ],

    // -- Operations: audit log (admin only) -------------------------------
    [
        'pattern' => 'breakfast/admin/audit',
        'method'  => 'GET',
        'action'  => function () use ($requireAdmin) {
            $requireAdmin();

            return ['data' => breakfast()->audit()->recent(100)];
        },
    ],

    // -- Reports (any CRM-access role) ------------------------------------
    [
        'pattern' => 'breakfast/admin/reports',
        'method'  => 'GET',
        'action'  => function () use ($requireCrm) {
            $requireCrm();

            return ['data' => breakfast()->crm()->dashboard()];
        },
    ],
];
