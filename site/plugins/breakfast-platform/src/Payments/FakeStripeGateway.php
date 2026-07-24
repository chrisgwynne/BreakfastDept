<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

/**
 * In-process fake of the Stripe gateway for browser (Playwright) tests. Returns
 * deterministic Checkout Session / Account / Refund objects without any network,
 * so the real payment-link + reconciliation code paths run under the single
 * threaded dev server. NEVER used in production — {@see PaymentsService::client()}
 * only selects it when BF_STRIPE_MOCK=1 and the site is non-production.
 */
final class FakeStripeGateway implements StripeGateway
{
    public function __construct(private readonly string $siteUrl = '')
    {
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        $id = 'cs_fake_' . bin2hex(random_bytes(8));

        return [
            'id'             => $id,
            'object'         => 'checkout.session',
            'url'            => rtrim($this->siteUrl, '/') . '/stripe-mock/hosted/' . $id,
            'payment_status' => 'unpaid',
        ];
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return ['id' => $id, 'object' => 'checkout.session', 'payment_status' => 'unpaid'];
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        return ['id' => 're_fake_' . bin2hex(random_bytes(8)), 'object' => 'refund', 'status' => 'succeeded'];
    }

    public function retrieveAccount(): array
    {
        return ['id' => 'acct_fake', 'business_profile' => ['name' => 'Breakfast Test'], 'email' => 'test@breakfast'];
    }
}
