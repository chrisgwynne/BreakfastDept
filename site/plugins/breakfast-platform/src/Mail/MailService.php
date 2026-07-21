<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use Breakfast\Platform\Crm\ActivityRepository;
use Breakfast\Platform\Queue\Queue;
use RuntimeException;

/**
 * Thrown to signal the queue that a send should be retried (temporary/unknown
 * provider outcome). Permanent failures do NOT throw — the job completes and the
 * outbound record is marked failed for an operator to see.
 */
final class RetryableMailException extends RuntimeException
{
}

/**
 * Application mail service. The ONLY way the app sends transactional email:
 * build a MailMessage, queue it (durable, never inline), and let the worker
 * deliver it through the configured provider. Enforces suppression, records the
 * outbound message + provider id, and writes CRM activity for meaningful states.
 */
final class MailService
{
    public function __construct(
        private readonly OutboundMessageRepository $outbound,
        private readonly MailProvider $provider,
        private readonly SuppressionService $suppressions,
        private readonly Queue $queue,
        private readonly ActivityRepository $activities,
    ) {
    }

    /**
     * Persist the tracking record and enqueue the send. Returns the message uuid.
     * Idempotent on the message uuid / idempotency key: a duplicate queue call
     * will not create a second application message.
     */
    public function queue(MailMessage $message): string
    {
        // Guard against a duplicate application message (exactly-once creation).
        if ($this->outbound->find($message->uuid) !== null) {
            return $message->uuid;
        }

        $this->outbound->create($message, $this->provider->name());

        $this->queue->push(
            'mail.send',
            ['message' => $message->toArray()],
            $message->idempotencyKey ?? ('mail:' . $message->uuid)
        );

        return $message->uuid;
    }

    /**
     * Deliver a message from a queue payload. Called by the worker. Throws
     * RetryableMailException for retryable outcomes; returns normally otherwise.
     *
     * @param array<string,mixed> $payload
     */
    public function deliver(array $payload): void
    {
        $message = MailMessage::fromArray((array) ($payload['message'] ?? []));
        $row     = $this->outbound->find($message->uuid);

        // Already terminal (delivered/accepted/failed/suppressed) → idempotent no-op.
        if ($row !== null && in_array((string) $row['status'], ['accepted', 'delivered', 'permanent_failure', 'suppressed'], true)) {
            return;
        }

        // Transactional suppression: never send to a suppressed recipient.
        if ($this->suppressions->canSendTransactional($message->primaryRecipient()->email) === false) {
            $this->outbound->markSuppressed($message->uuid);

            return;
        }

        $this->outbound->markProcessing($message->uuid);
        $result = $this->provider->send($message);

        match ($result->outcome) {
            MailOutcome::Accepted => $this->onAccepted($message, $result),
            MailOutcome::PermanentFailure => $this->onPermanent($message, $result),
            MailOutcome::TemporaryFailure, MailOutcome::UnknownOutcome => $this->onRetryable($message, $result),
        };
    }

    private function onAccepted(MailMessage $message, MailResult $result): void
    {
        $this->outbound->markAccepted($message->uuid, $result->providerMessageId, $result->statusCode);
        $this->logActivity($message, 'email.sent', 'Email sent: ' . $message->subject);
    }

    private function onPermanent(MailMessage $message, MailResult $result): void
    {
        $this->outbound->markPermanentFailure($message->uuid, (string) $result->error, $result->statusCode);
        $this->logActivity($message, 'email.failed', 'Email permanently failed: ' . $message->subject);
        // No throw → the queue job completes (no pointless retries of a 4xx).
    }

    private function onRetryable(MailMessage $message, MailResult $result): void
    {
        $this->outbound->markTemporaryFailure($message->uuid, (string) $result->error, $result->statusCode);

        throw new RetryableMailException((string) $result->error);
    }

    private function logActivity(MailMessage $message, string $type, string $summary): void
    {
        foreach ([['contact', $message->contactUuid], ['enquiry', $message->enquiryUuid], ['opportunity', $message->opportunityUuid]] as [$entity, $uuid]) {
            if ($uuid !== null) {
                $this->activities->record((string) $entity, $uuid, $type, $summary, 'system', null, ['message_uuid' => $message->uuid]);
            }
        }
    }
}
