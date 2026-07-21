<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

/**
 * HMAC-SHA256 request signing for Hermes.
 *
 * The signature covers a canonical string built from the HTTP method, request
 * path, timestamp, nonce and a hash of the raw request body. This binds the
 * signature to the exact request and, together with the timestamp + nonce,
 * prevents replay. Verification uses hash_equals for constant-time comparison.
 */
final class Signer
{
    public const ALGO = 'sha256';

    /**
     * Build the canonical string that gets signed. Deterministic and
     * unambiguous — each component is newline-separated and the body is hashed
     * so binary/large bodies do not bloat the string.
     */
    public static function canonical(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        $bodyHash = hash(self::ALGO, $body);

        return implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);
    }

    public static function sign(string $secret, string $canonical): string
    {
        return hash_hmac(self::ALGO, $canonical, $secret);
    }

    /**
     * Constant-time verification. `$provided` is the hex signature from the
     * request header.
     */
    public static function verify(string $secret, string $canonical, string $provided): bool
    {
        $expected = self::sign($secret, $canonical);

        // hash_equals is constant-time; length differences are handled safely.
        return hash_equals($expected, $provided);
    }
}
