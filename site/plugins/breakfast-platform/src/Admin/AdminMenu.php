<?php

declare(strict_types=1);

namespace Breakfast\Platform\Admin;

use Breakfast\Platform\Security\PanelGate;
use Breakfast\Platform\Security\PreviewGate;
use Kirby\Cms\User;

/**
 * Defines the Breakfast Admin primary navigation, replacing Kirby's default
 * Panel menu entirely.
 *
 * The order and grouping are fixed here; visibility of every entry is gated by a
 * server-side permission closure. Gating the menu is a UX convenience only — each
 * destination area/route re-checks permission on the server. Entries that are not
 * standalone areas (Work, Services, Leads, Tasks, …) are rendered as links into
 * the relevant area so editors get a branded, task-oriented nav.
 */
final class AdminMenu
{
    /**
     * Returns the closure Kirby calls for the `panel.menu` option.
     *
     * @return \Closure(\Kirby\Cms\App):array<int|string,mixed>
     */
    public static function config(): \Closure
    {
        return static function (\Kirby\Cms\App $kirby): array {
            return self::entries($kirby->user());
        };
    }

    /**
     * @return array<int|string,mixed>
     */
    public static function entries(?User $user): array
    {
        $content = static fn (): bool => self::canContent($user);
        $crm     = static fn (): bool => PanelGate::canAccess($user);
        $mail    = static fn (): bool => PanelGate::canViewEmailDelivery($user);
        $preview = static fn (): bool => PreviewGate::canView($user);
        $admin   = static fn (): bool => $user !== null && $user->isAdmin();

        return [
            // -- Everyday work ------------------------------------------------
            'dashboard' => ['label' => 'Dashboard', 'icon' => 'home', 'link' => 'dashboard'],
            'site'      => ['label' => 'Website', 'icon' => 'home', 'menu' => $content],
            'work'      => self::link('Work', 'layout', 'pages/work', $content),
            'services'  => self::link('Services', 'stack', 'pages/services', $content),
            'journal'   => self::link('Journal', 'book', 'pages/journal', $content),
            '-',

            // -- Sales & relationships ---------------------------------------
            'leads'     => self::link('Leads', 'email', 'crm/enquiries', $crm),
            'crm'       => ['label' => 'CRM', 'icon' => 'users', 'menu' => $crm],
            'previews'  => ['label' => 'Client Previews', 'icon' => 'preview', 'menu' => $preview],
            'tasks'     => self::link('Tasks', 'check', 'crm/tasks', $crm),
            'email'     => self::link('Email', 'email', 'crm/mail', $mail),
            '-',

            // -- Content operations ------------------------------------------
            'media'     => self::link('Media', 'image', 'pages/media', $content),
            'forms'     => self::link('Forms', 'form', 'crm/enquiries', $crm),
            'reports'   => self::link('Reports', 'chart', 'ops/reports', $crm),
            'settings'  => self::link('Settings', 'settings', 'site', $content),
            '-',

            // -- Administration (admin only) ---------------------------------
            'users'        => ['label' => 'Users', 'icon' => 'users', 'menu' => $admin],
            'integrations' => self::link('Integrations', 'live', 'ops', $admin),
            'brevo'        => self::link('Brevo', 'email', 'ops/mail', $admin),
            'hermes'       => self::link('Hermes', 'bolt', 'ops/hermes', $admin),
            'queue'        => self::link('Queue', 'refresh', 'ops/queue', $admin),
            'system'       => ['label' => 'System', 'icon' => 'settings', 'menu' => $admin],
            'audit'        => self::link('Audit', 'protected', 'ops/audit', $admin),
        ];
    }

    /**
     * A branded menu link into an existing area/view, gated by $visible.
     *
     * @param \Closure():bool $visible
     * @return array<string,mixed>
     */
    private static function link(string $label, string $icon, string $link, \Closure $visible): array
    {
        return [
            'label' => $label,
            'icon'  => $icon,
            'link'  => $link,
            'menu'  => $visible,
        ];
    }

    /**
     * Whether the user may see content-management entries (Website, Work, …).
     * Editors and admins qualify; CRM-only roles do not.
     */
    private static function canContent(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->role()->permissions()->for('access', 'site') === true;
    }
}
