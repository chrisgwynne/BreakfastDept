<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Portfolio\PortfolioStatus;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Uuid;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * Portfolio publication pipeline (CP2): the public/private boundary, atomic
 * snapshot publication with last-known-good preservation, unpublish/archive
 * behaviour, slug redirects, and the idempotent scheduler.
 */
final class PortfolioPublishTest extends PlatformTestCase
{
    private const CANARY_INTERNAL = 'SECRET-INTERNAL-Acme-Ltd-9f83';
    private const CANARY_UNAPPROVED_ALT = 'UNAPPROVED-SCREENSHOT-CANARY';
    private const CANARY_TESTIMONIAL = 'DRAFT-TESTIMONIAL-CANARY';

    private function svc(): \Breakfast\Platform\Portfolio\Portfolio
    {
        return $this->platform->portfolio();
    }

    public function testPublishCreatesCurrentSnapshotServedBySlug(): void
    {
        $rec = $this->makePublishable();
        $this->assertNull($this->svc()->publicBySlug($rec['slug']), 'not public before publishing');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');

        $public = $this->svc()->publicBySlug($rec['slug']);
        $this->assertIsArray($public);
        $this->assertSame('A booking-first site for Passo', $public['display_title']);
        $this->assertNotEmpty($public['services']);
        $this->assertNotEmpty($public['gallery']);

        $current = (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM portfolio_snapshots WHERE record_uuid = :u AND is_current = 1', ['u' => $rec['uuid']]);
        $this->assertSame(1, $current);
    }

    public function testSnapshotExcludesPrivateData(): void
    {
        $rec = $this->makePublishable(withPrivateCanaries: true);
        $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');
        $json = (string) $this->platform->db()->scalar('SELECT payload FROM portfolio_snapshots WHERE record_uuid = :u AND is_current = 1', ['u' => $rec['uuid']]);

        // None of the private canaries may appear in the public snapshot.
        $this->assertStringNotContainsString(self::CANARY_INTERNAL, $json, 'internal name leaked');
        $this->assertStringNotContainsString(self::CANARY_UNAPPROVED_ALT, $json, 'unapproved media leaked');
        $this->assertStringNotContainsString(self::CANARY_TESTIMONIAL, $json, 'unapproved testimonial leaked');
        $this->assertStringNotContainsString($rec['company_uuid'], $json, 'internal company id leaked');
        $this->assertStringNotContainsString($rec['project_uuid'], $json, 'internal project id leaked');
        $this->assertStringNotContainsString('/storage/', $json, 'storage path leaked');

        // But the approved public content IS present.
        $this->assertStringContainsString('Passo', $json);
    }

    public function testUnpublishTakesOfflineButKeepsRecordAndHistory(): void
    {
        $rec = $this->makePublishable();
        $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');
        $this->assertNotNull($this->svc()->publicBySlug($rec['slug']));

        $this->svc()->transition($rec['uuid'], PortfolioStatus::UNPUBLISHED, 'admin@breakfast');
        $this->assertNull($this->svc()->publicBySlug($rec['slug']), 'public access removed');
        // Record still exists, snapshot row is retained (just not current).
        $this->assertNotNull($this->svc()->find($rec['uuid']));
        $this->assertSame(1, (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM portfolio_snapshots WHERE record_uuid = :u', ['u' => $rec['uuid']]));
    }

    public function testRepublishKeepsLastKnownGood(): void
    {
        $rec = $this->makePublishable();
        $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::UNPUBLISHED, 'admin@breakfast');
        // Edit + republish → a second snapshot; the first is retained.
        $rec2 = $this->svc()->find($rec['uuid']);
        $this->svc()->update($rec['uuid'], ['summary' => 'A fresher public summary for Passo.'], (int) $rec2['revision'], 'admin@breakfast');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::READY, 'admin@breakfast');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');

        $total = (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM portfolio_snapshots WHERE record_uuid = :u', ['u' => $rec['uuid']]);
        $current = (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM portfolio_snapshots WHERE record_uuid = :u AND is_current = 1', ['u' => $rec['uuid']]);
        $this->assertSame(2, $total, 'both snapshots retained');
        $this->assertSame(1, $current, 'exactly one is current');
        $this->assertStringContainsString('fresher public summary', (string) $this->svc()->publicBySlug($rec['slug'])['summary']);
    }

    public function testSchedulerIsIdempotent(): void
    {
        $rec = $this->makePublishable();
        $this->svc()->transition($rec['uuid'], PortfolioStatus::SCHEDULED, 'admin@breakfast', ['scheduled_at' => '2020-01-01T09:00:00+00:00']);

        $first = $this->svc()->publishDue('2020-06-01T00:00:00+00:00', 'cli:portfolio');
        $this->assertSame([$rec['uuid']], $first['published']);
        // Running again publishes nothing (the record is no longer scheduled).
        $second = $this->svc()->publishDue('2020-06-01T00:00:00+00:00', 'cli:portfolio');
        $this->assertSame([], $second['published']);
        $this->assertSame(1, (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM portfolio_snapshots WHERE record_uuid = :u', ['u' => $rec['uuid']]));
    }

    public function testSchedulerSkipsFutureAndReportsFailures(): void
    {
        // Future schedule is not yet due.
        $future = $this->makePublishable();
        $this->svc()->transition($future['uuid'], PortfolioStatus::SCHEDULED, 'admin@breakfast', ['scheduled_at' => '2099-01-01T00:00:00+00:00']);
        $res = $this->svc()->publishDue('2020-01-01T00:00:00+00:00', 'cli:portfolio');
        $this->assertSame([], $res['published']);
    }

    public function testSlugRedirectResolvesAfterRenameAndPublish(): void
    {
        $rec = $this->makePublishable(['slug' => 'passo-original']);
        $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');
        // Rename (retires old slug) then republish so the new slug is live.
        $cur = $this->svc()->find($rec['uuid']);
        $this->svc()->update($rec['uuid'], ['slug' => 'passo-new'], (int) $cur['revision'], 'admin@breakfast');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::UNPUBLISHED, 'admin@breakfast');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::READY, 'admin@breakfast');
        $this->svc()->transition($rec['uuid'], PortfolioStatus::PUBLISHED, 'admin@breakfast');

        $this->assertSame('passo-new', $this->svc()->redirectFor('passo-original'));
        $this->assertNull($this->svc()->redirectFor('passo-new'), 'live slug never redirects (no loop)');
        $this->assertNotNull($this->svc()->publicBySlug('passo-new'));
    }

    public function testProjectSuggestionsAreSafeFieldsOnly(): void
    {
        $suggestions = $this->svc()->projectSuggestions([
            'name' => 'Passo website', 'project_type' => 'restaurant', 'target_date' => '2026-03-01',
            'description' => 'A booking-first restaurant site.',
            // Private fields that must NEVER be suggested:
            'quoted_value' => 480000, 'owner' => 'staff@breakfast', 'scope' => 'internal scope notes',
        ]);
        $this->assertSame('Passo website', $suggestions['display_title']);
        $this->assertArrayNotHasKey('quoted_value', $suggestions);
        $this->assertArrayNotHasKey('owner', $suggestions);
        $this->assertArrayNotHasKey('scope', $suggestions);
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function makePublishable(array $overrides = [], bool $withPrivateCanaries = false): array
    {
        $data = array_merge([
            'internal_name' => $withPrivateCanaries ? self::CANARY_INTERNAL : 'Passo rebuild',
            'display_title' => 'A booking-first site for Passo',
            'client_display_name' => 'Passo',
            'summary' => 'A neighbourhood Italian that now takes bookings on the site, not in DMs.',
            'company_uuid' => Uuid::v4(),
            'project_uuid' => Uuid::v4(),
        ], $overrides);
        $rec = $this->svc()->create($data, 'admin@breakfast');
        $uuid = $rec['uuid'];
        $db = $this->platform->db();
        $now = Clock::nowIso();

        // Linked service taxonomy.
        $tax = Uuid::v4();
        $db->run("INSERT INTO portfolio_taxonomies (uuid, kind, label, slug, service_slug, created_at) VALUES (:u,'service','Website design','website-design','new-website',:now)", ['u' => $tax, 'now' => $now]);
        $db->run('INSERT INTO portfolio_record_taxonomies (record_uuid, taxonomy_uuid) VALUES (:r,:t)', ['r' => $uuid, 't' => $tax]);

        // Approved public cover image with variants + alt.
        $coverUuid = Uuid::v4();
        $db->run(
            "INSERT INTO portfolio_media (uuid, record_uuid, kind, role, alt, variants, approved_for_public, approved_by, approved_at, created_at, updated_at)
             VALUES (:u,:r,'image','cover','Passo homepage on a laptop',:variants,1,'admin@breakfast',:now,:now,:now)",
            ['u' => $coverUuid, 'r' => $uuid, 'variants' => json_encode(['card' => '/media/portfolio/' . $coverUuid . '/card.webp']), 'now' => $now]
        );
        $db->run('UPDATE portfolio_records SET cover_media_uuid = :m WHERE uuid = :u', ['m' => $coverUuid, 'u' => $uuid]);

        // A visible story section.
        $db->run(
            "INSERT INTO portfolio_story_sections (uuid, record_uuid, section_key, heading, body, visible, sort_order)
             VALUES (:u,:r,'problem','The problem','Bookings were lost in Instagram DMs during service.',1,0)",
            ['u' => Uuid::v4(), 'r' => $uuid]
        );

        if ($withPrivateCanaries) {
            // An UNAPPROVED screenshot (must never publish) with a canary alt.
            $db->run(
                "INSERT INTO portfolio_media (uuid, record_uuid, kind, role, alt, approved_for_public, created_at, updated_at)
                 VALUES (:u,:r,'image','gallery',:alt,0,:now,:now)",
                ['u' => Uuid::v4(), 'r' => $uuid, 'alt' => self::CANARY_UNAPPROVED_ALT, 'now' => $now]
            );
            // An UNAPPROVED testimonial (must never publish).
            $db->run(
                "INSERT INTO portfolio_testimonials (uuid, record_uuid, quote, person_name, approved, visible, created_at, updated_at)
                 VALUES (:u,:r,:q,'Someone',0,1,:now,:now)",
                ['u' => Uuid::v4(), 'r' => $uuid, 'q' => self::CANARY_TESTIMONIAL, 'now' => $now]
            );
        }

        // Advance to READY.
        $this->svc()->transition($uuid, PortfolioStatus::INTERNAL_REVIEW, 'admin@breakfast');
        $this->svc()->transition($uuid, PortfolioStatus::READY, 'admin@breakfast');

        return $this->svc()->find($uuid);
    }
}
