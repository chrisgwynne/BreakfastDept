<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

/**
 * The small slice of the Stripe API the deposit workflow needs, behind an
 * interface so it can be faked in-process for browser tests (where a single
 * threaded dev server cannot make a live or self-directed HTTP call). Production
 * always uses the real {@see StripeClient}.
 */
interface StripeGateway
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function retrieveCheckoutSession(string $id): array;

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function createRefund(array $params, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function retrieveAccount(): array;
}
