<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Persistence for outbound_messages and email_events.
 */
final class OutboundMessageRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Persist the durable record of a message we are about to queue.
     */
    public function create(MailMessage $message, string $provider): string
    {
        $now = Clock::nowIso();
        $recipient = $message->primaryRecipient();

        $this->db->run(
            'INSERT INTO outbound_messages (
                uuid, job_uuid, provider, message_type, contact_uuid, company_uuid,
                enquiry_uuid, opportunity_uuid, task_uuid, sender_email, recipient_email,
                recipient_hash, subject, template_id, params_snapshot, tags, idempotency_key,
                status, attempts, queued_at, created_at, updated_at
             ) VALUES (
                :uuid, NULL, :provider, :type, :contact, :company, :enquiry, :opportunity, :task,
                :sender, :recipient, :rhash, :subject, :template, :params, :tags, :idem,
                :status, 0, :now, :now, :now
             )',
            [
                'uuid'       => $message->uuid,
                'provider'   => $provider,
                'type'       => $message->messageType,
                'contact'    => $message->contactUuid,
                'company'    => $message->companyUuid,
                'enquiry'    => $message->enquiryUuid,
                'opportunity' => $message->opportunityUuid,
                'task'       => $message->taskUuid,
                'sender'     => $message->sender->email,
                'recipient'  => $recipient->email,
                'rhash'      => Hash::correlator($recipient->email),
                'subject'    => mb_substr($message->subject, 0, 300),
                'template'   => $message->templateId,
                'params'     => $this->redactParams($message->params),
                'tags'       => json_encode(array_values($message->tags)) ?: null,
                'idem'       => $message->idempotencyKey,
                'status'     => 'queued',
                'now'        => $now,
            ]
        );

        return $message->uuid;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM outbound_messages WHERE uuid = :u', ['u' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findByProviderMessageId(string $providerMessageId): ?array
    {
        return $this->db->one(
            'SELECT * FROM outbound_messages WHERE provider_message_id = :p ORDER BY created_at DESC LIMIT 1',
            ['p' => $providerMessageId]
        );
    }

    public function markProcessing(string $uuid): void
    {
        $this->db->run(
            "UPDATE outbound_messages SET status = 'processing', attempts = attempts + 1, updated_at = :now WHERE uuid = :u",
            ['now' => Clock::nowIso(), 'u' => $uuid]
        );
    }

    public function markAccepted(string $uuid, ?string $providerMessageId, ?int $code): void
    {
        $now = Clock::nowIso();
        $this->db->run(
            "UPDATE outbound_messages SET status = 'accepted', provider_message_id = :pid,
                    last_response_code = :code, sent_at = :now, last_error = NULL, updated_at = :now
             WHERE uuid = :u",
            ['pid' => $providerMessageId, 'code' => $code, 'now' => $now, 'u' => $uuid]
        );
    }

    public function markTemporaryFailure(string $uuid, string $error, ?int $code): void
    {
        $this->db->run(
            "UPDATE outbound_messages SET status = 'temporary_failure', last_error = :err,
                    last_response_code = :code, updated_at = :now WHERE uuid = :u",
            ['err' => mb_substr($error, 0, 500), 'code' => $code, 'now' => Clock::nowIso(), 'u' => $uuid]
        );
    }

    public function markPermanentFailure(string $uuid, string $error, ?int $code): void
    {
        $now = Clock::nowIso();
        $this->db->run(
            "UPDATE outbound_messages SET status = 'permanent_failure', last_error = :err,
                    last_response_code = :code, failed_at = :now, updated_at = :now WHERE uuid = :u",
            ['err' => mb_substr($error, 0, 500), 'code' => $code, 'now' => $now, 'u' => $uuid]
        );
    }

    public function markSuppressed(string $uuid): void
    {
        $now = Clock::nowIso();
        $this->db->run(
            "UPDATE outbound_messages SET status = 'suppressed', failed_at = :now, last_error = 'recipient_suppressed', updated_at = :now WHERE uuid = :u",
            ['now' => $now, 'u' => $uuid]
        );
    }

    /**
     * Apply a delivery-state transition from a webhook event, respecting
     * terminal states (a late "delivered" must not downgrade a hard bounce, and
     * duplicates are harmless).
     */
    public function applyEventState(string $uuid, string $canonicalEvent, string $eventAt): void
    {
        $row = $this->find($uuid);
        if ($row === null) {
            return;
        }

        $current = (string) $row['status'];
        $now     = Clock::nowIso();

        // Terminal negative states never get downgraded by later positives.
        $terminalNegative = ['hard_bounced', 'blocked', 'invalid_recipient', 'spam_complaint'];
        if (in_array($current, $terminalNegative, true) && in_array($canonicalEvent, ['delivered', 'sent', 'opened', 'clicked'], true)) {
            return;
        }

        $set  = ['updated_at = :now'];
        $params = ['now' => $now, 'u' => $uuid, 'evt' => $eventAt];

        switch ($canonicalEvent) {
            case 'delivered':
                if ($current !== 'delivered') {
                    $set[] = "status = 'delivered'";
                }
                $set[] = 'delivered_at = COALESCE(delivered_at, :evt)';
                break;
            case 'opened':
                $set[] = 'first_opened_at = COALESCE(first_opened_at, :evt)';
                $set[] = 'last_opened_at = :evt';
                break;
            case 'clicked':
                $set[] = 'first_clicked_at = COALESCE(first_clicked_at, :evt)';
                $set[] = 'last_clicked_at = :evt';
                break;
            case 'soft_bounce':
                $set[] = 'soft_bounced_at = COALESCE(soft_bounced_at, :evt)';
                if (in_array($current, ['queued', 'processing', 'accepted'], true)) {
                    $set[] = "status = 'soft_bounced'";
                }
                break;
            case 'hard_bounce':
                $set[] = "status = 'hard_bounced'";
                $set[] = 'hard_bounced_at = COALESCE(hard_bounced_at, :evt)';
                $set[] = 'failed_at = COALESCE(failed_at, :evt)';
                break;
            case 'blocked':
                $set[] = "status = 'blocked'";
                $set[] = 'blocked_at = COALESCE(blocked_at, :evt)';
                break;
            case 'invalid_email':
                $set[] = "status = 'invalid_recipient'";
                $set[] = 'failed_at = COALESCE(failed_at, :evt)';
                break;
            case 'spam':
                $set[] = "status = 'spam_complaint'";
                $set[] = 'spam_at = COALESCE(spam_at, :evt)';
                break;
            case 'unsubscribed':
                $set[] = 'unsubscribed_at = COALESCE(unsubscribed_at, :evt)';
                break;
            default:
                return;
        }

        $this->db->run('UPDATE outbound_messages SET ' . implode(', ', $set) . ' WHERE uuid = :u', $params);
    }

    /**
     * Record an email event idempotently. Returns false if it was a duplicate.
     *
     * @param array<string,mixed> $metadata
     */
    public function recordEvent(
        ?string $outboundUuid,
        string $provider,
        ?string $providerEventId,
        ?string $providerMessageId,
        string $canonicalEvent,
        string $recipient,
        string $dedupeFingerprint,
        ?string $eventAt,
        array $metadata = []
    ): bool {
        try {
            $this->db->run(
                'INSERT INTO email_events (
                    uuid, outbound_uuid, provider, provider_event_id, provider_message_id,
                    event_type, recipient_hash, metadata, event_at, received_at, dedupe_fingerprint
                 ) VALUES (:uuid, :out, :prov, :peid, :pmid, :type, :rhash, :meta, :evt, :recv, :fp)',
                [
                    'uuid'  => Uuid::v4(),
                    'out'   => $outboundUuid,
                    'prov'  => $provider,
                    'peid'  => $providerEventId,
                    'pmid'  => $providerMessageId,
                    'type'  => $canonicalEvent,
                    'rhash' => Hash::correlator($recipient),
                    'meta'  => json_encode($metadata, JSON_UNESCAPED_SLASHES) ?: '{}',
                    'evt'   => $eventAt,
                    'recv'  => Clock::nowIso(),
                    'fp'    => $dedupeFingerprint,
                ]
            );
        } catch (\PDOException) {
            return false; // duplicate fingerprint
        }

        return true;
    }

    /**
     * @param array{status?:string,type?:string,limit?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $where  = ['1 = 1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[]          = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['type'])) {
            $where[]        = 'message_type = :type';
            $params['type'] = $filters['type'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 100);

        return $this->db->all(
            'SELECT * FROM outbound_messages WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :l',
            $params
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function eventsFor(string $outboundUuid): array
    {
        return $this->db->all(
            'SELECT * FROM email_events WHERE outbound_uuid = :u ORDER BY received_at ASC',
            ['u' => $outboundUuid]
        );
    }

    public function recentFailureCount(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM outbound_messages WHERE status IN ('permanent_failure','hard_bounced','blocked','invalid_recipient','spam_complaint')"
        );
    }

    /** Prune email events older than the retention window (days). */
    public function pruneEvents(int $olderThanDays): int
    {
        $cutoff = Clock::now()->modify('-' . $olderThanDays . ' days')->format('c');

        return $this->db->run('DELETE FROM email_events WHERE received_at < :c', ['c' => $cutoff])->rowCount();
    }

    /** @param array<string,scalar> $params */
    private function redactParams(array $params): ?string
    {
        if ($params === []) {
            return null;
        }

        $json = json_encode($params, JSON_UNESCAPED_SLASHES);

        return $json === false ? null : mb_substr($json, 0, 2000);
    }
}
