<?php

declare(strict_types=1);

namespace Breakfast\Platform\Security;

use Kirby\Cms\User;

/**
 * Server-side permission checks for the Client Previews feature.
 *
 * Every action is gated HERE and re-checked in each admin API route / area —
 * hiding a button in the UI is never the access control. Admins always pass;
 * every other role must be granted the specific `breakfast.previews.<action>`
 * permission in its role blueprint. Defaults to denied.
 *
 * Granular actions (matching the product spec):
 *   view, create, upload, validate, publish, changeSlug, managePassword,
 *   manageExpiry, viewAnalytics, rollback, disable, archive, delete,
 *   emailDraft, systemManage
 */
final class PreviewGate
{
    public const CATEGORY = 'breakfast.previews';

    public const ACTIONS = [
        'view', 'create', 'upload', 'validate', 'publish', 'changeSlug',
        'managePassword', 'manageExpiry', 'viewAnalytics', 'rollback',
        'disable', 'archive', 'delete', 'emailDraft', 'systemManage',
    ];

    public static function can(?User $user, string $action): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (in_array($action, self::ACTIONS, true) === false) {
            return false;
        }

        return $user->role()->permissions()->for(self::CATEGORY, $action) === true;
    }

    public static function canView(?User $user): bool
    {
        return self::can($user, 'view');
    }

    public static function canCreate(?User $user): bool
    {
        return self::can($user, 'create');
    }

    public static function canUpload(?User $user): bool
    {
        return self::can($user, 'upload');
    }

    public static function canValidate(?User $user): bool
    {
        return self::can($user, 'validate');
    }

    public static function canPublish(?User $user): bool
    {
        return self::can($user, 'publish');
    }

    public static function canChangeSlug(?User $user): bool
    {
        return self::can($user, 'changeSlug');
    }

    public static function canManagePassword(?User $user): bool
    {
        return self::can($user, 'managePassword');
    }

    public static function canManageExpiry(?User $user): bool
    {
        return self::can($user, 'manageExpiry');
    }

    public static function canViewAnalytics(?User $user): bool
    {
        return self::can($user, 'viewAnalytics');
    }

    public static function canRollback(?User $user): bool
    {
        return self::can($user, 'rollback');
    }

    public static function canDisable(?User $user): bool
    {
        return self::can($user, 'disable');
    }

    public static function canArchive(?User $user): bool
    {
        return self::can($user, 'archive');
    }

    public static function canDelete(?User $user): bool
    {
        return self::can($user, 'delete');
    }

    public static function canEmailDraft(?User $user): bool
    {
        return self::can($user, 'emailDraft');
    }

    public static function canSystemManage(?User $user): bool
    {
        return self::can($user, 'systemManage');
    }
}
