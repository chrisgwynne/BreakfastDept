<?php

declare(strict_types=1);

namespace Breakfast\Platform\Calendar;

use RuntimeException;

/**
 * A safe, client-facing calendar error carrying an HTTP status and, optionally,
 * per-field validation messages.
 */
final class CalendarException extends RuntimeException
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
