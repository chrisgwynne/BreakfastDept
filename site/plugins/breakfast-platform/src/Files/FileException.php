<?php

declare(strict_types=1);

namespace Breakfast\Platform\Files;

use RuntimeException;

/** A safe, client-facing files error carrying an HTTP status. */
final class FileException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
