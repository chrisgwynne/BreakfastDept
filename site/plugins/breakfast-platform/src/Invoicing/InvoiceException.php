<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

use RuntimeException;

/**
 * A safe, client-facing invoicing error carrying an HTTP status. Messages are
 * non-technical and never leak internal state.
 */
final class InvoiceException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
