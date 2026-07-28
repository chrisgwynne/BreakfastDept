<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\RetentionService;
use Breakfast\Tests\Support\PlatformTestCase;

final class RetentionServiceTest extends PlatformTestCase
{
    private function insertOldActivity(string $when): void
    {
        // Activities are flat files; write one directly with a chosen timestamp.
        $this->platform->fileStore()->put('activities', [
            'uuid'        => bin2hex(random_bytes(8)),
            'entity_type' => 'contact',
            'entity_uuid' => 'c1',
            'type'        => 'note.added',
            'actor_type'  => 'system',
            'summary'     => 'x',
            'metadata'    => [],
            'created_at'  => $when,
        ]);
    }

    private function activityCount(): int
    {
        return count($this->platform->fileStore()->all('activities'));
    }

    public function testPlanIsReadOnlyAndCountsEligible(): void
    {
        $this->insertOldActivity('2019-01-01T00:00:00+00:00'); // well past 730d
        $this->insertOldActivity(date('c')); // recent

        $before = $this->activityCount();
        $plan   = $this->platform->retention()->plan();

        $this->assertSame(730, $plan['crm_activities']['retain_days']);
        $this->assertSame(1, $plan['crm_activities']['eligible'], 'only the old row is eligible');
        // plan() deleted nothing.
        $this->assertSame($before, $this->activityCount());
    }

    public function testDryRunDeletesNothingConfirmDeletesOld(): void
    {
        $this->insertOldActivity('2019-01-01T00:00:00+00:00');
        $this->insertOldActivity(date('c'));

        $dry = $this->platform->retention()->run(false);
        $this->assertSame(1, $dry['crm_activities']);
        $this->assertSame(2, $this->activityCount(), 'dry run kept everything');

        $applied = $this->platform->retention()->run(true);
        $this->assertSame(1, $applied['crm_activities']);
        $this->assertSame(1, $this->activityCount(), 'old row removed, recent kept');
    }

    public function testZeroWindowDisablesCategory(): void
    {
        $this->insertOldActivity('2010-01-01T00:00:00+00:00');

        $service = new RetentionService($this->platform, ['activitiesDays' => 0]);
        $plan    = $service->plan();
        $this->assertSame(0, $plan['crm_activities']['retain_days']);
        $this->assertSame(0, $plan['crm_activities']['eligible'], '0 = keep forever, never eligible');

        $service->run(true);
        $this->assertSame(1, $this->activityCount(), 'nothing deleted');
    }

    public function testOrphanedUploadDetectedAndRemovedAfterGrace(): void
    {
        $dir = $this->platform->uploadsDir();
        @mkdir($dir, 0777, true);
        $orphan = $dir . '/orphan-file.bin';
        file_put_contents($orphan, 'x');
        touch($orphan, time() - 48 * 3600); // older than the 24h grace

        // A referenced file is never an orphan.
        $keep = $dir . '/kept.bin';
        file_put_contents($keep, 'y');
        touch($keep, time() - 48 * 3600);
        $this->platform->db()->run(
            "INSERT INTO uploads (uuid, enquiry_uuid, original_name, stored_name, mime, size_bytes, sha256, created_at)
             VALUES (:u, NULL, 'k', 'kept.bin', 'application/octet-stream', 1, 'abc', :t)",
            ['u' => bin2hex(random_bytes(8)), 't' => date('c')]
        );

        $plan = $this->platform->retention()->plan();
        $this->assertSame(1, $plan['orphaned_uploads']['eligible']);

        $this->platform->retention()->run(true);
        $this->assertFileDoesNotExist($orphan, 'orphan removed');
        $this->assertFileExists($keep, 'referenced file kept');
    }
}
