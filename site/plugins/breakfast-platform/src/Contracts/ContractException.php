<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

use RuntimeException;

/**
 * A safe, client-facing contracts error carrying an HTTP status. Messages are
 * non-technical and never leak internal state.
 */
final class ContractException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
