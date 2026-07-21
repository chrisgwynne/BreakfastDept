<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Security\RateLimiter;
use Breakfast\Platform\Support\Clock;

/**
 * Password protection for a preview.
 *
 * Passwords are stored only as a strong one-way hash (password_hash). Access is
 * rate-limited per preview+client with a GENERIC failure (no "wrong password vs
 * unknown preview" distinction). A successful unlock issues a short-lived,
 * host-only session token that is cryptographically BOUND to the current
 * password hash, so changing or clearing the password instantly invalidates
 * every outstanding session.
 */
final class PreviewPasswordService
{
    private const MIN_LENGTH        = 8;
    private const MAX_ATTEMPTS      = 8;   // per preview + client
    private const MAX_ATTEMPTS_ALL  = 40;  // per preview, all clients (anti-distributed)
    private const WINDOW_SECONDS    = 300;
    private const SESSION_TTL       = 3600; // 1 hour

    public function __construct(
        private readonly PreviewRepository $previews,
        private readonly RateLimiter $limiter,
        private readonly string $secret,
    ) {
    }

    /**
     * Set (or replace) a preview password. Marks the preview password-protected
     * and switches visibility to password. Returns nothing sensitive.
     */
    public function setPassword(string $previewUuid, string $plaintext, ?string $actor): bool
    {
        $plaintext = trim($plaintext);
        if (strlen($plaintext) < self::MIN_LENGTH) {
            return false;
        }
        $hash = password_hash($plaintext, PASSWORD_DEFAULT);
        $this->previews->update($previewUuid, [
            'password_hash'      => $hash,
            'password_protected' => 1,
            'visibility'         => PreviewStatus::VISIBILITY_PASSWORD,
            'updated_by'         => $actor,
        ]);

        return true;
    }

    public function clearPassword(string $previewUuid, ?string $actor): void
    {
        $this->previews->update($previewUuid, [
            'password_hash'      => null,
            'password_protected' => 0,
            'visibility'         => PreviewStatus::VISIBILITY_UNLISTED,
            'updated_by'         => $actor,
        ]);
    }

    /**
     * Attempt a password unlock. Rate-limited and generic on failure.
     *
     * @param array<string,mixed> $preview
     * @return array{ok:bool,token?:string,error?:string,retry_after?:int}
     */
    public function attempt(array $preview, string $plaintext, ?string $ip): array
    {
        $previewUuid = (string) $preview['uuid'];
        $bucket      = 'preview_pw:' . $previewUuid . ':' . substr(sha1(($ip ?? '') . $this->secret), 0, 16);

        // Per-client limit AND a per-preview global ceiling, so rotating source
        // IPs cannot reset the whole budget (distributed brute force).
        $rate   = $this->limiter->hit($bucket, self::MAX_ATTEMPTS, self::WINDOW_SECONDS);
        $global = $this->limiter->hit('preview_pw_all:' . $previewUuid, self::MAX_ATTEMPTS_ALL, self::WINDOW_SECONDS);
        if ($rate['allowed'] === false || $global['allowed'] === false) {
            $retry = max((int) $rate['retry_after'], (int) $global['retry_after']);

            return ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry];
        }

        $hash = is_string($preview['password_hash'] ?? null) ? (string) $preview['password_hash'] : '';
        if ($hash === '' || password_verify($plaintext, $hash) === false) {
            // Deliberately generic — same response for wrong password.
            return ['ok' => false, 'error' => 'invalid'];
        }

        return ['ok' => true, 'token' => $this->mintToken($previewUuid, $hash)];
    }

    /**
     * Verify a session token against a preview's CURRENT password hash. A token
     * minted for a previous hash (password changed/cleared) no longer validates.
     *
     * @param array<string,mixed> $preview
     */
    public function verifyToken(array $preview, string $token): bool
    {
        $hash = is_string($preview['password_hash'] ?? null) ? (string) $preview['password_hash'] : '';
        if ($hash === '') {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$expiry, $sig] = $parts;
        if (ctype_digit($expiry) === false || (int) $expiry < Clock::timestamp()) {
            return false;
        }

        $expected = $this->signature((string) $preview['uuid'], $hash, (int) $expiry);

        return hash_equals($expected, $sig);
    }

    private function mintToken(string $previewUuid, string $hash): string
    {
        $expiry = Clock::timestamp() + self::SESSION_TTL;

        return $expiry . '.' . $this->signature($previewUuid, $hash, $expiry);
    }

    private function signature(string $previewUuid, string $hash, int $expiry): string
    {
        // Binding the token to the password hash means rotating the password
        // invalidates outstanding sessions automatically.
        return hash_hmac('sha256', $previewUuid . '|' . $expiry . '|' . $hash, $this->secret);
    }
}
