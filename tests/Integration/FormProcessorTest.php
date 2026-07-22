<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Forms\FormDefinition;
use Breakfast\Platform\Forms\FormProcessor;
use Breakfast\Platform\Support\Clock;
use Breakfast\Tests\Support\PlatformTestCase;

final class FormProcessorTest extends PlatformTestCase
{
    /** @return array<string,mixed> */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'csrf_valid'  => true,
            'honeypot'    => '',
            'rendered_at' => Clock::timestamp() - 20,
            'ip'          => '203.0.113.10',
            'messages'    => [],
        ], $overrides);
    }

    private function validContact(): array
    {
        return [
            'name'    => 'Jamie Rivers',
            'email'   => 'jamie@example.co.uk',
            'company' => 'Rivers Joinery',
            'subject' => 'New site',
            'message' => 'We need a clearer website for our joinery business please.',
            'consent' => '1',
        ];
    }

    public function testValidContactPersistsEverythingThenQueues(): void
    {
        $p = $this->platform;
        $result = (new FormProcessor($p))->process(FormDefinition::contact(), $this->validContact(), $this->context());

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('ENQ-', $result->reference);

        // Persisted BEFORE any external side effect.
        $this->assertSame(1, $p->enquiries()->count());
        $this->assertSame(1, $p->contacts()->count());
        $this->assertSame(1, $p->companies()->count());

        // Immutable activity recorded.
        $enquiry = $p->enquiries()->search()[0];
        $activity = $p->activities()->forEntity('enquiry', $enquiry['uuid']);
        $this->assertContains('form.received', array_column($activity, 'type'));

        // Two emails queued (internal + acknowledgement), not sent inline.
        $this->assertSame(2, $p->queue()->pendingCount());
    }

    public function testWebsiteReviewPersistsAsFlaggedLead(): void
    {
        $p = $this->platform;
        $review = [
            'name'     => 'Sam Owen',
            'email'    => 'sam@example.co.uk',
            'company'  => 'Owen Plumbing',
            'website'  => 'https://old-owen-plumbing.example',
            'location' => 'Rhyl',
            'issues'   => 'It looks dated and I cannot book anything on a phone.',
            'phone'    => '01745 000111',
            'consent'  => '',
        ];

        $result = (new FormProcessor($p))->process(FormDefinition::websiteReview(), $review, $this->context());

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('ENQ-', $result->reference);
        $this->assertSame(1, $p->enquiries()->count());

        // The enquiry is flagged by its form type, distinct from contact/project.
        $enquiry = $p->enquiries()->search()[0];
        $this->assertSame('website-review', $enquiry['form_type']);

        // A CRM lead is created with the correct source and the site to review.
        $contact = $p->contacts()->search()[0];
        $this->assertSame('website-review-form', $contact['lead_source']);
        $this->assertStringContainsString('old-owen-plumbing', (string) $contact['website']);

        // Internal notification + acknowledgement queued, never sent inline.
        $this->assertSame(2, $p->queue()->pendingCount());
    }

    public function testCsrfFailureBlocksSubmission(): void
    {
        $p = $this->platform;
        $result = (new FormProcessor($p))->process(FormDefinition::contact(), $this->validContact(), $this->context(['csrf_valid' => false]));

        $this->assertFalse($result->success);
        $this->assertSame(0, $p->enquiries()->count());
    }

    public function testHoneypotIsBenignButPersistsNothing(): void
    {
        $p = $this->platform;
        $result = (new FormProcessor($p))->process(FormDefinition::contact(), $this->validContact(), $this->context(['honeypot' => 'gotcha']));

        $this->assertTrue($result->success, 'bots see a benign success');
        $this->assertSame(0, $p->enquiries()->count());
        $this->assertSame(0, $p->queue()->pendingCount());
    }

    public function testTimingTooFastRejected(): void
    {
        $p = $this->platform;
        $result = (new FormProcessor($p))->process(FormDefinition::contact(), $this->validContact(), $this->context(['rendered_at' => Clock::timestamp()]));

        $this->assertTrue($result->success);
        $this->assertSame(0, $p->enquiries()->count());
    }

    public function testValidationErrorsReturned(): void
    {
        $p = $this->platform;
        $result = (new FormProcessor($p))->process(FormDefinition::contact(), ['name' => '', 'email' => 'bad', 'message' => 'x'], $this->context());

        $this->assertFalse($result->success);
        $this->assertArrayHasKey('name', $result->errors);
        $this->assertArrayHasKey('email', $result->errors);
        $this->assertSame(0, $p->enquiries()->count());
    }

    public function testDuplicateSubmissionSwallowed(): void
    {
        $p = $this->platform;
        $processor = new FormProcessor($p);
        $processor->process(FormDefinition::contact(), $this->validContact(), $this->context());
        $processor->process(FormDefinition::contact(), $this->validContact(), $this->context());

        $this->assertSame(1, $p->enquiries()->count(), 'identical submission within the window is not stored twice');
    }

    public function testRateLimitingBlocksAfterThreshold(): void
    {
        $p = $this->platform;
        $processor = new FormProcessor($p);

        // Vary content so duplicate detection does not fire first; same IP.
        for ($i = 0; $i < 6; $i++) {
            $data = $this->validContact();
            $data['message'] = 'Distinct enquiry number ' . $i . ' with enough length.';
            $data['email']   = 'person' . $i . '@example.co.uk';
            $last = $processor->process(FormDefinition::contact(), $data, $this->context());
        }

        $this->assertTrue($last->rateLimited, 'the IP is rate limited after repeated submissions');
    }

    public function testIpIsStoredHashedNotPlainAndUtmCaptured(): void
    {
        $p = $this->platform;
        (new FormProcessor($p))->process(FormDefinition::contact(), $this->validContact(), $this->context([
            'ip'  => '198.51.100.9',
            'utm' => ['utm_source' => 'newsletter'],
        ]));

        $enquiry = $p->enquiries()->search()[0];
        $this->assertNotSame('198.51.100.9', $enquiry['ip_hash']);
        $this->assertNotEmpty($enquiry['ip_hash']);
        $this->assertSame('newsletter', $enquiry['utm']['utm_source']);
    }
}
