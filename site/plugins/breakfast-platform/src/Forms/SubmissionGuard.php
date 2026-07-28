<?php

declare(strict_types=1);

namespace Breakfast\Platform\Forms;

use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Security\RateLimiter;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;

/**
 * Anti-abuse checks applied before a submission is accepted:
 * honeypot, time-to-submit, IP/email rate limiting and duplicate detection.
 *
 * Every failure returns a machine reason for private logging while the caller
 * shows the visitor a single generic message. Duplicate fingerprints are held
 * as flat files keyed on the fingerprint hash.
 */
final class SubmissionGuard
{
    private const COLLECTION = 'form_fingerprints';

    public function __construct(
        private readonly FileStore $store,
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

        $existing = $this->store->find(self::COLLECTION, $fingerprint);
        if ($existing !== null && (string) ($existing['expires_at'] ?? '') > Clock::nowIso()) {
            return true;
        }

        $this->store->put(self::COLLECTION, [
            'uuid'       => $fingerprint,
            'fingerprint' => $fingerprint,
            'form_type'  => $formType,
            'created_at' => Clock::nowIso(),
            'expires_at' => Clock::now()->modify('+' . $windowSeconds . ' seconds')->format('c'),
        ]);

        return false;
    }

    /** Sweep expired fingerprints. */
    public function pruneFingerprints(): int
    {
        $now     = Clock::nowIso();
        $removed = 0;
        foreach ($this->store->all(self::COLLECTION) as $row) {
            if ((string) ($row['expires_at'] ?? '') < $now) {
                if ($this->store->delete(self::COLLECTION, (string) ($row['uuid'] ?? ''))) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
