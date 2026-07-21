<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Mail\BrevoWebhook;
use Breakfast\Platform\Mail\EmailAddress;
use Breakfast\Platform\Mail\MailMessage;
use Breakfast\Tests\Support\PlatformTestCase;

final class BrevoWebhookTest extends PlatformTestCase
{
    private function webhook(): BrevoWebhook
    {
        return new BrevoWebhook(
            $this->platform->outbound(),
            $this->platform->suppressions(),
            $this->platform->activities(),
            ['secret' => 'hook-secret']
        );
    }

    private function seedOutbound(string $providerMessageId, string $recipient = 'client@example.co.uk', ?string $contactUuid = null): string
    {
        $msg = new MailMessage(
            messageType: 'crm_reply',
            sender: EmailAddress::create('studio@example.com'),
            to: [EmailAddress::create($recipient)],
            subject: 'Hi',
            html: '<p>Hi</p>',
            contactUuid: $contactUuid,
        );
        $uuid = $this->platform->outbound()->create($msg, 'brevo');
        $this->platform->outbound()->markAccepted($uuid, $providerMessageId, 201);

        return $uuid;
    }

    private function headers(): array
    {
        return ['x-breakfast-webhook-token' => 'hook-secret'];
    }

    public function testRejectsMissingAuth(): void
    {
        $res = $this->webhook()->handle('{"event":"delivered"}', [], 'application/json');
        $this->assertSame(401, $res['status']);
    }

    public function testRejectsOversizedBody(): void
    {
        $res = $this->webhook()->handle(str_repeat('x', 70000), $this->headers(), 'application/json');
        $this->assertSame(413, $res['status']);
    }

    public function testRejectsWrongContentType(): void
    {
        $res = $this->webhook()->handle('{}', $this->headers(), 'text/plain');
        $this->assertSame(415, $res['status']);
    }

    public function testRejectsMalformed(): void
    {
        $res = $this->webhook()->handle('not json', $this->headers(), 'application/json');
        $this->assertSame(400, $res['status']);
    }

    public function testDeliveredUpdatesOutbound(): void
    {
        $uuid = $this->seedOutbound('<msg-1@brevo>');
        $body = json_encode(['event' => 'delivered', 'email' => 'client@example.co.uk', 'message-id' => '<msg-1@brevo>', 'ts_event' => 1700000000]);
        $res  = $this->webhook()->handle($body, $this->headers(), 'application/json');

        $this->assertSame(200, $res['status']);
        $this->assertSame('delivered', $this->platform->outbound()->find($uuid)['status']);
    }

    public function testDuplicateEventIsHarmless(): void
    {
        $this->seedOutbound('<msg-2@brevo>');
        $body = json_encode(['event' => 'delivered', 'email' => 'client@example.co.uk', 'message-id' => '<msg-2@brevo>', 'ts_event' => 1700000000, 'id' => 99]);
        $this->webhook()->handle($body, $this->headers(), 'application/json');
        $this->webhook()->handle($body, $this->headers(), 'application/json');

        $events = $this->platform->db()->all('SELECT * FROM email_events');
        $this->assertCount(1, $events, 'duplicate webhook deliveries are deduplicated');
    }

    public function testHardBounceSuppressesTransactional(): void
    {
        $this->seedOutbound('<msg-3@brevo>', 'bouncer@example.com');
        $body = json_encode(['event' => 'hard_bounce', 'email' => 'bouncer@example.com', 'message-id' => '<msg-3@brevo>', 'ts_event' => 1700000000]);
        $this->webhook()->handle($body, $this->headers(), 'application/json');

        $this->assertFalse($this->platform->suppressions()->canSendTransactional('bouncer@example.com'));
    }

    public function testSpamComplaintSuppresses(): void
    {
        $this->seedOutbound('<msg-4@brevo>', 'complainer@example.com');
        $body = json_encode(['event' => 'spam', 'email' => 'complainer@example.com', 'message-id' => '<msg-4@brevo>', 'ts_event' => 1700000000]);
        $this->webhook()->handle($body, $this->headers(), 'application/json');
        $this->assertFalse($this->platform->suppressions()->canSendTransactional('complainer@example.com'));
    }

    public function testUnsubscribeDoesNotBlockTransactional(): void
    {
        $this->seedOutbound('<msg-5@brevo>', 'unsub@example.com');
        $body = json_encode(['event' => 'unsubscribed', 'email' => 'unsub@example.com', 'message-id' => '<msg-5@brevo>', 'ts_event' => 1700000000]);
        $this->webhook()->handle($body, $this->headers(), 'application/json');
        $this->assertTrue($this->platform->suppressions()->canSendTransactional('unsub@example.com'));
    }

    public function testLateDeliveredDoesNotDowngradeHardBounce(): void
    {
        $uuid = $this->seedOutbound('<msg-6@brevo>', 'x@example.com');
        $this->webhook()->handle(json_encode(['event' => 'hard_bounce', 'email' => 'x@example.com', 'message-id' => '<msg-6@brevo>', 'ts_event' => 1700000100]), $this->headers(), 'application/json');
        // Out-of-order: a delayed "delivered" arrives after the bounce.
        $this->webhook()->handle(json_encode(['event' => 'delivered', 'email' => 'x@example.com', 'message-id' => '<msg-6@brevo>', 'ts_event' => 1700000000]), $this->headers(), 'application/json');

        $this->assertSame('hard_bounced', $this->platform->outbound()->find($uuid)['status'], 'a terminal negative state is not downgraded');
    }

    public function testOpenDoesNotSuppressOrCreateNoise(): void
    {
        $contact = $this->platform->contacts()->create(['display_name' => 'C', 'email' => 'reader@example.com']);
        $this->seedOutbound('<msg-7@brevo>', 'reader@example.com', $contact['uuid']);
        $this->webhook()->handle(json_encode(['event' => 'opened', 'email' => 'reader@example.com', 'message-id' => '<msg-7@brevo>', 'ts_event' => 1700000000]), $this->headers(), 'application/json');

        $this->assertTrue($this->platform->suppressions()->canSendTransactional('reader@example.com'));
        $types = array_column($this->platform->activities()->forEntity('contact', $contact['uuid']), 'type');
        $this->assertNotContains('email.opened', $types, 'opens do not create CRM activity noise');
    }
}
