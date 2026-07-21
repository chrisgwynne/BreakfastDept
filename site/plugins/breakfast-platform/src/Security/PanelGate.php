<?php

declare(strict_types=1);

namespace Breakfast\Platform\Security;

use Kirby\Cms\User;

/**
 * Server-side permission checks for CRM Panel access.
 *
 * Access control is enforced HERE (and in every API route/area), never by
 * merely hiding buttons in the UI. Admins always pass; other roles must have
 * the relevant `breakfast.crm.*` permission granted in their role blueprint.
 */
final class PanelGate
{
    public static function canAccess(?User $user): bool
    {
        return self::allowed($user, 'access');
    }

    public static function canManage(?User $user): bool
    {
        return self::allowed($user, 'manage');
    }

    public static function canExport(?User $user): bool
    {
        return self::allowed($user, 'export');
    }

    /**
     * CRM email permissions. Composing/sending/retrying map to the CRM 'manage'
     * grant (admin + crm-manager); viewing delivery maps to 'access' (adds
     * read-only analyst). Enforced server-side on every email route.
     */
    public static function canComposeEmail(?User $user): bool
    {
        return self::canManage($user);
    }

    public static function canSendEmail(?User $user): bool
    {
        return self::canManage($user);
    }

    public static function canViewEmailDelivery(?User $user): bool
    {
        return self::canAccess($user);
    }

    private static function allowed(?User $user, string $action): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $permissions = $user->role()->permissions();

        // Custom permission category; explicit grant required in the role.
        return $permissions->for('breakfast.platform', $action) === true;
    }
}
