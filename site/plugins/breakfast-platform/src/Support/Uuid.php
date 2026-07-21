<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

use InvalidArgumentException;
use Random\RandomException;

/**
 * RFC 4122 version 4 UUID generation and validation.
 */
final class Uuid
{
    public static function v4(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (RandomException $e) {
            // random_bytes only throws if no CSPRNG is available; that is fatal.
            throw new \RuntimeException('No secure random source available', 0, $e);
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function isValid(string $uuid): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        ) === 1;
    }

    public static function assertValid(string $uuid): string
    {
        if (self::isValid($uuid) === false) {
            throw new InvalidArgumentException('Invalid UUID');
        }

        return strtolower($uuid);
    }
}
