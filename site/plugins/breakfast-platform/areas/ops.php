<?php

declare(strict_types=1);

use Breakfast\Platform\Security\PanelGate;

/**
 * Breakfast Admin — Operations area.
 *
 * Consolidates the administration surfaces referenced by the branded nav
 * (Integrations, Brevo, Hermes, Queue, Audit) plus Reports. Operational views
 * are admin-only; Reports additionally opens to any CRM-access role. Each view
 * re-checks permission server-side and renders the shared `k-breakfast-ops`
 * component with a `section` prop; the component reads from admin API routes.
 */

return function ($kirby) {
    $denied = static fn (string $t): array => [
        'component' => 'k-error-view',
        'title'     => $t,
        'props'     => ['error' => 'You do not have permission to view this.', 'layout' => 'outside'],
    ];

    $adminOnly = static function (callable $view) use ($kirby, $denied) {
        return function (...$args) use ($kirby, $view, $denied) {
            $user = $kirby->user();
            if ($user === null || $user->isAdmin() === false) {
                return $denied('Operations');
            }

            return $view(...$args);
        };
    };

    $reportsGuard = static function (callable $view) use ($kirby, $denied) {
        return function (...$args) use ($kirby, $view, $denied) {
            if (PanelGate::canAccess($kirby->user()) === false) {
                return $denied('Reports');
            }

            return $view(...$args);
        };
    };

    $view = static fn (string $section, string $title): array => [
        'component' => 'k-breakfast-ops',
        'title'     => $title,
        'props'     => ['section' => $section, 'title' => $title],
    ];

    return [
        'label' => 'Operations',
        'icon'  => 'live',
        'menu'  => fn () => $kirby->user()?->isAdmin() === true,
        'link'  => 'ops',
        'views' => [
            ['pattern' => 'ops', 'action' => $adminOnly(fn () => $view('integrations', 'Integrations'))],
            ['pattern' => 'ops/mail', 'action' => $adminOnly(fn () => $view('mail', 'Brevo & email'))],
            ['pattern' => 'ops/hermes', 'action' => $adminOnly(fn () => $view('hermes', 'Hermes'))],
            ['pattern' => 'ops/queue', 'action' => $adminOnly(fn () => $view('queue', 'Queue'))],
            ['pattern' => 'ops/audit', 'action' => $adminOnly(fn () => $view('audit', 'Audit log'))],
            ['pattern' => 'ops/reports', 'action' => $reportsGuard(fn () => $view('reports', 'Reports'))],
        ],
    ];
};
