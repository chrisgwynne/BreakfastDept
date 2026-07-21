<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * Estimates reading time from text content at a configurable words-per-minute
 * rate. Used for journal articles.
 */
final class ReadingTime
{
    public static function minutes(string $text, int $wpm = 220): int
    {
        $plain = trim(strip_tags($text));

        if ($plain === '') {
            return 0;
        }

        $words = preg_split('/\s+/', $plain) ?: [];
        $count = count(array_filter($words, static fn ($w): bool => $w !== ''));

        return max(1, (int) ceil($count / max(1, $wpm)));
    }
}
