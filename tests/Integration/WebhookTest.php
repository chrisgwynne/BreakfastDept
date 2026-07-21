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

        $deliveries = $this->platform->db()->all('SELECT * FROM webhook_deliveries');
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

        $this->assertCount(0, $this->platform->db()->all('SELECT * FROM webhook_deliveries'));
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

        $delivery = $this->platform->db()->one('SELECT * FROM webhook_deliveries');
        $wh->deliver($delivery['uuid']);

        $after = $this->platform->db()->one('SELECT * FROM webhook_deliveries WHERE uuid = :u', ['u' => $delivery['uuid']]);
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
        $delivery = $this->platform->db()->one('SELECT * FROM webhook_deliveries');

        try {
            $wh->deliver($delivery['uuid']);
            $this->fail('expected a RuntimeException for retry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed', strtolower($e->getMessage()));
        }

        $endpoint = $this->platform->db()->one('SELECT * FROM webhook_endpoints WHERE uuid = :u', ['u' => $endpointId]);
        $this->assertSame(1, (int) $endpoint['consecutive_fails']);
    }
}
