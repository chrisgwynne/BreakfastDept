<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Forms\FormDefinition;
use Breakfast\Platform\Forms\FormProcessor;
use Breakfast\Platform\Mail\EmailAddress;
use Breakfast\Platform\Mail\MailMessage;
use Breakfast\Platform\Mail\MailResult;
use Breakfast\Platform\Queue\Worker;
use Breakfast\Platform\Support\Clock;
use Breakfast\Tests\Support\PlatformTestCase;

final class MailPipelineTest extends PlatformTestCase
{
    private function message(string $type = 'crm_reply', ?string $uuid = null): MailMessage
    {
        return new MailMessage(
            messageType: $type,
            sender: EmailAddress::create('studio@example.com', 'Breakfast'),
            to: [EmailAddress::create('client@example.co.uk', 'Client')],
            subject: 'Hello',
            html: '<p>Hi</p>',
            text: 'Hi',
            uuid: $uuid,
        );
    }

    public function testQueueCreatesOutboundAndJobButDoesNotSendInline(): void
    {
        $uuid = $this->platform->mailService()->queue($this->message());

        $this->assertNotNull($this->platform->outbound()->find($uuid));
        $this->assertSame('queued', $this->platform->outbound()->find($uuid)['status']);
        $this->assertSame(1, $this->platform->queue()->pendingCount());
        $this->assertCount(0, $this->mail->sent, 'nothing is sent until the worker runs');
    }

    public function testWorkerDeliversThroughProviderAndStoresProviderMessageId(): void
    {
        $uuid = $this->platform->mailService()->queue($this->message());
        (new Worker($this->platform))->runOnce();

        $row = $this->platform->outbound()->find($uuid);
        $this->assertSame('accepted', $row['status']);
        $this->assertNotNull($row['provider_message_id']);
        $this->assertCount(1, $this->mail->sent);
        $this->assertSame(0, $this->platform->queue()->pendingCount());
    }

    public function testExactlyOnceApplicationMessageOnDuplicateQueue(): void
    {
        $msg = $this->message(uuid: 'fixed-uuid');
        $this->platform->mailService()->queue($msg);
        $this->platform->mailService()->queue(MailMessage::fromArray($msg->toArray()));

        $rows = $this->platform->outbound()->search();
        $this->assertCount(1, $rows, 'duplicate queue does not create a second application message');
    }

    public function testTemporaryFailureRetriesThenSucceeds(): void
    {
        $this->mail->scriptNext(MailResult::temporary('429 rate limit', 429));
        $uuid = $this->platform->mailService()->queue($this->message());

        // First run fails → job requeued (not failed permanently).
        (new Worker($this->platform))->runOnce();
        $this->assertSame('temporary_failure', $this->platform->outbound()->find($uuid)['status']);

        // Advance past the backoff and run again → accepted.
        Clock::freeze(Clock::now()->modify('+1 hour')->format('c'));
        (new Worker($this->platform))->runOnce();
        $this->assertSame('accepted', $this->platform->outbound()->find($uuid)['status']);
    }

    public function testPermanentFailureDoesNotRetry(): void
    {
        $this->mail->scriptNext(MailResult::permanent('invalid recipient', 400));
        $uuid = $this->platform->mailService()->queue($this->message());
        (new Worker($this->platform))->runOnce();

        $this->assertSame('permanent_failure', $this->platform->outbound()->find($uuid)['status']);
        // Job completed (no pointless retry of a 4xx).
        $this->assertSame(0, $this->platform->queue()->pendingCount());
        $this->assertSame(0, $this->platform->queue()->failedCount());
    }

    public function testSuppressedRecipientIsNeverSent(): void
    {
        $this->platform->suppressions()->suppress('client@example.co.uk', 'transactional', 'hard_bounce');
        $uuid = $this->platform->mailService()->queue($this->message());
        (new Worker($this->platform))->runOnce();

        $this->assertSame('suppressed', $this->platform->outbound()->find($uuid)['status']);
        $this->assertCount(0, $this->mail->sent, 'the provider is never called for a suppressed recipient');
    }

    public function testDuplicateWorkerRunDoesNotDoubleSend(): void
    {
        $uuid = $this->platform->mailService()->queue($this->message());
        (new Worker($this->platform))->runOnce();
        // Re-deliver the same payload (simulating a duplicate) — idempotent.
        $this->platform->mailService()->deliver(['message' => $this->platform->outbound()->find($uuid) ? $this->message(uuid: $uuid)->toArray() : []]);

        $this->assertCount(1, $this->mail->sent);
    }

    public function testEnquiryPersistsAndQueuesTwoSeparateEmailsEvenIfProviderWouldFail(): void
    {
        // Provider will fail, but the enquiry + queued messages must still exist.
        $this->mail->scriptNext(MailResult::temporary('down', 503));

        $result = (new FormProcessor($this->platform))->process(FormDefinition::contact(), [
            'name' => 'Pat', 'email' => 'pat@example.co.uk', 'message' => 'A genuine enquiry, long enough to pass.', 'consent' => '1',
        ], ['csrf_valid' => true, 'honeypot' => '', 'rendered_at' => Clock::timestamp() - 20, 'ip' => '203.0.113.1', 'messages' => []]);

        $this->assertTrue($result->success);
        $this->assertSame(1, $this->platform->enquiries()->count());

        $outbound = $this->platform->outbound()->search();
        $types    = array_column($outbound, 'message_type');
        $this->assertContains('enquiry_internal', $types);
        $this->assertContains('enquiry_ack', $types);
        $this->assertCount(2, $outbound, 'internal + acknowledgement are separate application messages');
    }

    public function testInternalNotificationSenderIsStudioNotSubmitterAndReplyToIsSubmitter(): void
    {
        $factory = $this->platform->enquiryMail();
        $enquiry = ['uuid' => 'e1', 'reference' => 'ENQ-1', 'form_type' => 'contact', 'payload' => ['message' => 'hi']];
        $contact = ['uuid' => 'c1', 'email' => 'visitor@example.com', 'display_name' => 'Visitor'];

        $msg = $factory->internal($enquiry, $contact);
        $this->assertSame('studio@example.com', $msg->sender->email);
        $this->assertSame('visitor@example.com', $msg->replyTo?->email);
    }

    public function testInvalidSubmitterReplyToFallsBackToStudio(): void
    {
        $factory = $this->platform->enquiryMail();
        $msg = $factory->internal(
            ['uuid' => 'e1', 'reference' => 'ENQ-1', 'form_type' => 'contact', 'payload' => []],
            ['uuid' => 'c1', 'email' => 'not-an-email', 'display_name' => 'X']
        );
        $this->assertSame('studio@example.com', $msg->replyTo?->email);
    }
}
