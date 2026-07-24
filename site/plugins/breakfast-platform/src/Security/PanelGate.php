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

    /**
     * Hermes integration console. Viewing the integration (overview, credentials
     * inventory, scopes, activity, diagnostics) requires the CRM 'manage' grant;
     * operational actions that touch the integration — running a connection
     * self-test, generating a credential's env line — are admin-only. Secrets are
     * never exposed regardless. Enforced server-side on every Hermes route.
     */
    public static function canViewHermes(?User $user): bool
    {
        return self::canManage($user);
    }

    public static function canManageHermes(?User $user): bool
    {
        return $user !== null && $user->isAdmin();
    }

    /**
     * Invoicing. Viewing and managing invoices both require the CRM 'manage'
     * grant (they contain financial + client billing data). Enforced server-side
     * on every invoice route.
     */
    public static function canViewInvoices(?User $user): bool
    {
        return self::canManage($user);
    }

    public static function canManageInvoices(?User $user): bool
    {
        return self::canManage($user);
    }

    /**
     * Granular invoice-PDF permissions. Downloading/previewing a generated
     * document maps to invoice 'view'; generating a draft preview or first
     * issued PDF maps to invoice 'manage'; regenerating an ISSUED invoice's
     * document (which supersedes the current version while preserving the
     * original) is admin-only, because it changes the official record set.
     * Enforced server-side on every PDF route.
     */
    public static function canViewInvoicePdf(?User $user): bool
    {
        return self::canViewInvoices($user);
    }

    public static function canGenerateInvoicePdf(?User $user): bool
    {
        return self::canManageInvoices($user);
    }

    public static function canRegenerateInvoicePdf(?User $user): bool
    {
        return $user !== null && $user->isAdmin();
    }

    /**
     * Website content. Viewing the overview needs admin access; editing drafts
     * and publishing to the live site both require the 'manage' grant. Enforced
     * server-side on every website route.
     */
    public static function canViewWebsite(?User $user): bool
    {
        return self::canAccess($user);
    }

    public static function canEditWebsite(?User $user): bool
    {
        return self::canManage($user);
    }

    public static function canPublishWebsite(?User $user): bool
    {
        return self::canManage($user);
    }

    /**
     * Brevo integration settings. Viewing, managing (key + config) and testing
     * all require the 'manage' grant — this area exposes email deliverability
     * configuration and a masked credential hint. Enforced server-side.
     */
    public static function canViewBrevo(?User $user): bool
    {
        return self::canManage($user);
    }

    public static function canManageBrevo(?User $user): bool
    {
        return self::canManage($user);
    }

    public static function canTestBrevo(?User $user): bool
    {
        return self::canManage($user);
    }

    /**
     * Calendar. Viewing needs admin access; creating/editing/deleting events
     * require the 'manage' grant. Enforced server-side on every calendar route.
     */
    public static function canViewCalendar(?User $user): bool
    {
        return self::canAccess($user);
    }

    public static function canManageCalendar(?User $user): bool
    {
        return self::canManage($user);
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
