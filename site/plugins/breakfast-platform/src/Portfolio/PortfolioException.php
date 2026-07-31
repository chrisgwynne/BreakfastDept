<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

/** A portfolio-authoring failure carrying an HTTP status + safe message. */
final class PortfolioException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
