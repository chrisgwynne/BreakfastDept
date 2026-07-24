<?php

declare(strict_types=1);

namespace Breakfast\Platform\Operations;

use RuntimeException;

/** A safe, client-facing automation error carrying an HTTP status. */
final class AutomationException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
