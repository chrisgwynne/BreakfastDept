<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\PrivacyService;
use Breakfast\Tests\Support\PlatformTestCase;

final class PrivacyServiceTest extends PlatformTestCase
{
    public function testExportContactReturnsStructuredData(): void
    {
        $contact = $this->platform->contacts()->create(['display_name' => 'Jo', 'email' => 'jo@example.com']);
        $this->platform->enquiries()->create(['form_type' => 'contact', 'contact_uuid' => $contact['uuid'], 'payload' => []]);

        $export = (new PrivacyService($this->platform))->exportContact($contact['uuid']);
        $this->assertSame('jo@example.com', $export['contact']['email']);
        $this->assertCount(1, $export['enquiries']);
    }

    public function testAnonymiseScrubsPiiButKeepsHistory(): void
    {
        $contact = $this->platform->contacts()->create(['display_name' => 'Jo', 'email' => 'jo@example.com']);
        $this->platform->crmMail()->send('jo@example.com', 'Hi', 'Body', ['contact_uuid' => $contact['uuid']], 'a@example.com');

        $ok = (new PrivacyService($this->platform))->anonymiseContact($contact['uuid']);
        $this->assertTrue($ok);

        $after = $this->platform->contacts()->find($contact['uuid']);
        $this->assertNull($after['email']);
        $this->assertNotNull($after['anonymised_at']);

        // Delivery history row is kept but recipient scrubbed.
        $emails = $this->platform->outbound()->search();
        $this->assertNotEmpty($emails);
        $this->assertSame('anonymised', $emails[0]['recipient_email']);

        $types = array_column($this->platform->activities()->forEntity('contact', $contact['uuid']), 'type');
        $this->assertContains('anonymisation', $types);
    }

    public function testCleanupDryRunReturnsCounts(): void
    {
        $counts = (new PrivacyService($this->platform))->cleanup(false);
        $this->assertArrayHasKey('email_events', $counts);
        $this->assertArrayHasKey('completed_jobs', $counts);
    }
}
