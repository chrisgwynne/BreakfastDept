<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Portfolio\PortfolioException;
use Breakfast\Platform\Portfolio\PortfolioStatus;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * Portfolio domain (CP1): the record lifecycle, the server-enforced publication
 * state machine, optimistic concurrency, slug history, and the editorial
 * publish-validation blockers. Everything here is authoritative on the server;
 * the client is never trusted to drive a transition.
 */
final class PortfolioTest extends PlatformTestCase
{
    private function svc(): \Breakfast\Platform\Portfolio\Portfolio
    {
        return $this->platform->portfolio();
    }

    public function testCreateStartsAsDraftWithUniqueSlug(): void
    {
        $rec = $this->svc()->create(['internal_name' => 'Passo rebuild', 'display_title' => 'Passo'], 'admin@breakfast');
        $this->assertSame(PortfolioStatus::DRAFT, $rec['status']);
        $this->assertSame('private', $rec['visibility']);
        $this->assertSame('passo', $rec['slug']);
        $this->assertSame(1, (int) $rec['revision']);

        // A second record with a colliding slug is disambiguated.
        $rec2 = $this->svc()->create(['internal_name' => 'Passo two', 'slug' => 'passo'], 'admin@breakfast');
        $this->assertSame('passo-2', $rec2['slug']);
    }

    public function testCreateRequiresInternalName(): void
    {
        $this->expectException(PortfolioException::class);
        $this->svc()->create([], 'admin@breakfast');
    }

    public function testStateMachineRejectsInvalidTransitions(): void
    {
        $rec = $this->svc()->create(['internal_name' => 'X'], 'admin@breakfast');
        // draft cannot jump straight to published.
        try {
            $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');
            $this->fail('expected invalid transition to be rejected');
        } catch (PortfolioException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_transition', $e->errorCode);
        }
        // draft → internal_review → ready are valid.
        $this->svc()->transition($rec['uuid'], PortfolioStatus::INTERNAL_REVIEW, 'admin@breakfast');
        $r = $this->svc()->transition($rec['uuid'], PortfolioStatus::READY, 'admin@breakfast');
        $this->assertSame(PortfolioStatus::READY, $r['status']);
        $this->assertSame('preview', $r['visibility']);
    }

    public function testPublishIsBlockedUntilValidThenSucceeds(): void
    {
        $rec = $this->makeReadyRecord();
        // Not yet valid (no service / no approved image / no story) → blocked.
        $blockers = $this->svc()->validateForPublish($rec['uuid']);
        $this->assertNotEmpty($blockers);
        try {
            $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');
            $this->fail('publish should be blocked by validation');
        } catch (PortfolioException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }

        $this->satisfyPublishRequirements($rec['uuid']);
        $this->assertSame([], $this->svc()->validateForPublish($rec['uuid']));
        $published = $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');
        $this->assertSame(PortfolioStatus::PUBLISHED, $published['status']);
        $this->assertSame('public', $published['visibility']);
        $this->assertNotEmpty($published['published_at']);
    }

    public function testPublishOverrideRequiresReason(): void
    {
        $rec = $this->makeReadyRecord();
        // Override without a reason is refused.
        try {
            $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast', ['override' => true]);
            $this->fail('override without reason should be refused');
        } catch (PortfolioException $e) {
            $this->assertSame('override_reason_required', $e->errorCode);
        }
        // With a reason it publishes despite blockers, and records the override.
        $published = $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast', ['override' => true, 'override_reason' => 'Launch pre-agreed with client']);
        $this->assertSame(PortfolioStatus::PUBLISHED, $published['status']);
        $events = $this->platform->db()->all('SELECT type FROM portfolio_events WHERE record_uuid = :u', ['u' => $rec['uuid']]);
        $types = array_map(static fn ($e) => $e['type'], $events);
        $this->assertContains('validation_overridden', $types);
    }

    public function testScheduleRequiresATimeThenPublishes(): void
    {
        $rec = $this->makeReadyRecord();
        try {
            $this->svc()->transition($rec['uuid'], PortfolioStatus::SCHEDULED, 'admin@breakfast');
            $this->fail('scheduling without a time should be refused');
        } catch (PortfolioException $e) {
            $this->assertSame('schedule_time_required', $e->errorCode);
        }
        $r = $this->svc()->transition($rec['uuid'], PortfolioStatus::SCHEDULED, 'admin@breakfast', ['scheduled_at' => '2099-01-01T09:00:00+00:00']);
        $this->assertSame(PortfolioStatus::SCHEDULED, $r['status']);
        $this->assertSame('2099-01-01T09:00:00+00:00', $r['scheduled_at']);
    }

