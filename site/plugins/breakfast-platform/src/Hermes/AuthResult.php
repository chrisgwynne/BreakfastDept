<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

/**
 * Result of authenticating a Hermes request.
 */
final class AuthResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?Credential $credential = null,
        public readonly ?string $error = null,
        public readonly int $status = 401
    ) {
    }

    public static function success(Credential $credential): self
    {
        return new self(true, $credential);
    }

    public static function failure(string $error, int $status = 401): self
    {
        return new self(false, null, $error, $status);
    }
}
