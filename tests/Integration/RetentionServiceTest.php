<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\RetentionService;
use Breakfast\Tests\Support\PlatformTestCase;

final class RetentionServiceTest extends PlatformTestCase
{
    private function insertOldActivity(string $when): void
    {
        $this->platform->db()->run(
            "INSERT INTO activities (uuid, entity_type, entity_uuid, type, actor_type, summary, created_at)
             VALUES (:u, 'contact', 'c1', 'note.added', 'system', 'x', :t)",
            ['u' => bin2hex(random_bytes(8)), 't' => $when]
        );
    }

    public function testPlanIsReadOnlyAndCountsEligible(): void
    {
        $this->insertOldActivity('2019-01-01T00:00:00+00:00'); // well past 730d
        $this->insertOldActivity($this->platform->db()->scalar('SELECT :n', ['n' => date('c')])); // recent

        $before = (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM activities');
        $plan   = $this->platform->retention()->plan();

        $this->assertSame(730, $plan['crm_activities']['retain_days']);
        $this->assertSame(1, $plan['crm_activities']['eligible'], 'only the old row is eligible');
        // plan() deleted nothing.
        $this->assertSame($before, (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM activities'));
    }

    public function testDryRunDeletesNothingConfirmDeletesOld(): void
    {
        $this->insertOldActivity('2019-01-01T00:00:00+00:00');
        $this->insertOldActivity(date('c'));

        $dry = $this->platform->retention()->run(false);
        $this->assertSame(1, $dry['crm_activities']);
        $this->assertSame(2, (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM activities'), 'dry run kept everything');

        $applied = $this->platform->retention()->run(true);
        $this->assertSame(1, $applied['crm_activities']);
        $this->assertSame(1, (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM activities'), 'old row removed, recent kept');
    }

    public function testZeroWindowDisablesCategory(): void
    {
        $this->insertOldActivity('2010-01-01T00:00:00+00:00');

        $service = new RetentionService($this->platform, ['activitiesDays' => 0]);
        $plan    = $service->plan();
        $this->assertSame(0, $plan['crm_activities']['retain_days']);
        $this->assertSame(0, $plan['crm_activities']['eligible'], '0 = keep forever, never eligible');

        $service->run(true);
        $this->assertSame(1, (int) $this->platform->db()->scalar('SELECT COUNT(*) FROM activities'), 'nothing deleted');
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
