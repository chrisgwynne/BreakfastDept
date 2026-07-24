<?php

declare(strict_types=1);

namespace Breakfast\Platform\Retainers;

use RuntimeException;

/** A safe, client-facing retainer error carrying an HTTP status. */
final class RetainerException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
