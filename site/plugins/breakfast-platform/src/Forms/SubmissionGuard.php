<?php

declare(strict_types=1);

namespace Breakfast\Platform\Forms;

use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Security\RateLimiter;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;

/**
 * Anti-abuse checks applied before a submission is accepted:
 * honeypot, time-to-submit, IP/email rate limiting and duplicate detection.
 *
 * Every failure returns a machine reason for private logging while the caller
 * shows the visitor a single generic message.
 */
final class SubmissionGuard
{
    public function __construct(
        private readonly Database $db,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    /**
     * A filled honeypot field means a bot. The field name is arbitrary and the
     * input is visually hidden + aria-hidden in the template.
     */
    public function honeypotTripped(string $honeypotValue): bool
    {
        return trim($honeypotValue) !== '';
    }

    /**
     * Reject submissions that arrive implausibly fast (bots) or with a missing
     * / tampered timestamp. `renderedAt` is a signed unix timestamp embedded in
     * the form when it was rendered.
     */
    public function timingSuspicious(?int $renderedAt, int $minSeconds = 3, int $maxSeconds = 3600): bool
    {
        if ($renderedAt === null || $renderedAt <= 0) {
            return true;
        }

        $elapsed = Clock::timestamp() - $renderedAt;

        return $elapsed < $minSeconds || $elapsed > $maxSeconds;
    }

    /**
     * IP- and email-based rate limiting. Returns null when allowed, or a reason
     * string when blocked.
     */
    public function rateLimited(string $formType, ?string $ip, ?string $email): ?string
    {
        $ipHash = Hash::ip($ip);

        // Per IP: 5 submissions / 10 minutes.
        $ipCheck = $this->rateLimiter->hit('form:' . $formType . ':ip:' . $ipHash, 5, 600);
        if ($ipCheck['allowed'] === false) {
            return 'ip_rate_limit';
        }

        if ($email !== null && $email !== '') {
            // Per email: 3 submissions / hour.
            $emailHash = Hash::correlator(strtolower($email));
            $emailCheck = $this->rateLimiter->hit('form:' . $formType . ':email:' . $emailHash, 3, 3600);
            if ($emailCheck['allowed'] === false) {
                return 'email_rate_limit';
            }
        }

        return null;
    }

    /**
     * Duplicate detection: hash the meaningful content and reject an identical
     * submission within a short window (default 10 minutes).
     *
     * @param array<string,mixed> $content
     */
    public function isDuplicate(string $formType, array $content, int $windowSeconds = 600): bool
    {
        ksort($content);
        $fingerprint = hash('sha256', $formType . '|' . json_encode($content));

        $existing = $this->db->scalar(
            'SELECT fingerprint FROM form_fingerprints WHERE fingerprint = :f AND expires_at > :now',
            ['f' => $fingerprint, 'now' => Clock::nowIso()]
        );

        if ($existing !== null) {
            return true;
        }

        $this->db->run(
            'INSERT INTO form_fingerprints (fingerprint, form_type, created_at, expires_at)
             VALUES (:f, :t, :c, :e)
             ON CONFLICT(fingerprint) DO UPDATE SET expires_at = :e',
            [
                'f' => $fingerprint,
                't' => $formType,
                'c' => Clock::nowIso(),
                'e' => Clock::now()->modify('+' . $windowSeconds . ' seconds')->format('c'),
            ]
        );

        return false;
    }

    /** Sweep expired fingerprints. */
    public function pruneFingerprints(): int
    {
        return $this->db->run(
            'DELETE FROM form_fingerprints WHERE expires_at < :now',
            ['now' => Clock::nowIso()]
        )->rowCount();
    }
}
