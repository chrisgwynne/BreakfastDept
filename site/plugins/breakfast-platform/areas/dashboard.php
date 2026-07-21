<?php

declare(strict_types=1);

/**
 * Breakfast Admin — Dashboard area.
 *
 * The first screen after login (set as `panel.home`). Branded, task-oriented and
 * built from the permission-guarded `breakfast/admin/overview` API. Every logged-
 * in user can open it; the payload itself is shaped by the user's permissions, so
 * a Writer never receives CRM figures and only admins receive system health.
 */

return function ($kirby) {
    return [
        'label' => 'Dashboard',
        'icon'  => 'home',
        'menu'  => true,
        'link'  => 'dashboard',
        'views' => [
            [
                'pattern' => 'dashboard',
                'action'  => function () use ($kirby) {
                    $user = $kirby->user();

                    return [
                        'component' => 'k-breakfast-dashboard',
                        'title'     => 'Dashboard',
                        'props'     => [
                            'greetingName' => $user !== null ? (string) ($user->name()->isNotEmpty() ? $user->name() : $user->email()) : 'there',
                        ],
                    ];
                },
            ],
        ],
    ];
};
