<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Immutable HTTP response used by the mail HTTP client.
 */
final class HttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $error = null,
        public readonly array $headers = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
