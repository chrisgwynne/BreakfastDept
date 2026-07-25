<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Portfolio\PortfolioException;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * Portfolio child entities (CP3 part 2): structured story, modular blocks,
 * results, media metadata + rights approval, and taxonomy. Type allow-lists,
 * rich-text sanitisation and the rights gate are all enforced server-side.
 */
final class PortfolioChildTest extends PlatformTestCase
{
    private function svc(): \Breakfast\Platform\Portfolio\Portfolio
    {
        return $this->platform->portfolio();
    }

    private function record(): string
    {
        return (string) $this->svc()->create(['internal_name' => 'X'], 'admin@breakfast')['uuid'];
    }

    public function testStorySectionUpsertAndSanitisation(): void
    {
        $r = $this->record();
        $s = $this->svc()->upsertStorySection($r, 'problem', ['heading' => 'The problem', 'body' => 'Bookings were lost <script>alert(1)</script> in DMs.'], 'admin@breakfast');
        $this->assertStringNotContainsString('<script>', (string) $s['body']);
        $this->assertStringContainsString('Bookings were lost', (string) $s['body']);
        // Upsert updates in place (still one row for the key).
        $this->svc()->upsertStorySection($r, 'problem', ['body' => 'Rewritten.'], 'admin@breakfast');
        $count = (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM portfolio_story_sections WHERE record_uuid = :r AND section_key = :k', ['r' => $r, 'k' => 'problem']);
        $this->assertSame(1, $count);
        // Unknown section key rejected.
        $this->expectException(PortfolioException::class);
        $this->svc()->upsertStorySection($r, 'nonsense', ['body' => 'x'], 'admin@breakfast');
    }

    public function testBlocksLifecycle(): void
    {
        $r = $this->record();
        $b1 = $this->svc()->addBlock($r, ['type' => 'full_image', 'content' => ['x' => 1]], 'admin@breakfast');
        $b2 = $this->svc()->addBlock($r, ['type' => 'quote', 'content' => ['text' => 'Great']], 'admin@breakfast');
        $this->assertSame(0, (int) $b1['sort_order']);
        $this->assertSame(1, (int) $b2['sort_order']);
        // Invalid type rejected (no arbitrary/code blocks).
        try {
            $this->svc()->addBlock($r, ['type' => 'raw_html'], 'admin@breakfast');
            $this->fail('invalid block type should be rejected');
        } catch (PortfolioException $e) {
            $this->assertSame('invalid_block', $e->errorCode);
        }
        // Reorder, hide, soft-delete + restore.
        $this->svc()->reorderBlocks($r, [$b2['uuid'], $b1['uuid']], 'admin@breakfast');
        $this->assertSame(0, (int) $this->platform->db()->scalar('SELECT sort_order FROM portfolio_blocks WHERE uuid = :u', ['u' => $b2['uuid']]));
        $this->svc()->setBlockDeleted($b1['uuid'], true, 'admin@breakfast');
        $this->assertSame(1, (int) $this->platform->db()->scalar('SELECT deleted FROM portfolio_blocks WHERE uuid = :u', ['u' => $b1['uuid']]));
        $this->svc()->setBlockDeleted($b1['uuid'], false, 'admin@breakfast');
        $this->assertSame(0, (int) $this->platform->db()->scalar('SELECT deleted FROM portfolio_blocks WHERE uuid = :u', ['u' => $b1['uuid']]));
    }

    public function testResultsRequireSourceForNumbers(): void
    {
        $r = $this->record();
        // Numeric result without a source is refused.
        try {
            $this->svc()->addResult($r, ['value' => '+40%', 'label' => 'Enquiries', 'is_numeric' => true], 'admin@breakfast');
            $this->fail('numeric result without source should be refused');
        } catch (PortfolioException $e) {
            $this->assertSame('source_required', $e->errorCode);
        }
        // With a source it's accepted; a qualitative result needs none.
        $num = $this->svc()->addResult($r, ['value' => '+40%', 'label' => 'Enquiries', 'is_numeric' => true, 'source' => 'GA4 Jan–Jun'], 'admin@breakfast');
        $qual = $this->svc()->addResult($r, ['label' => 'Easier to book', 'is_numeric' => false], 'admin@breakfast');
        $this->assertNotEmpty($num['uuid']);
        $this->svc()->deleteResult($qual['uuid'], 'admin@breakfast');
        $this->assertSame(1, (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM portfolio_results WHERE record_uuid = :r', ['r' => $r]));
    }

    public function testMediaRightsApprovalGate(): void
    {
        $r = $this->record();
        $m = $this->svc()->attachMedia($r, ['role' => 'cover', 'alt' => 'Homepage'], 'admin@breakfast');
        $this->assertSame(0, (int) $m['approved_for_public'], 'media is not public until approved');
        $approved = $this->svc()->approveMedia($m['uuid'], true, ['rights_source' => 'Client supplied', 'rights_note' => 'Permission on file'], 'admin@breakfast');
        $this->assertSame(1, (int) $approved['approved_for_public']);
        $this->assertSame('admin@breakfast', $approved['approved_by']);
        // Revoking approval takes it back out of public eligibility.
        $revoked = $this->svc()->approveMedia($m['uuid'], false, [], 'admin@breakfast');
        $this->assertSame(0, (int) $revoked['approved_for_public']);
        // The approve/unapprove is recorded as an editorial event.
        $types = array_column($this->platform->db()->all('SELECT type FROM portfolio_events WHERE record_uuid = :r', ['r' => $r]), 'type');
        $this->assertContains('media_approved', $types);
    }

    public function testTaxonomyCreateAndAttach(): void
    {
        $r = $this->record();
        $svc = $this->svc();
        $t1 = $svc->createTaxonomy(['kind' => 'service', 'label' => 'Website design', 'service_slug' => 'new-website'], 'admin@breakfast');
        $t2 = $svc->createTaxonomy(['kind' => 'industry', 'label' => 'Hospitality'], 'admin@breakfast');
        // Duplicate (kind+slug) rejected.
        try {
            $svc->createTaxonomy(['kind' => 'service', 'label' => 'Website design'], 'admin@breakfast');
            $this->fail('duplicate taxonomy should be rejected');
        } catch (PortfolioException $e) {
            $this->assertSame('duplicate', $e->errorCode);
        }
        // Invalid kind rejected.
        try {
            $svc->createTaxonomy(['kind' => 'nonsense', 'label' => 'x'], 'admin@breakfast');
            $this->fail('invalid kind should be rejected');
        } catch (PortfolioException $e) {
            $this->assertSame('invalid_kind', $e->errorCode);
        }
        $svc->setRecordTaxonomies($r, [$t1['uuid'], $t2['uuid']], 'admin@breakfast');
        $linked = $svc->find($r)['taxonomies'];
        $this->assertCount(2, $linked);
        // Re-setting replaces, never duplicates.
        $svc->setRecordTaxonomies($r, [$t1['uuid']], 'admin@breakfast');
        $this->assertCount(1, $svc->find($r)['taxonomies']);
    }
}
