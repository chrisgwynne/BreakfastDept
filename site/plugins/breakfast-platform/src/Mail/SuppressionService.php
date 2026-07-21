<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use Breakfast\Platform\Crm\ActivityRepository;
use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Email suppression. Marketing and transactional suppression are modelled
 * SEPARATELY: an unsubscribe only stops marketing; a hard bounce / complaint /
 * block / invalid recipient also stops transactional delivery. A CRM edit can
 * never silently resubscribe someone — removal is an explicit, audited action.
 */
final class SuppressionService
{
    public const SCOPE_TRANSACTIONAL = 'transactional';
    public const SCOPE_MARKETING     = 'marketing';

    public function __construct(
        private readonly Database $db,
        private readonly ActivityRepository $activities,
    ) {
    }

    public function isSuppressed(string $email, string $scope): bool
    {
        return $this->db->scalar(
            'SELECT uuid FROM email_suppressions WHERE email_hash = :h AND scope = :s LIMIT 1',
            ['h' => Hash::correlator(strtolower($email)), 's' => $scope]
        ) !== null;
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

        $this->db->run(
            'INSERT INTO email_suppressions (uuid, email_hash, email, scope, reason, contact_uuid, created_at, updated_at)
             VALUES (:uuid, :hash, :email, :scope, :reason, :contact, :now, :now)
             ON CONFLICT(email_hash, scope) DO UPDATE SET reason = :reason, updated_at = :now',
            [
                'uuid' => Uuid::v4(), 'hash' => $hash, 'email' => strtolower($email),
                'scope' => $scope, 'reason' => $reason, 'contact' => $contactUuid, 'now' => $now,
            ]
        );

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
        $this->db->run(
            'DELETE FROM email_suppressions WHERE email_hash = :h AND scope = :s',
            ['h' => Hash::correlator(strtolower($email)), 's' => $scope]
        );

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
        return (int) $this->db->scalar('SELECT COUNT(*) FROM email_suppressions WHERE scope = :s', ['s' => $scope]);
    }
}
