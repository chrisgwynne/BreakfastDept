<?php

declare(strict_types=1);

namespace Breakfast\Platform\ChangeRequests;

use RuntimeException;

/** A safe, client-facing change-request error carrying an HTTP status. */
final class ChangeRequestException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
