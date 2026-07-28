<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;

/**
 * Authenticates a Hermes request: resolves the credential, verifies the HMAC
 * signature, enforces the replay window (timestamp) and single-use nonce.
 *
 * Required request headers:
 *   X-Hermes-Key        credential id
 *   X-Hermes-Timestamp  unix seconds
 *   X-Hermes-Nonce      unique per request
 *   X-Hermes-Signature  hex HMAC-SHA256 of the canonical string
 *
 * Single-use nonces are held as flat files; a putIfAbsent on the nonce hash is
 * the filesystem equivalent of the old UNIQUE primary key.
 */
final class Authenticator
{
    public function __construct(
        private readonly CredentialStore $store,
        private readonly FileStore $files,
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
        $now = Clock::nowIso();
        // Prune expired nonces opportunistically.
        foreach ($this->files->all('hermes_nonces') as $n) {
            if ((string) ($n['expires_at'] ?? '') < $now) {
                $this->files->delete('hermes_nonces', (string) ($n['uuid'] ?? ''));
            }
        }
        // putIfAbsent on the nonce hash — false means it was already seen (replay).
        return $this->files->putIfAbsent('hermes_nonces', sha1($nonce), [
            'uuid'       => sha1($nonce),
            'nonce'      => $nonce,
            'seen_at'    => $now,
            'expires_at' => Clock::now()->modify('+' . ($this->replayWindow * 2) . ' seconds')->format('c'),
        ]);
    }

    /**
     * Enforce a scope on an authenticated credential.
     */
    public function authorize(Credential $credential, string $scope): bool
    {
        return $credential->hasScope($scope);
    }
}
