<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

use Breakfast\Platform\Queue\Queue;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Outbound webhook system.
 *
 * dispatch() is called from application code (e.g. the form pipeline). It NEVER
 * performs HTTP inline — it records a signed, versioned delivery per subscribed
 * endpoint and enqueues a queue job. The queue worker later calls deliver(),
 * which does the HTTP POST with retries/backoff (handled by the queue) and
 * disables endpoints after repeated failures.
 */
final class WebhookDispatcher
{
    public const SCHEMA_VERSION = '1';

    private const DISABLE_AFTER = 10;

    /** @var null|callable(string,string,array<string,string>):array{status:int,error:?string} */
    private static mixed $httpClient = null;

    public function __construct(
        private readonly Database $db,
        private readonly Queue $queue,
        private readonly string $signingSecret
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function dispatch(string $eventType, array $data): void
    {
        $endpoints = $this->activeEndpointsFor($eventType);

        foreach ($endpoints as $endpoint) {
            $eventUuid = Uuid::v4();
            $payload   = [
                'id'             => $eventUuid,
                'type'           => $eventType,
                'schema_version' => self::SCHEMA_VERSION,
                'created_at'     => Clock::nowIso(),
                'data'           => $data,
            ];

            $deliveryUuid = Uuid::v4();
            $this->db->run(
                'INSERT INTO webhook_deliveries
                    (uuid, endpoint_uuid, event_uuid, event_type, schema_version, payload, status, attempts, created_at)
                 VALUES (:uuid, :endpoint, :event, :type, :ver, :payload, :status, 0, :created)',
                [
                    'uuid'     => $deliveryUuid,
                    'endpoint' => $endpoint['uuid'],
                    'event'    => $eventUuid,
                    'type'     => $eventType,
                    'ver'      => self::SCHEMA_VERSION,
                    'payload'  => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
                    'status'   => 'pending',
                    'created'  => Clock::nowIso(),
                ]
            );

            // Enqueue — delivery happens in the worker, never in this request.
            $this->queue->push('webhook.deliver', ['delivery_uuid' => $deliveryUuid], 'webhook:' . $deliveryUuid);
        }
    }

    /**
     * Perform the actual HTTP delivery for one delivery row. Called by the queue
     * worker. Throws on failure so the queue applies backoff/retry.
     */
    public function deliver(string $deliveryUuid): void
    {
        $delivery = $this->db->one('SELECT * FROM webhook_deliveries WHERE uuid = :u', ['u' => $deliveryUuid]);

        if ($delivery === null || $delivery['status'] === 'delivered') {
            return;
        }

        $endpoint = $this->db->one('SELECT * FROM webhook_endpoints WHERE uuid = :u', ['u' => $delivery['endpoint_uuid']]);
        if ($endpoint === null || (int) $endpoint['active'] !== 1) {
            $this->db->run(
                "UPDATE webhook_deliveries SET status = 'failed', last_error = 'endpoint_inactive' WHERE uuid = :u",
                ['u' => $deliveryUuid]
            );

            return;
        }

        $body      = (string) $delivery['payload'];
        $timestamp = (string) Clock::timestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $this->signingSecret);

        $headers = [
            'Content-Type'          => 'application/json',
            'X-Breakfast-Event'     => (string) $delivery['event_type'],
            'X-Breakfast-Event-Id'  => (string) $delivery['event_uuid'],
            'X-Breakfast-Timestamp' => $timestamp,
            'X-Breakfast-Signature' => $signature,
            'X-Breakfast-Schema'    => (string) $delivery['schema_version'],
        ];

        $result = $this->post((string) $endpoint['url'], $body, $headers);
        $attempts = (int) $delivery['attempts'] + 1;

        if ($result['status'] >= 200 && $result['status'] < 300) {
            $this->db->run(
                "UPDATE webhook_deliveries SET status = 'delivered', attempts = :a, response_code = :c, delivered_at = :now WHERE uuid = :u",
                ['a' => $attempts, 'c' => $result['status'], 'now' => Clock::nowIso(), 'u' => $deliveryUuid]
            );
            $this->db->run(
                'UPDATE webhook_endpoints SET consecutive_fails = 0, updated_at = :now WHERE uuid = :u',
                ['now' => Clock::nowIso(), 'u' => $endpoint['uuid']]
            );

            return;
        }

        // Record the failed attempt and possibly disable the endpoint.
        $this->db->run(
            "UPDATE webhook_deliveries SET attempts = :a, response_code = :c, last_error = :e WHERE uuid = :u",
            ['a' => $attempts, 'c' => $result['status'] ?: null, 'e' => $result['error'] ?? 'http_error', 'u' => $deliveryUuid]
        );

        $fails = (int) $endpoint['consecutive_fails'] + 1;
        if ($fails >= self::DISABLE_AFTER) {
            $this->db->run(
                "UPDATE webhook_endpoints SET consecutive_fails = :f, active = 0, disabled_reason = 'repeated_failures', updated_at = :now WHERE uuid = :u",
                ['f' => $fails, 'now' => Clock::nowIso(), 'u' => $endpoint['uuid']]
            );
        } else {
            $this->db->run(
                'UPDATE webhook_endpoints SET consecutive_fails = :f, updated_at = :now WHERE uuid = :u',
                ['f' => $fails, 'now' => Clock::nowIso(), 'u' => $endpoint['uuid']]
            );
        }

        // Signal the queue to retry.
        throw new \RuntimeException('Webhook delivery failed: HTTP ' . $result['status']);
    }

    /**
     * Register (or update) an outbound endpoint.
     *
     * @param list<string> $events
     */
    public function registerEndpoint(string $label, string $url, array $events): string
    {
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();

        $this->db->run(
            'INSERT INTO webhook_endpoints (uuid, label, url, events, active, consecutive_fails, created_at, updated_at)
             VALUES (:uuid, :label, :url, :events, 1, 0, :now, :now)',
            [
                'uuid'   => $uuid,
                'label'  => $label,
                'url'    => $url,
                'events' => json_encode(array_values($events)) ?: '["*"]',
                'now'    => $now,
            ]
        );

        return $uuid;
    }

    /** Re-enqueue a delivery for redelivery from the Panel. */
    public function redeliver(string $deliveryUuid): void
    {
        $this->db->run(
            "UPDATE webhook_deliveries SET status = 'pending', last_error = NULL WHERE uuid = :u",
            ['u' => $deliveryUuid]
        );
        $this->queue->push('webhook.deliver', ['delivery_uuid' => $deliveryUuid]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function activeEndpointsFor(string $eventType): array
    {
        $rows = $this->db->all('SELECT * FROM webhook_endpoints WHERE active = 1');
        $out  = [];

        foreach ($rows as $row) {
            $events = json_decode((string) $row['events'], true);
            $events = is_array($events) ? $events : ['*'];

            if (in_array('*', $events, true) || in_array($eventType, $events, true)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,error:?string}
     */
    private function post(string $url, string $body, array $headers): array
    {
        if (self::$httpClient !== null) {
            return (self::$httpClient)($url, $body, $headers);
        }

        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch) ?: null;
        curl_close($ch);

        return ['status' => $status, 'error' => $error];
    }

    /** @internal test seam */
    public static function useHttpClient(?callable $client): void
    {
        self::$httpClient = $client;
    }
}
