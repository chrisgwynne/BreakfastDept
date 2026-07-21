<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A tiny injectable clock so time-dependent logic (rate limiting, replay
 * windows, queue leases, task due dates) is deterministic under test.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        if (self::$frozen instanceof DateTimeImmutable) {
            return self::$frozen;
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** ISO-8601 UTC timestamp, e.g. 2026-07-21T09:30:00+00:00 */
    public static function nowIso(): string
    {
        return self::now()->format('c');
    }

    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }

    /** @internal test seam */
    public static function freeze(string $iso): void
    {
        self::$frozen = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    /** @internal test seam */
    public static function unfreeze(): void
    {
        self::$frozen = null;
    }
}
