<?php

declare(strict_types=1);

namespace Breakfast\Platform\Settings;

use RuntimeException;

/**
 * A safe, client-facing settings error carrying an HTTP status and, optionally,
 * per-field validation messages. Never leaks internal state or secret material.
 */
final class SettingsException extends RuntimeException
{
    /**
     * @param array<string,string> $fields
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
