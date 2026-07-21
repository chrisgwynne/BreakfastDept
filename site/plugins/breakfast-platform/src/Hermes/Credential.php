<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

/**
 * A single Hermes integration credential: an identifier, its granted scopes and
 * its shared secret. Secrets originate from environment variables only and are
 * never logged or serialised.
 */
final class Credential
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $id,
        public readonly array $scopes,
        private readonly string $secret
    ) {
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** Never expose the secret in debug output. */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'scopes' => $this->scopes, 'secret' => '***'];
    }
}
