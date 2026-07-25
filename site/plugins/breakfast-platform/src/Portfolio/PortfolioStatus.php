<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

/**
 * The portfolio publication state machine.
 *
 *   draft → internal_review → ready → scheduled → published
 *
 * with alternate states changes_requested, unpublished and archived. Transitions
 * are server-enforced: {@see canTransition()} is the single source of truth and
 * invalid moves are rejected in the service, never trusted from the client.
 *
 * Visibility is derived, not free: only `published` is publicly accessible;
 * `ready` and `scheduled` may be previewed by an authenticated admin but are
 * never indexed; everything else is private.
 */
final class PortfolioStatus
{
    public const DRAFT             = 'draft';
    public const INTERNAL_REVIEW   = 'internal_review';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const READY             = 'ready';
    public const SCHEDULED         = 'scheduled';
    public const PUBLISHED         = 'published';
    public const UNPUBLISHED       = 'unpublished';
    public const ARCHIVED          = 'archived';

    /** @var list<string> */
    public const ALL = [
        self::DRAFT, self::INTERNAL_REVIEW, self::CHANGES_REQUESTED, self::READY,
        self::SCHEDULED, self::PUBLISHED, self::UNPUBLISHED, self::ARCHIVED,
    ];

    /**
     * Allowed transitions, keyed by the current status. Publishing (→published)
     * additionally requires editorial validation, enforced by the service.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        self::DRAFT             => [self::INTERNAL_REVIEW, self::READY, self::ARCHIVED],
        self::INTERNAL_REVIEW   => [self::CHANGES_REQUESTED, self::READY, self::DRAFT, self::ARCHIVED],
        self::CHANGES_REQUESTED => [self::INTERNAL_REVIEW, self::READY, self::DRAFT, self::ARCHIVED],
        self::READY             => [self::SCHEDULED, self::PUBLISHED, self::CHANGES_REQUESTED, self::DRAFT, self::ARCHIVED],
        self::SCHEDULED         => [self::PUBLISHED, self::READY, self::CHANGES_REQUESTED, self::ARCHIVED],
        self::PUBLISHED         => [self::UNPUBLISHED, self::ARCHIVED],
        self::UNPUBLISHED       => [self::READY, self::PUBLISHED, self::DRAFT, self::ARCHIVED],
        self::ARCHIVED          => [self::DRAFT, self::UNPUBLISHED], // restore
    ];

    /** Statuses whose current public snapshot is served to visitors. */
    public const PUBLIC_STATUSES = [self::PUBLISHED];

    /** Statuses an authenticated admin may preview (never indexed). */
    public const PREVIEWABLE_STATUSES = [self::READY, self::SCHEDULED, self::PUBLISHED, self::CHANGES_REQUESTED, self::INTERNAL_REVIEW, self::DRAFT, self::UNPUBLISHED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function isPublic(string $status): bool
    {
        return in_array($status, self::PUBLIC_STATUSES, true);
    }

    /** The stored `visibility` value implied by a status. */
    public static function visibilityFor(string $status): string
    {
        if ($status === self::PUBLISHED) {
            return 'public';
        }
        if (in_array($status, [self::READY, self::SCHEDULED], true)) {
            return 'preview';
        }

        return 'private';
    }
}
