<?php

declare(strict_types=1);

use Breakfast\Platform\Security\PanelGate;

/**
 * Custom "CRM" Panel area.
 *
 * The menu entry is only shown to users who pass the CRM permission gate, and
 * every view re-checks access server-side (hiding the menu is not access
 * control). Views render Vue components registered by the plugin's index.js;
 * those components load their data from the permission-guarded API in api/crm.php.
 */

return function ($kirby) {
    $denied = static function (string $title): array {
        return [
            'component' => 'k-error-view',
            'title'     => $title,
            'props'     => [
                'error' => 'You do not have permission to view the CRM.',
                'layout' => 'outside',
            ],
        ];
    };

    $guard = static function (callable $view) use ($kirby, $denied) {
        return function (...$args) use ($kirby, $view, $denied) {
            if (PanelGate::canAccess($kirby->user()) === false) {
                return $denied('CRM');
            }

            return $view(...$args);
        };
    };

    return [
        'label' => 'CRM',
        'icon'  => 'users',
        'menu'  => fn () => PanelGate::canAccess($kirby->user()),
        'link'  => 'crm',
        'views' => [
            [
                'pattern' => 'crm',
                'action'  => $guard(fn () => [
                    'component' => 'k-crm-dashboard',
                    'title'     => 'CRM',
                    'props'     => ['canManage' => PanelGate::canManage($kirby->user())],
                ]),
            ],
            [
                'pattern' => 'crm/enquiries',
                'action'  => $guard(fn () => [
                    'component' => 'k-crm-enquiries',
                    'title'     => 'Enquiries',
                    'props'     => ['canManage' => PanelGate::canManage($kirby->user())],
                ]),
            ],
            [
                'pattern' => 'crm/enquiries/(:any)',
                'action'  => $guard(fn (string $uuid) => [
                    'component' => 'k-crm-enquiry',
                    'title'     => 'Enquiry',
                    'props'     => ['uuid' => $uuid, 'canManage' => PanelGate::canManage($kirby->user())],
                ]),
            ],
            [
                'pattern' => 'crm/contacts',
                'action'  => $guard(fn () => [
                    'component' => 'k-crm-contacts',
                    'title'     => 'Contacts',
                    'props'     => [],
                ]),
            ],
            [
                'pattern' => 'crm/pipeline',
                'action'  => $guard(fn () => [
                    'component' => 'k-crm-pipeline',
                    'title'     => 'Pipeline',
                    'props'     => ['canManage' => PanelGate::canManage($kirby->user())],
                ]),
            ],
            [
                'pattern' => 'crm/tasks',
                'action'  => $guard(fn () => [
                    'component' => 'k-crm-tasks',
                    'title'     => 'Tasks',
                    'props'     => ['canManage' => PanelGate::canManage($kirby->user())],
                ]),
            ],
            [
                'pattern' => 'crm/mail',
                'action'  => $guard(fn () => [
                    'component' => 'k-crm-mail',
                    'title'     => 'Email delivery',
                    'props'     => ['canSend' => PanelGate::canSendEmail($kirby->user())],
                ]),
            ],
        ],
    ];
};
