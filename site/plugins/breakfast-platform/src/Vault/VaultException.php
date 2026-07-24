<?php

declare(strict_types=1);

namespace Breakfast\Platform\Vault;

use RuntimeException;

/**
 * A safe, client-facing vault error carrying an HTTP status. NEVER contains a
 * secret value — messages are redacted by construction.
 */
final class VaultException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
