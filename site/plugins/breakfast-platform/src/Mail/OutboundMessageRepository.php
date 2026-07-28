<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Flat-file persistence for outbound messages and their delivery events.
 *
 * outbound_messages and email_events are stored as JSON files via FileStore.
 * Event ingestion stays idempotent by keying each event file on a hash of its
 * dedupe fingerprint and writing only if absent — the file-store equivalent of
 * the old UNIQUE(dedupe_fingerprint) constraint.
 */
final class OutboundMessageRepository
{
    private const MESSAGES = 'outbound_messages';
    private const EVENTS   = 'email_events';

    public function __construct(private readonly FileStore $store)
    {
    }

    /**
     * Persist the durable record of a message we are about to queue.
     */
    public function create(MailMessage $message, string $provider): string
    {
        $now       = Clock::nowIso();
        $recipient = $message->primaryRecipient();

        $this->store->put(self::MESSAGES, [
            'uuid'                => $message->uuid,
            'job_uuid'            => null,
            'provider'            => $provider,
            'provider_message_id' => null,
            'message_type'        => $message->messageType,
            'contact_uuid'        => $message->contactUuid,
            'company_uuid'        => $message->companyUuid,
            'enquiry_uuid'        => $message->enquiryUuid,
            'opportunity_uuid'    => $message->opportunityUuid,
            'task_uuid'           => $message->taskUuid,
            'sender_email'        => $message->sender->email,
            'recipient_email'     => $recipient->email,
            'recipient_hash'      => Hash::correlator($recipient->email),
            'subject'             => mb_substr($message->subject, 0, 300),
            'template_id'         => $message->templateId,
            'params_snapshot'     => $this->redactParams($message->params),
            'tags'                => array_values($message->tags),
            'idempotency_key'     => $message->idempotencyKey,
            'status'              => 'queued',
            'attempts'            => 0,
            'last_response_code'  => null,
            'last_error'          => null,
            'queued_at'           => $now,
            'sent_at'             => null,
            'delivered_at'        => null,
            'first_opened_at'     => null,
            'last_opened_at'      => null,
            'first_clicked_at'    => null,
            'last_clicked_at'     => null,
            'soft_bounced_at'     => null,
            'hard_bounced_at'     => null,
            'blocked_at'          => null,
            'spam_at'             => null,
            'unsubscribed_at'     => null,
            'failed_at'           => null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        return $message->uuid;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->store->find(self::MESSAGES, $uuid);
    }

    /** @return array<string,mixed>|null */
    public function findByProviderMessageId(string $providerMessageId): ?array
    {
        $matches = array_filter(
            $this->store->all(self::MESSAGES),
            fn (array $r): bool => (string) ($r['provider_message_id'] ?? '') === $providerMessageId
        );
        usort($matches, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $matches[0] ?? null;
    }

    public function markProcessing(string $uuid): void
    {
        $now = Clock::nowIso();
        $this->mutate($uuid, static function (array $r) use ($now): array {
            $r['status']     = 'processing';
            $r['attempts']   = (int) ($r['attempts'] ?? 0) + 1;
            $r['updated_at'] = $now;

            return $r;
        });
    }

    public function markAccepted(string $uuid, ?string $providerMessageId, ?int $code): void
    {
        $now = Clock::nowIso();
        $this->mutate($uuid, static function (array $r) use ($providerMessageId, $code, $now): array {
            $r['status']              = 'accepted';
            $r['provider_message_id'] = $providerMessageId;
            $r['last_response_code']  = $code;
            $r['sent_at']             = $now;
            $r['last_error']          = null;
            $r['updated_at']          = $now;

            return $r;
        });
    }

    public function markTemporaryFailure(string $uuid, string $error, ?int $code): void
    {
        $now = Clock::nowIso();
        $this->mutate($uuid, static function (array $r) use ($error, $code, $now): array {
            $r['status']             = 'temporary_failure';
            $r['last_error']         = mb_substr($error, 0, 500);
            $r['last_response_code'] = $code;
            $r['updated_at']         = $now;

            return $r;
        });
    }

    public function markPermanentFailure(string $uuid, string $error, ?int $code): void
    {
        $now = Clock::nowIso();
        $this->mutate($uuid, static function (array $r) use ($error, $code, $now): array {
            $r['status']             = 'permanent_failure';
            $r['last_error']         = mb_substr($error, 0, 500);
            $r['last_response_code'] = $code;
            $r['failed_at']          = $now;
            $r['updated_at']         = $now;

            return $r;
        });
    }

    public function markSuppressed(string $uuid): void
    {
        $now = Clock::nowIso();
        $this->mutate($uuid, static function (array $r) use ($now): array {
            $r['status']     = 'suppressed';
            $r['failed_at']  = $now;
            $r['last_error'] = 'recipient_suppressed';
            $r['updated_at'] = $now;

            return $r;
        });
    }

    /**
     * Apply a delivery-state transition from a webhook event, respecting
     * terminal states (a late "delivered" must not downgrade a hard bounce, and
     * duplicates are harmless).
     */
    public function applyEventState(string $uuid, string $canonicalEvent, string $eventAt): void
    {
        $now = Clock::nowIso();
        $this->mutate($uuid, static function (array $r) use ($canonicalEvent, $eventAt, $now): array {
            $current = (string) ($r['status'] ?? '');

            // Terminal negative states never get downgraded by later positives.
            $terminalNegative = ['hard_bounced', 'blocked', 'invalid_recipient', 'spam_complaint'];
            if (in_array($current, $terminalNegative, true) && in_array($canonicalEvent, ['delivered', 'sent', 'opened', 'clicked'], true)) {
                return $r;
            }

            $keepEarliest = static function (array $r, string $field) use ($eventAt): array {
                if (($r[$field] ?? null) === null) {
                    $r[$field] = $eventAt;
                }

                return $r;
            };

            switch ($canonicalEvent) {
                case 'delivered':
                    if ($current !== 'delivered') {
                        $r['status'] = 'delivered';
                    }
                    $r = $keepEarliest($r, 'delivered_at');
                    break;
                case 'opened':
                    $r = $keepEarliest($r, 'first_opened_at');
                    $r['last_opened_at'] = $eventAt;
                    break;
                case 'clicked':
                    $r = $keepEarliest($r, 'first_clicked_at');
                    $r['last_clicked_at'] = $eventAt;
                    break;
                case 'soft_bounce':
                    $r = $keepEarliest($r, 'soft_bounced_at');
                    if (in_array($current, ['queued', 'processing', 'accepted'], true)) {
                        $r['status'] = 'soft_bounced';
                    }
                    break;
                case 'hard_bounce':
                    $r['status'] = 'hard_bounced';
                    $r = $keepEarliest($r, 'hard_bounced_at');
                    $r = $keepEarliest($r, 'failed_at');
                    break;
                case 'blocked':
                    $r['status'] = 'blocked';
                    $r = $keepEarliest($r, 'blocked_at');
                    break;
                case 'invalid_email':
                    $r['status'] = 'invalid_recipient';
                    $r = $keepEarliest($r, 'failed_at');
                    break;
                case 'spam':
                    $r['status'] = 'spam_complaint';
                    $r = $keepEarliest($r, 'spam_at');
                    break;
                case 'unsubscribed':
                    $r = $keepEarliest($r, 'unsubscribed_at');
                    break;
                default:
                    return $r;
            }

            $r['updated_at'] = $now;

            return $r;
        });
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
        // The fingerprint is the uniqueness key; hash it to a filesystem-safe id
        // and use it as the record uuid too, so the filename always equals the
        // uuid (delete-by-uuid works from anywhere, e.g. retention pruning).
        $id = sha1($dedupeFingerprint);

        return $this->store->putIfAbsent(self::EVENTS, $id, [
            'uuid'                => $id,
            'outbound_uuid'       => $outboundUuid,
            'provider'            => $provider,
            'provider_event_id'   => $providerEventId,
            'provider_message_id' => $providerMessageId,
            'event_type'          => $canonicalEvent,
            'recipient_hash'      => Hash::correlator($recipient),
            'metadata'            => $metadata,
            'event_at'            => $eventAt,
            'received_at'         => Clock::nowIso(),
            'dedupe_fingerprint'  => $dedupeFingerprint,
        ]);
    }

    /**
     * @param array{status?:string,type?:string,limit?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $rows = $this->store->all(self::MESSAGES);

        if (!empty($filters['status'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['status'] ?? null) === $filters['status']);
        }
        if (!empty($filters['type'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['message_type'] ?? null) === $filters['type']);
        }

        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, (int) ($filters['limit'] ?? 100));
    }

    /** @return array<int,array<string,mixed>> */
    public function eventsFor(string $outboundUuid): array
    {
        $rows = array_values(array_filter(
            $this->store->all(self::EVENTS),
            fn (array $r): bool => (string) ($r['outbound_uuid'] ?? '') === $outboundUuid
        ));
        usort($rows, static fn ($a, $b) => strcmp((string) ($a['received_at'] ?? ''), (string) ($b['received_at'] ?? '')));

        return $rows;
    }

    public function recentFailureCount(): int
    {
        $failed = ['permanent_failure', 'hard_bounced', 'blocked', 'invalid_recipient', 'spam_complaint'];

        return count(array_filter(
            $this->store->all(self::MESSAGES),
            static fn (array $r): bool => in_array((string) ($r['status'] ?? ''), $failed, true)
        ));
    }

    /** Prune email events older than the retention window (days). */
    public function pruneEvents(int $olderThanDays): int
    {
        $cutoff  = Clock::now()->modify('-' . $olderThanDays . ' days')->format('c');
        $pruned  = 0;

        foreach ($this->store->all(self::EVENTS) as $event) {
            if ((string) ($event['received_at'] ?? '') < $cutoff) {
                $this->store->delete(self::EVENTS, (string) ($event['uuid'] ?? ''));
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Apply a mutation to a stored message if it exists.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $mutator
     */
    private function mutate(string $uuid, callable $mutator): void
    {
        $this->store->update(self::MESSAGES, $uuid, $mutator);
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
