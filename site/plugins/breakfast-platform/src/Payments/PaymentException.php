<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

use RuntimeException;

/**
 * A safe, client-facing payments error carrying an HTTP status. Never leaks
 * provider secrets or internal state.
 */
final class PaymentException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
