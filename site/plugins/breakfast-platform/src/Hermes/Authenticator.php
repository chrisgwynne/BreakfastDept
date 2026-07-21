<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;

/**
 * Authenticates a Hermes request: resolves the credential, verifies the HMAC
 * signature, enforces the replay window (timestamp) and single-use nonce.
 *
 * Required request headers:
 *   X-Hermes-Key        credential id
 *   X-Hermes-Timestamp  unix seconds
 *   X-Hermes-Nonce      unique per request
 *   X-Hermes-Signature  hex HMAC-SHA256 of the canonical string
 */
final class Authenticator
{
    public function __construct(
        private readonly CredentialStore $store,
        private readonly Database $db,
        private readonly int $replayWindow = 300
    ) {
    }

    /**
     * @param array<string,string> $headers lowercase header name => value
     */
    public function authenticate(string $method, string $path, string $body, array $headers): AuthResult
    {
        $keyId     = $headers['x-hermes-key'] ?? '';
        $timestamp = $headers['x-hermes-timestamp'] ?? '';
        $nonce     = $headers['x-hermes-nonce'] ?? '';
        $signature = $headers['x-hermes-signature'] ?? '';

        if ($keyId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return AuthResult::failure('missing_auth_headers');
        }

        $credential = $this->store->find($keyId);
        if ($credential === null) {
            return AuthResult::failure('unknown_credential');
        }

        // Replay window on the timestamp.
        if (ctype_digit($timestamp) === false) {
            return AuthResult::failure('bad_timestamp');
        }

        $skew = abs(Clock::timestamp() - (int) $timestamp);
        if ($skew > $this->replayWindow) {
            return AuthResult::failure('timestamp_out_of_window');
        }

        // Signature check (constant time).
        $canonical = Signer::canonical($method, $path, $timestamp, $nonce, $body);
        if (Signer::verify($credential->secret(), $canonical, $signature) === false) {
            return AuthResult::failure('bad_signature');
        }

        // Single-use nonce: reject replays inside the window.
        if ($this->consumeNonce($nonce) === false) {
            return AuthResult::failure('nonce_reused');
        }

        return AuthResult::success($credential);
    }

    /**
     * Store the nonce; return false if it was already seen (replay). Expired
     * nonces are pruned opportunistically.
     */
    private function consumeNonce(string $nonce): bool
    {
        $this->db->run('DELETE FROM hermes_nonces WHERE expires_at < :now', ['now' => Clock::nowIso()]);

        try {
            $this->db->run(
                'INSERT INTO hermes_nonces (nonce, seen_at, expires_at) VALUES (:n, :s, :e)',
                [
                    'n' => $nonce,
                    's' => Clock::nowIso(),
                    'e' => Clock::now()->modify('+' . ($this->replayWindow * 2) . ' seconds')->format('c'),
                ]
            );
        } catch (\PDOException $e) {
            // Primary-key conflict → nonce already used.
            return false;
        }

        return true;
    }

    /**
     * Enforce a scope on an authenticated credential.
     */
    public function authorize(Credential $credential, string $scope): bool
    {
        return $credential->hasScope($scope);
    }
}
