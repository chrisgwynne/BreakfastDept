<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Mail\SuppressionService;
use Breakfast\Tests\Support\PlatformTestCase;

final class CrmMailTest extends PlatformTestCase
{
    public function testSendQueuesAndLinksToContact(): void
    {
        $contact = $this->platform->contacts()->create(['display_name' => 'C', 'email' => 'c@example.com']);
        $res = $this->platform->crmMail()->send('c@example.com', 'Hello', "Line one.\n\nLine two.", ['contact_uuid' => $contact['uuid']], 'agent@example.com');

        $this->assertTrue($res['ok']);
        $row = $this->platform->outbound()->find($res['uuid']);
        $this->assertSame('crm_reply', $row['message_type']);
        $this->assertSame($contact['uuid'], $row['contact_uuid']);
        $this->assertSame(1, $this->platform->queue()->pendingCount());
    }

    public function testSuppressedRecipientRejected(): void
    {
        $this->platform->suppressions()->suppress('c@example.com', SuppressionService::SCOPE_TRANSACTIONAL, 'hard_bounce');
        $res = $this->platform->crmMail()->send('c@example.com', 'Hi', 'Body', [], 'a@example.com');
        $this->assertFalse($res['ok']);
        $this->assertSame('recipient_suppressed', $res['error']);
    }

    public function testInvalidRecipientRejected(): void
    {
        $res = $this->platform->crmMail()->send('not-an-email', 'Hi', 'Body', [], 'a@example.com');
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_recipient', $res['error']);
    }

    public function testEmptySubjectRejected(): void
    {
        $res = $this->platform->crmMail()->send('c@example.com', '   ', 'Body', [], 'a@example.com');
        $this->assertFalse($res['ok']);
        $this->assertSame('subject_required', $res['error']);
    }

    public function testPreviewEscapesHtml(): void
    {
        $preview = $this->platform->crmMail()->preview('Subj', '<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $preview['html']);
        $this->assertStringContainsString('&lt;script&gt;', $preview['html']);
    }
}
