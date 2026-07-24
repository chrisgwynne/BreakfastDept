<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use RuntimeException;

/** A safe, client-facing projects error carrying an HTTP status. */
final class ProjectException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
