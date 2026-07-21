<?php

declare(strict_types=1);

use Breakfast\Platform\Security\PreviewGate;

/**
 * Breakfast Admin — Client Previews area.
 *
 * The menu entry and every view are gated by PreviewGate::canView server-side;
 * each mutating action re-checks its specific grant in the API. Views render the
 * k-breakfast-previews* components, which read from the permission-guarded
 * breakfast/previews API.
 */

return function ($kirby) {
    $denied = static fn (): array => [
        'component' => 'k-error-view',
        'title'     => 'Client Previews',
        'props'     => ['error' => 'You do not have permission to view Client Previews.', 'layout' => 'outside'],
    ];

    $guard = static function (callable $view) use ($kirby, $denied) {
        return function (...$args) use ($kirby, $view, $denied) {
            if (PreviewGate::canView($kirby->user()) === false) {
                return $denied();
            }

            return $view(...$args);
        };
    };

    $u = static fn () => $kirby->user();

    return [
        'label' => 'Client Previews',
        'icon'  => 'preview',
        'menu'  => fn () => PreviewGate::canView($kirby->user()),
        'link'  => 'previews',
        'views' => [
            [
                'pattern' => 'previews',
                'action'  => $guard(fn () => [
                    'component' => 'k-breakfast-previews',
                    'title'     => 'Client Previews',
                    'props'     => [
                        'canCreate' => PreviewGate::canCreate($u()),
                    ],
                ]),
            ],
            [
                'pattern' => 'previews/(:any)',
                'action'  => $guard(fn (string $uuid) => [
                    'component' => 'k-breakfast-preview',
                    'title'     => 'Preview',
                    'props'     => [
                        'uuid'    => $uuid,
                        'can'     => [
                            'upload'         => PreviewGate::canUpload($u()),
                            'publish'        => PreviewGate::canPublish($u()),
                            'rollback'       => PreviewGate::canRollback($u()),
                            'changeSlug'     => PreviewGate::canChangeSlug($u()),
                            'managePassword' => PreviewGate::canManagePassword($u()),
                            'manageExpiry'   => PreviewGate::canManageExpiry($u()),
                            'disable'        => PreviewGate::canDisable($u()),
                            'archive'        => PreviewGate::canArchive($u()),
                            'delete'         => PreviewGate::canDelete($u()),
                            'emailDraft'     => PreviewGate::canEmailDraft($u()),
                            'analytics'      => PreviewGate::canViewAnalytics($u()),
                            'systemManage'   => PreviewGate::canSystemManage($u()),
                        ],
                    ],
                ]),
            ],
        ],
    ];
};
