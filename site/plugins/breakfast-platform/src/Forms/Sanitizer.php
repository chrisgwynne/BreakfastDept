<?php

declare(strict_types=1);

namespace Breakfast\Platform\Forms;

/**
 * Input sanitisation helpers for form submissions.
 *
 * Sanitisation is about safe *storage and transport*, not validation. We strip
 * control characters, normalise whitespace, cap lengths, and — critically —
 * neutralise header-injection attempts in any value that could reach an email
 * header (name, email, subject).
 */
final class Sanitizer
{
    public static function text(string $value, int $max = 5000): string
    {
        // Remove control chars except tab/newline; collapse trailing whitespace.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);

        return mb_substr($value, 0, $max);
    }

    /** Single-line value safe for use in an email header. */
    public static function headerSafe(string $value, int $max = 320): string
    {
        // Any CR/LF (or their encoded forms) means header injection — strip them.
        $value = str_ireplace(["\r", "\n", '%0a', '%0d', '\r', '\n'], ' ', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    public static function email(string $value): string
    {
        $value = self::headerSafe($value, 254);

        return strtolower(trim($value));
    }

    /**
     * Recursively sanitise an associative payload for storage.
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public static function payload(array $data, int $max = 5000): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map(
                    static fn ($v): string => self::text((string) $v, 500),
                    $value
                ));
            }

            $clean[(string) $key] = self::text((string) $value, $max);
        }

        return $clean;
    }
}
