<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

/**
 * The complete set of Hermes permission scopes. Every endpoint declares the
 * single scope it requires; the authenticator enforces it server-side.
 */
final class Scopes
{
    public const CONTENT_READ  = 'content:read';
    public const CONTENT_DRAFT = 'content:draft';
    public const CRM_SUMMARY   = 'crm:summary';
    public const CRM_READ      = 'crm:read';
    public const CRM_NOTES     = 'crm:notes';
    public const CRM_TASKS     = 'crm:tasks';
    public const CRM_CLASSIFY  = 'crm:classify';
    public const CRM_EXPORT    = 'crm:export';
    public const WEBHOOKS_TEST = 'webhooks:test';

    // Client Previews — read/summarise/analyse and DRAFT only. Hermes may never
    // upload files, publish, change a URL, disable/reveal a password, delete,
    // access server paths, override validation or send an invitation. A preview
    // Hermes creates is always an unpublished draft awaiting human review.
    public const PREVIEWS_SUMMARY = 'previews:summary';
    public const PREVIEWS_READ    = 'previews:read';
    public const PREVIEWS_ANALYSE = 'previews:analyse';
    public const PREVIEWS_DRAFT   = 'previews:draft';

    // Calendar — Hermes may CREATE a tentative internal event (follow-up, reminder,
    // proposed call) for human review. It may never invite external attendees,
    // email clients, cancel/reschedule confirmed meetings, or delete events.
    public const CALENDAR_CREATE = 'calendar:events:create';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::CONTENT_READ,
            self::CONTENT_DRAFT,
            self::CRM_SUMMARY,
            self::CRM_READ,
            self::CRM_NOTES,
            self::CRM_TASKS,
            self::CRM_CLASSIFY,
            self::CRM_EXPORT,
            self::WEBHOOKS_TEST,
            self::PREVIEWS_SUMMARY,
            self::PREVIEWS_READ,
            self::PREVIEWS_ANALYSE,
            self::PREVIEWS_DRAFT,
            self::CALENDAR_CREATE,
        ];
    }

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }
}
