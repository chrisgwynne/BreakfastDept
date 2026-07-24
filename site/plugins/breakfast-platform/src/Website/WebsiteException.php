<?php

declare(strict_types=1);

namespace Breakfast\Platform\Website;

use RuntimeException;

/**
 * A safe, client-facing website-editing error carrying an HTTP status and,
 * optionally, per-field validation messages. Never leaks internal state.
 */
final class WebsiteException extends RuntimeException
{
    /**
     * @param array<string,string> $fields
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
