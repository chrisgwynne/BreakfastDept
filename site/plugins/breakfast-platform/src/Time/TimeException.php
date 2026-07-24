<?php

declare(strict_types=1);

namespace Breakfast\Platform\Time;

use RuntimeException;

/** A safe, client-facing time-tracking error carrying an HTTP status. */
final class TimeException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
