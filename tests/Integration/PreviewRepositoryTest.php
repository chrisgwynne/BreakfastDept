<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\ClientPreviews\PreviewStatus;
use Breakfast\Tests\Support\PlatformTestCase;

final class PreviewRepositoryTest extends PlatformTestCase
{
    public function testCreateAndFindBySlug(): void
    {
        $preview = $this->platform->previews()->create([
            'name'        => 'Acme mock-up',
            'public_slug' => 'ACME',
            'created_by'  => 'studio@example.com',
        ]);

        $this->assertSame('acme', $preview['public_slug'], 'slug stored lowercased');
        $this->assertSame(PreviewStatus::DRAFT, $preview['status']);
        $this->assertSame(PreviewStatus::VISIBILITY_UNLISTED, $preview['visibility']);
        $this->assertSame(0, (int) $preview['indexable'], 'noindex by default');

        $found = $this->platform->previews()->findBySlug('Acme');
        $this->assertNotNull($found);
        $this->assertSame($preview['uuid'], $found['uuid']);
    }

    public function testSlugTakenIsCaseInsensitiveAndBlocksPreviousSlug(): void
    {
        $repo = $this->platform->previews();
        $a = $repo->create(['name' => 'A', 'public_slug' => 'acme']);

        $this->assertTrue($repo->slugTaken('ACME'));
        $this->assertFalse($repo->slugTaken('acme', $a['uuid']), 'excludes self');

        // Rename: old slug is retained as previous_slug and stays blocked.
        $repo->update($a['uuid'], ['public_slug' => 'acme-two', 'previous_slug' => 'acme']);
        $this->assertTrue($repo->slugTaken('acme'), 'previous slug blocks immediate reuse');
    }

    public function testVersionsIncrementAndSupersede(): void
    {
        $preview = $this->platform->previews()->create(['name' => 'V', 'public_slug' => 'vers']);
        $versions = $this->platform->previewVersions();

        $v1 = $versions->create($preview['uuid'], ['storage_path' => 'versions/x/1', 'state' => PreviewStatus::V_PUBLISHED]);
        $v2 = $versions->create($preview['uuid'], ['storage_path' => 'versions/x/2', 'state' => PreviewStatus::V_PUBLISHED]);

        $this->assertSame(1, (int) $v1['version_number']);
        $this->assertSame(2, (int) $v2['version_number']);

        $versions->supersedeOthers($preview['uuid'], $v2['uuid']);
        $this->assertSame(PreviewStatus::V_SUPERSEDED, $versions->find($v1['uuid'])['state']);
        $this->assertSame(PreviewStatus::V_PUBLISHED, $versions->find($v2['uuid'])['state']);
    }

    public function testAccessEventsStoreNoRawIp(): void
    {
        $preview = $this->platform->previews()->create(['name' => 'A', 'public_slug' => 'acc']);
        $activity = $this->platform->previewActivity();

        $activity->recordAccess($preview['uuid'], null, 'view', '/index.html', 200, '203.0.113.9', 'Mozilla/5.0', 'example.com', 'sess-1');

        $events = array_values(array_filter(
            $this->platform->fileStore()->all('preview_access_events'),
            static fn (array $e): bool => ($e['preview_uuid'] ?? null) === $preview['uuid']
        ));
        $row = $events[0] ?? null;
        $this->assertNotNull($row);
        $this->assertNotSame('203.0.113.9', $row['ip_hash']);
        $this->assertNotNull($row['ip_hash']);
        $this->assertStringNotContainsString('203.0.113.9', json_encode($row) ?: '');

        $summary = $activity->summary($preview['uuid']);
        $this->assertSame(1, $summary['views']);
        $this->assertSame(1, $summary['approx_visitors']);
    }

    public function testFormSubmissionsAlwaysTestLeadsAndBulkDeletable(): void
    {
        $preview = $this->platform->previews()->create(['name' => 'A', 'public_slug' => 'frm']);
        $activity = $this->platform->previewActivity();

        $sub = $activity->recordSubmission($preview['uuid'], 'Jo', 'jo@example.com', 'Hi', '203.0.113.1');
        $this->assertSame(1, (int) $sub['is_test_lead']);
        $this->assertCount(1, $activity->submissions($preview['uuid']));

        $activity->deleteSubmissions($preview['uuid']);
        $this->assertCount(0, $activity->submissions($preview['uuid']));
    }
}
