<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Hermes\WebhookDispatcher;
use Breakfast\Tests\Support\PlatformTestCase;

final class WebhookTest extends PlatformTestCase
{
    protected function tearDown(): void
    {
        WebhookDispatcher::useHttpClient(null);
        parent::tearDown();
    }

    public function testDispatchCreatesDeliveryAndQueuesJobNotSyncHttp(): void
    {
        $wh = $this->platform->webhooks();
        $wh->registerEndpoint('Hermes', 'https://example.test/hook', ['enquiry.created']);

        $wh->dispatch('enquiry.created', ['reference' => 'ENQ-1']);

        $deliveries = $this->platform->fileStore()->all('webhook_deliveries');
        $this->assertCount(1, $deliveries);
        $this->assertSame('pending', $deliveries[0]['status']);

        // A queue job is created — delivery is never synchronous.
        $this->assertSame(1, $this->platform->queue()->pendingCount());
    }

    public function testEndpointNotSubscribedGetsNothing(): void
    {
        $wh = $this->platform->webhooks();
        $wh->registerEndpoint('Other', 'https://example.test/hook', ['task.created']);
        $wh->dispatch('enquiry.created', ['reference' => 'ENQ-2']);

        $this->assertCount(0, $this->platform->fileStore()->all('webhook_deliveries'));
    }

    public function testDeliverSignsAndMarksDelivered(): void
    {
        $captured = null;
        WebhookDispatcher::useHttpClient(function ($url, $body, $headers) use (&$captured) {
            $captured = ['url' => $url, 'body' => $body, 'headers' => $headers];
            return ['status' => 200, 'error' => null];
        });

        $wh = $this->platform->webhooks();
        $wh->registerEndpoint('Hermes', 'https://example.test/hook', ['*']);
        $wh->dispatch('task.created', ['id' => 't1']);

        $delivery = $this->platform->fileStore()->all('webhook_deliveries')[0];
        $wh->deliver($delivery['uuid']);

        $after = $this->platform->fileStore()->find('webhook_deliveries', (string) $delivery['uuid']);
        $this->assertSame('delivered', $after['status']);
        $this->assertArrayHasKey('X-Breakfast-Signature', $captured['headers']);
        $this->assertNotEmpty($captured['headers']['X-Breakfast-Signature']);
        $this->assertSame('task.created', $captured['headers']['X-Breakfast-Event']);
    }

    public function testFailedDeliveryThrowsForRetryAndCountsFailure(): void
    {
        WebhookDispatcher::useHttpClient(fn () => ['status' => 500, 'error' => 'server error']);

        $wh = $this->platform->webhooks();
        $endpointId = $wh->registerEndpoint('Hermes', 'https://example.test/hook', ['*']);
        $wh->dispatch('task.created', ['id' => 't1']);
        $delivery = $this->platform->fileStore()->all('webhook_deliveries')[0];

        try {
            $wh->deliver($delivery['uuid']);
            $this->fail('expected a RuntimeException for retry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed', strtolower($e->getMessage()));
        }

        $endpoint = $this->platform->fileStore()->find('webhook_endpoints', (string) $endpointId);
        $this->assertSame(1, (int) $endpoint['consecutive_fails']);
    }

    /**
     * SSRF guard (audit): a literal internal IP or a non-http(s) scheme is
     * refused at registration, so an operator (or a scoped Hermes caller)
     * cannot point a webhook at internal infrastructure.
     */
    public function testRegisterRejectsInternalAndNonHttpUrls(): void
    {
        $wh = $this->platform->webhooks();
        foreach (['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:9000/x', 'https://[::1]/x', 'file:///etc/passwd', 'gopher://10.0.0.1/'] as $bad) {
            try {
                $wh->registerEndpoint('Bad', $bad, ['*']);
                $this->fail("expected rejection for $bad");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // Nothing was persisted.
        $this->assertCount(0, $this->platform->fileStore()->all('webhook_endpoints'));
    }

    /**
     * Even if a hostile row reaches delivery (host resolving to an internal
     * address), the real HTTP path resolves + validates and blocks the request
     * rather than connecting. We assert the guard returns the blocked marker by
     * driving the resolver through a link-local literal that bypasses the
     * registration guard via the DB directly.
     */
    public function testDeliverBlocksHostResolvingToInternalAddress(): void
    {
        // Insert an endpoint row directly (bypassing registration) with an
        // internal literal host, as a crafted/compromised row would look.
        $wh = $this->platform->webhooks();
        $wh->registerEndpoint('Legit', 'https://example.test/hook', ['*']);
        // Rewrite its URL to an internal target after the fact.
        foreach ($this->platform->fileStore()->all('webhook_endpoints') as $ep) {
            $this->platform->fileStore()->update('webhook_endpoints', (string) $ep['uuid'], static function (array $r): array {
                $r['url'] = 'http://127.0.0.1:9/x';

                return $r;
            });
        }
        $wh->dispatch('task.created', ['id' => 't1']);
        $delivery = $this->platform->fileStore()->all('webhook_deliveries')[0];

        // Real HTTP path (no fake client) must block, not connect → recorded as
        // a failed attempt with the blocked marker, and it throws for retry.
        WebhookDispatcher::useHttpClient(null);
        try {
            $wh->deliver($delivery['uuid']);
            $this->fail('expected a RuntimeException (blocked delivery)');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $after = $this->platform->fileStore()->find('webhook_deliveries', (string) $delivery['uuid']);
        $this->assertSame('blocked_url', $after['last_error']);
        $this->assertNotSame('delivered', $after['status']);
    }
}