    public function testUnpublishAndArchiveAndRestore(): void
    {
        $rec = $this->makeReadyRecord();
        $this->satisfyPublishRequirements($rec['uuid']);
        $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');
        // Unpublish removes public access without destroying the record.
        $un = $this->svc()->transition($rec['uuid'], PortfolioStatus::UNPUBLISHED, 'admin@breakfast');
        $this->assertSame('private', $un['visibility']);
        $this->assertNotNull($this->svc()->find($rec['uuid']));
        // Archive is distinct, and restore brings it back to draft.
        $arch = $this->svc()->transition($rec['uuid'], PortfolioStatus::ARCHIVED, 'admin@breakfast');
        $this->assertNotEmpty($arch['archived_at']);
        $restored = $this->svc()->transition($rec['uuid'], PortfolioStatus::DRAFT, 'admin@breakfast');
        $this->assertSame(PortfolioStatus::DRAFT, $restored['status']);
        $this->assertNull($restored['archived_at']);
    }

    public function testOptimisticConcurrencyRejectsStaleWrite(): void
    {
        $rec = $this->svc()->create(['internal_name' => 'X'], 'admin@breakfast');
        $this->svc()->update($rec['uuid'], ['summary' => 'first'], 1, 'admin@breakfast'); // now revision 2
        $this->expectException(PortfolioException::class);
        $this->expectExceptionMessageMatches('/changed elsewhere/');
        $this->svc()->update($rec['uuid'], ['summary' => 'stale'], 1, 'admin@breakfast');
    }

    public function testSlugChangeRetiresOldSlugAsRedirect(): void
    {
        $rec = $this->svc()->create(['internal_name' => 'X', 'slug' => 'old-name'], 'admin@breakfast');
        $updated = $this->svc()->update($rec['uuid'], ['slug' => 'new-name'], 1, 'admin@breakfast');
        $this->assertSame('new-name', $updated['slug']);
        $redirect = $this->platform->db()->one('SELECT * FROM portfolio_redirects WHERE old_slug = :s', ['s' => 'old-name']);
        $this->assertNotNull($redirect);
        $this->assertSame($rec['uuid'], $redirect['record_uuid']);
        // The new slug is now taken, so a fresh record can't grab it.
        $other = $this->svc()->create(['internal_name' => 'Y', 'slug' => 'new-name'], 'admin@breakfast');
        $this->assertNotSame('new-name', $other['slug']);
    }

    public function testCountsGroupByStatus(): void
    {
        $this->svc()->create(['internal_name' => 'A'], 'admin@breakfast');
        $b = $this->svc()->create(['internal_name' => 'B'], 'admin@breakfast');
        $this->svc()->transition($b['uuid'], PortfolioStatus::INTERNAL_REVIEW, 'admin@breakfast');
        $counts = $this->svc()->counts();
        $this->assertSame(1, $counts[PortfolioStatus::DRAFT]);
        $this->assertSame(1, $counts[PortfolioStatus::INTERNAL_REVIEW]);
        $this->assertSame(2, $counts['all']);
    }

    // ---- helpers -------------------------------------------------------

    /** A record advanced to READY with the public text fields set (but no media/services yet). */
    private function makeReadyRecord(): array
    {
        $rec = $this->svc()->create([
            'internal_name' => 'Passo rebuild',
            'display_title' => 'A booking-first site for Passo',
            'client_display_name' => 'Passo',
            'summary' => 'A neighbourhood Italian that now takes bookings on the site, not in DMs.',
        ], 'admin@breakfast');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::INTERNAL_REVIEW, 'admin@breakfast');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::READY, 'admin@breakfast');

        return $this->svc()->find($rec['uuid']);
    }

    /** Add the linked service, an approved public image and a story section so validation passes. */
    private function satisfyPublishRequirements(string $recordUuid): void
    {
        $db = $this->platform->db();
        $now = \Breakfast\Platform\Support\Clock::nowIso();
        // A service taxonomy + link.
        $tax = \Breakfast\Platform\Support\Uuid::v4();
        $db->run("INSERT INTO portfolio_taxonomies (uuid, kind, label, slug, created_at) VALUES (:u,'service','Website design','website-design',:now)", ['u' => $tax, 'now' => $now]);
        $db->run('INSERT INTO portfolio_record_taxonomies (record_uuid, taxonomy_uuid) VALUES (:r,:t)', ['r' => $recordUuid, 't' => $tax]);
        // An approved public image with alt text.
        $db->run(
            "INSERT INTO portfolio_media (uuid, record_uuid, kind, role, alt, approved_for_public, approved_by, approved_at, created_at, updated_at)
             VALUES (:u,:r,'image','cover','Passo homepage on a laptop',1,'admin@breakfast',:now,:now,:now)",
            ['u' => \Breakfast\Platform\Support\Uuid::v4(), 'r' => $recordUuid, 'now' => $now]
        );
        // A visible story section.
        $db->run(
            "INSERT INTO portfolio_story_sections (uuid, record_uuid, section_key, heading, body, visible, sort_order)
             VALUES (:u,:r,'problem','The problem','Bookings were lost in Instagram DMs during service.',1,0)",
            ['u' => \Breakfast\Platform\Support\Uuid::v4(), 'r' => $recordUuid]
        );
    }
}
