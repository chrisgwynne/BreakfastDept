<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

use Breakfast\Platform\Security\SecurityHeaders;

/**
 * Per-request runtime holders that need to be shared across snippets/templates
 * within a single render (notably the CSP nonce, which the response header and
 * any inline script tags must agree on).
 */
final class Runtime
{
    private static ?SecurityHeaders $security = null;

    public static function security(bool $isProduction = true): SecurityHeaders
    {
        return self::$security ??= new SecurityHeaders($isProduction);
    }

    public static function reset(): void
    {
        self::$security = null;
    }
}
