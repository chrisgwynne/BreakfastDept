<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use Breakfast\Platform\Crm\ActivityRepository;
use Breakfast\Platform\Support\Uuid;

/**
 * Receives Brevo transactional webhook events. Authenticated independently of
 * the Panel (shared secret or HTTP Basic over HTTPS), size-limited, content-type
 * checked, deduplicated, and stored BEFORE any downstream side effect. Never
 * reveals whether a CRM contact exists; logs only safe metadata.
 */
final class BrevoWebhook
{
    private const MAX_BODY_BYTES = 65_536;

    /** Brevo event name → internal canonical event. */
    private const MAP = [
        'request'        => 'sent',
        'sent'           => 'sent',
        'delivered'      => 'delivered',
        'soft_bounce'    => 'soft_bounce',
        'hard_bounce'    => 'hard_bounce',
        'blocked'        => 'blocked',
        'invalid_email'  => 'invalid_email',
        'deferred'       => 'deferred',
        'error'          => 'error',
        'opened'         => 'opened',
        'unique_opened'  => 'opened',
        'click'          => 'clicked',
        'clicks'         => 'clicked',
        'spam'           => 'spam',
        'complaint'      => 'spam',
        'unsubscribed'   => 'unsubscribed',
        'unsubscribe'    => 'unsubscribed',
    ];

    /**
     * @param array{secret?:string,basicUser?:string,basicPassword?:string} $auth
     */
    public function __construct(
        private readonly OutboundMessageRepository $outbound,
        private readonly SuppressionService $suppressions,
        private readonly ActivityRepository $activities,
        private readonly array $auth,
    ) {
    }

    /**
     * @param array<string,string> $headers lowercase header => value
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $body, array $headers, ?string $contentType): array
    {
        $requestId = Uuid::v4();

        if ($this->authenticate($headers) === false) {
            return ['status' => 401, 'body' => ['error' => 'unauthorized', 'request_id' => $requestId]];
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ['status' => 413, 'body' => ['error' => 'payload_too_large', 'request_id' => $requestId]];
        }

        if ($contentType !== null && str_contains(strtolower($contentType), 'application/json') === false) {
            return ['status' => 415, 'body' => ['error' => 'unsupported_media_type', 'request_id' => $requestId]];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === false) {
            return ['status' => 400, 'body' => ['error' => 'malformed', 'request_id' => $requestId]];
        }

        // Brevo may send a single event object or a batch array.
        $events = isset($decoded['event']) ? [$decoded] : array_values(array_filter($decoded, 'is_array'));
        $processed = 0;
        foreach ($events as $event) {
            if ($this->processOne($event)) {
                $processed++;
            }
        }

        return ['status' => 200, 'body' => ['ok' => true, 'processed' => $processed, 'request_id' => $requestId]];
    }

    /**
     * @param array<string,mixed> $event
     */
    private function processOne(array $event): bool
    {
        $brevoEvent = strtolower((string) ($event['event'] ?? ''));
        $canonical  = self::MAP[$brevoEvent] ?? null;
        if ($canonical === null) {
            return false;
        }

        $email     = is_string($event['email'] ?? null) ? (string) $event['email'] : '';
        $messageId = $this->extractMessageId($event);
        $eventId   = isset($event['id']) ? (string) $event['id'] : null;
        $eventAt   = $this->extractTimestamp($event);

        if ($email === '') {
            return false;
        }

        // Resolve the outbound message this event belongs to (if we sent it).
        $outboundRow  = $messageId !== null ? $this->outbound->findByProviderMessageId($messageId) : null;
        $outboundUuid = $outboundRow !== null ? (string) $outboundRow['uuid'] : null;
        $contactUuid  = $outboundRow !== null && $outboundRow['contact_uuid'] !== null ? (string) $outboundRow['contact_uuid'] : null;

        // Deduplicate: store the event once. A duplicate is a harmless success.
        $fingerprint = hash('sha256', ($messageId ?? '') . '|' . $brevoEvent . '|' . ($eventAt ?? '') . '|' . $eventId . '|' . strtolower($email));
        $stored = $this->outbound->recordEvent(
            $outboundUuid,
            'brevo',
            $eventId,
            $messageId,
            $canonical,
            $email,
            $fingerprint,
            $eventAt,
            ['event' => $brevoEvent]
        );

        if ($stored === false) {
            return true; // already processed
        }

        // Update delivery state (terminal-safe, out-of-order-safe) + suppression.
        if ($outboundUuid !== null) {
            $this->outbound->applyEventState($outboundUuid, $canonical, $eventAt ?? '');
        }
        $this->suppressions->applyFromEvent($canonical, $email, $contactUuid);

        // Meaningful CRM timeline entries only — never opens/clicks (noise).
        if ($contactUuid !== null && in_array($canonical, ['delivered', 'hard_bounce', 'blocked', 'spam', 'unsubscribed', 'invalid_email'], true)) {
            $this->activities->record(
                'contact',
                $contactUuid,
                'email.' . $canonical,
                'Email ' . str_replace('_', ' ', $canonical),
                'system',
                null,
                ['outbound_uuid' => $outboundUuid]
            );
        }

        return true;
    }

    /** @param array<string,mixed> $event */
    private function extractMessageId(array $event): ?string
    {
        // Keep the id verbatim (Brevo formats the send-response messageId and the
        // webhook message-id identically, e.g. "<uuid@domain>"), so lookups match.
        foreach (['message-id', 'messageId', 'message_id'] as $key) {
            if (isset($event[$key]) && $event[$key] !== '') {
                return (string) $event[$key];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $event */
    private function extractTimestamp(array $event): ?string
    {
        if (isset($event['ts_event']) || isset($event['ts'])) {
            $ts = (int) ($event['ts_event'] ?? $event['ts']);

            return $ts > 0 ? date('c', $ts) : null;
        }
        if (isset($event['date']) && is_string($event['date'])) {
            $t = strtotime($event['date']);

            return $t !== false ? date('c', $t) : null;
        }

        return null;
    }

    /** @param array<string,string> $headers */
    private function authenticate(array $headers): bool
    {
        $secret = (string) ($this->auth['secret'] ?? '');
        $user   = (string) ($this->auth['basicUser'] ?? '');
        $pass   = (string) ($this->auth['basicPassword'] ?? '');

        // If nothing is configured, refuse (fail closed).
        if ($secret === '' && $user === '') {
            return false;
        }

        if ($secret !== '') {
            $provided = (string) ($headers['x-breakfast-webhook-token'] ?? '');
            if ($provided !== '' && hash_equals($secret, $provided)) {
                return true;
            }
        }

        if ($user !== '') {
            $auth = (string) ($headers['authorization'] ?? '');
            if (str_starts_with($auth, 'Basic ')) {
                $decoded = base64_decode(substr($auth, 6), true);
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$u, $p] = explode(':', $decoded, 2);

                    return hash_equals($user, $u) && hash_equals($pass, $p);
                }
            }
        }

        return false;
    }
}
