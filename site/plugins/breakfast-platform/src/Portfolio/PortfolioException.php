<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

use RuntimeException;

/**
 * Domain error for the portfolio module. Carries an HTTP-ish status so the API
 * layer can translate it into a safe response without leaking internals.
 */
final class PortfolioException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode = 'portfolio_error')
    {
        parent::__construct($message);
    }
}
