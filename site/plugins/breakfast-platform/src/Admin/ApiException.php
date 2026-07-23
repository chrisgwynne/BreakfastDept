<?php

declare(strict_types=1);

namespace Breakfast\Platform\Admin;

use RuntimeException;

/**
 * A safe, client-facing API error for the standalone admin API.
 *
 * The message is deliberately non-technical and never discloses internal state
 * (account existence, which credential check failed, database internals). The
 * HTTP status and optional machine `code` / field errors let the front-end react
 * without ever needing the underlying exception.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string,string> $fields per-field validation messages
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $errorCode = null,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
