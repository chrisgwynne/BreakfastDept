<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

/**
 * Verifies a Stripe webhook signature against the RAW request body and decodes
 * the event, exactly as Stripe's own libraries do.
 *
 * The `Stripe-Signature` header carries a timestamp (`t=`) and one or more
 * scheme signatures (`v1=`). We recompute HMAC-SHA256 of "{t}.{payload}" with
 * the endpoint's signing secret and compare in constant time, and reject stale
 * timestamps outside a tolerance window (replay protection). Signature failures
 * throw — an unverified event is NEVER processed.
 */
final class StripeWebhook
{
    /** Default replay tolerance, in seconds (matches Stripe's default). */
    private const TOLERANCE = 300;

    public function __construct(
        private readonly string $signingSecret,
        private readonly int $tolerance = self::TOLERANCE,
    ) {
    }

    /**
     * Verify the signature and return the decoded event.
     *
     * @param int|null $now injectable clock for deterministic tests
     * @return array<string,mixed>
     */
    public function constructEvent(string $payload, string $signatureHeader, ?int $now = null): array
    {
        if ($this->signingSecret === '') {
            throw new PaymentException(500, 'Webhook signing secret is not configured.');
        }
        $parsed = $this->parseHeader($signatureHeader);
        $timestamp = $parsed['t'];
        if ($timestamp <= 0 || $parsed['v1'] === []) {
            throw new PaymentException(400, 'Invalid webhook signature header.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->signingSecret);
        $matched = false;
        foreach ($parsed['v1'] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new PaymentException(400, 'Webhook signature verification failed.');
        }

        $now ??= time();
        if ($this->tolerance > 0 && abs($now - (int) $timestamp) > $this->tolerance) {
            throw new PaymentException(400, 'Webhook timestamp is outside the tolerance window.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new PaymentException(400, 'Webhook payload is not valid JSON.');
        }

        return $event;
    }

    /**
     * @return array{t:string,v1:list<string>}
     */
    private function parseHeader(string $header): array
    {
        $t = '';
        $v1 = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't') {
                $t = $value;
            } elseif ($key === 'v1') {
                $v1[] = $value;
            }
        }

        return ['t' => $t, 'v1' => $v1];
    }
}
