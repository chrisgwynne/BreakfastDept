<?php

declare(strict_types=1);

namespace Breakfast\Platform\Proposals;

use RuntimeException;

/**
 * A safe, client-facing proposals error carrying an HTTP status. Messages are
 * non-technical and never leak internal state.
 */
final class ProposalException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
