<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\DashboardData;
use Breakfast\Platform\Security\PreviewGate;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * The Breakfast Admin dashboard aggregates only safe summaries and degrades
 * gracefully when the Client Previews tables are not yet present.
 */
final class AdminDashboardTest extends PlatformTestCase
{
    public function testMetricsAreSafeSummaries(): void
    {
        $data    = new DashboardData($this->platform);
        $metrics = $data->metrics();

        $this->assertArrayHasKey('new_leads', $metrics);
        $this->assertArrayHasKey('pipeline_value', $metrics);
        $this->assertArrayHasKey('active_previews', $metrics);
        // No secret-bearing keys ever appear.
        $this->assertArrayNotHasKey('api_key', $metrics);
        $this->assertArrayNotHasKey('dbPath', $metrics);
    }

    public function testSystemHealthExposesNoSecrets(): void
    {
        $health = (new DashboardData($this->platform))->systemHealth();

        $this->assertSame('fake', $health['mail_provider']);
        $this->assertArrayHasKey('queue_depth', $health);
        $this->assertArrayHasKey('failed_jobs', $health);
        // Never leak credentials, hosts or paths.
        foreach ($health as $key => $value) {
            $this->assertStringNotContainsStringIgnoringCase('key', (string) $key);
            $this->assertStringNotContainsStringIgnoringCase('password', (string) $key);
        }
    }

    public function testPreviewsSummaryIsAllZeroWhenNoneExist(): void
    {
        // With an empty flat-file store the summary must be all-zero, never a fatal.
        $summary = (new DashboardData($this->platform))->previewsSummary();

        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['active']);
    }

    public function testPreviewsSummaryCountsRowsWhenTablePresent(): void
    {
        $repo = $this->platform->previews();
        $a = $repo->create(['name' => 'A', 'public_slug' => 'aaa']);
        $b = $repo->create(['name' => 'B', 'public_slug' => 'bbb']);
        $c = $repo->create(['name' => 'C', 'public_slug' => 'ccc']);
        $d = $repo->create(['name' => 'D', 'public_slug' => 'ddd']);
        $repo->update($a['uuid'], ['status' => 'active']);
        $repo->update($b['uuid'], ['status' => 'active']);
        // c stays draft; d is active but archived (excluded).
        $repo->update($d['uuid'], ['status' => 'active', 'archived_at' => '2020-01-01T00:00:00+00:00']);

        $summary = (new DashboardData($this->platform))->previewsSummary();

        $this->assertSame(2, $summary['active'], 'archived rows excluded');
        $this->assertSame(1, $summary['draft']);
        $this->assertSame(3, $summary['total']);
    }

    public function testPreviewGateDeniesByDefault(): void
    {
        // No user → denied for every action.
        $this->assertFalse(PreviewGate::canView(null));
        $this->assertFalse(PreviewGate::canPublish(null));
        $this->assertFalse(PreviewGate::can(null, 'delete'));
        // Unknown action is never granted.
        $this->assertFalse(PreviewGate::can(null, 'not-an-action'));
    }
}
