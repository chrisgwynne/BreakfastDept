<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use Breakfast\Platform\Crm\ActivityRepository;
use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;

/**
 * Email suppression. Marketing and transactional suppression are modelled
 * SEPARATELY: an unsubscribe only stops marketing; a hard bounce / complaint /
 * block / invalid recipient also stops transactional delivery. A CRM edit can
 * never silently resubscribe someone — removal is an explicit, audited action.
 *
 * Each suppression is one flat-file record keyed on a hash of (email, scope),
 * the filesystem equivalent of the old UNIQUE(email_hash, scope).
 */
final class SuppressionService
{
    private const COLLECTION = 'email_suppressions';

    public const SCOPE_TRANSACTIONAL = 'transactional';
    public const SCOPE_MARKETING     = 'marketing';

    public function __construct(
        private readonly FileStore $store,
        private readonly ActivityRepository $activities,
    ) {
    }

    public function isSuppressed(string $email, string $scope): bool
    {
        return $this->store->exists(self::COLLECTION, $this->id($email, $scope));
    }

    /**
     * Transactional messages may still be sent to marketing-suppressed contacts
     * (a lawful reply), but never to transactionally-suppressed ones.
     */
    public function canSendTransactional(string $email): bool
    {
        return $this->isSuppressed($email, self::SCOPE_TRANSACTIONAL) === false;
    }

    public function suppress(string $email, string $scope, string $reason, ?string $contactUuid = null, string $actor = 'system'): void
    {
        $now  = Clock::nowIso();
        $hash = Hash::correlator(strtolower($email));
        $id   = $this->id($email, $scope);

        // Upsert: preserve the original created_at, refresh reason + updated_at.
        $existing = $this->store->find(self::COLLECTION, $id);
        $this->store->put(self::COLLECTION, [
            'uuid'         => $id,
            'email_hash'   => $hash,
            'email'        => strtolower($email),
            'scope'        => $scope,
            'reason'       => $reason,
            'contact_uuid' => $contactUuid,
            'created_at'   => (string) ($existing['created_at'] ?? $now),
            'updated_at'   => $now,
        ]);

        if ($contactUuid !== null) {
            $this->activities->record(
                'contact',
                $contactUuid,
                'email.suppressed',
                'Email suppressed (' . $scope . '): ' . $reason,
                $actor,
                null,
                ['scope' => $scope, 'reason' => $reason]
            );
        }
    }

    /** Explicit, audited removal — never triggered silently by a CRM edit. */
    public function unsuppress(string $email, string $scope, ?string $contactUuid, string $actor): void
    {
        $this->store->delete(self::COLLECTION, $this->id($email, $scope));

        if ($contactUuid !== null) {
            $this->activities->record(
                'contact',
                $contactUuid,
                'email.unsuppressed',
                'Suppression removed (' . $scope . ')',
                $actor,
                null,
                ['scope' => $scope]
            );
        }
    }

    /**
     * Map a canonical delivery event onto suppression changes.
     */
    public function applyFromEvent(string $canonicalEvent, string $email, ?string $contactUuid): void
    {
        match ($canonicalEvent) {
            'hard_bounce'   => $this->suppress($email, self::SCOPE_TRANSACTIONAL, 'hard_bounce', $contactUuid),
            'invalid_email' => $this->suppress($email, self::SCOPE_TRANSACTIONAL, 'invalid_email', $contactUuid),
            'blocked'       => $this->suppress($email, self::SCOPE_TRANSACTIONAL, 'blocked', $contactUuid),
            'spam'          => $this->suppress($email, self::SCOPE_TRANSACTIONAL, 'spam_complaint', $contactUuid),
            'unsubscribed'  => $this->suppress($email, self::SCOPE_MARKETING, 'unsubscribe', $contactUuid),
            default         => null, // soft bounce, opens, clicks, delivered: no suppression
        };
    }

    public function count(string $scope): int
    {
        return count(array_filter($this->store->all(self::COLLECTION), static fn (array $r): bool => (string) ($r['scope'] ?? '') === $scope));
    }

    /** Deterministic record id for (email, scope). */
    private function id(string $email, string $scope): string
    {
        return sha1(Hash::correlator(strtolower($email)) . '|' . $scope);
    }
}
