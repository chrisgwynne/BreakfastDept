<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portal;

use RuntimeException;

/** A safe, client-facing portal error carrying an HTTP status. */
final class PortalException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
