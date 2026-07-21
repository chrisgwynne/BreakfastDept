<?php

declare(strict_types=1);

namespace Breakfast\Platform\Security;

/**
 * Keyed hashing for values we must not store in the clear.
 *
 * Raw IP addresses are never persisted. Instead we store a keyed HMAC of the
 * IP so rate limiting and abuse correlation work without retaining PII beyond a
 * short window. The key comes from the environment (falls back to a per-install
 * derived value) so hashes are not portable across installs.
 */
final class Hash
{
    private static ?string $key = null;

    public static function setKey(string $key): void
    {
        self::$key = $key;
    }

    private static function key(): string
    {
        if (self::$key !== null && self::$key !== '') {
            return self::$key;
        }

        $env = getenv('WEBHOOK_SIGNING_SECRET') ?: getenv('APP_URL') ?: 'breakfast-local-key';

        return self::$key = hash('sha256', 'breakfast:' . $env);
    }

    /** Deterministic keyed hash of an IP (or any correlator). */
    public static function ip(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }

        return substr(hash_hmac('sha256', $ip, self::key()), 0, 32);
    }

    /** Truncated correlation hash for analytics (documented short retention). */
    public static function correlator(string $value): string
    {
        return substr(hash_hmac('sha256', $value, self::key()), 0, 16);
    }
}
