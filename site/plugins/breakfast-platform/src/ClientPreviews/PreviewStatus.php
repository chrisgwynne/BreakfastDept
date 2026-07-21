<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Canonical Client Previews state vocabulary — lifecycle status, access
 * visibility and per-version state. Centralised so the repository, services,
 * admin API and preview-origin responder all agree on the exact strings.
 */
final class PreviewStatus
{
    // Preview lifecycle status.
    public const DRAFT             = 'draft';
    public const PROCESSING        = 'processing';
    public const ACTIVE            = 'active';
    public const DISABLED          = 'disabled';
    public const EXPIRED           = 'expired';
    public const ARCHIVED          = 'archived';
    public const FAILED_VALIDATION = 'failed_validation';

    /** @var list<string> */
    public const STATUSES = [
        self::DRAFT, self::PROCESSING, self::ACTIVE, self::DISABLED,
        self::EXPIRED, self::ARCHIVED, self::FAILED_VALIDATION,
    ];

    // Access visibility (only meaningful while ACTIVE).
    public const VISIBILITY_PUBLIC   = 'public';
    public const VISIBILITY_UNLISTED = 'unlisted';
    public const VISIBILITY_PASSWORD = 'password';

    /** @var list<string> */
    public const VISIBILITIES = [
        self::VISIBILITY_PUBLIC, self::VISIBILITY_UNLISTED, self::VISIBILITY_PASSWORD,
    ];

    // Version state.
    public const V_UPLOADED          = 'uploaded';
    public const V_VALIDATING        = 'validating';
    public const V_VALIDATION_FAILED = 'validation_failed';
    public const V_READY             = 'ready';
    public const V_PUBLISHED         = 'published';
    public const V_SUPERSEDED        = 'superseded';
    public const V_ARCHIVED          = 'archived';

    /** @var list<string> */
    public const VERSION_STATES = [
        self::V_UPLOADED, self::V_VALIDATING, self::V_VALIDATION_FAILED,
        self::V_READY, self::V_PUBLISHED, self::V_SUPERSEDED, self::V_ARCHIVED,
    ];

    /**
     * A preview that is currently reachable by a visitor (subject to visibility
     * and password). Anything else renders a neutral status page.
     */
    public static function isServable(string $status): bool
    {
        return $status === self::ACTIVE;
    }
}
