<?php

declare(strict_types=1);

namespace Breakfast\Platform\Queue;

/**
 * Immutable value object for a queued job row.
 */
final class Job
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $uuid,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $maxAttempts
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);

        return new self(
            (string) $row['uuid'],
            (string) $row['type'],
            is_array($payload) ? $payload : [],
            (int) ($row['attempts'] ?? 0),
            (int) ($row['max_attempts'] ?? 5)
        );
    }
}
