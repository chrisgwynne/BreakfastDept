<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Clock;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * Regression for the panel/CLI audit path. The Client Previews admin routes call
 * $platform->audit()->event(...) — a signature distinct from the Hermes-request
 * record(); this proves that call shape actually writes a row (an earlier bug
 * called record() with the wrong arguments and 500'd every preview mutation).
 */
final class AuditLogTest extends PlatformTestCase
{
    public function testEventWritesAMappedRow(): void
    {
        $this->platform->audit()->event('preview.publish', 'preview', 'uuid-123', 'studio@example.com', ['version' => 'v-9']);

        $row = array_values(array_filter($this->platform->fileStore()->all('hermes_audit'), static fn (array $r): bool => (string) ($r['endpoint'] ?? '') === 'preview.publish'))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('PANEL', $row['method']);
        $this->assertSame('preview', $row['target_type']);
        $this->assertSame('uuid-123', $row['target_uuid']);
        $this->assertSame('panel:studio@example.com', $row['credential_id']);
        $this->assertSame('ok', $row['result']);
        $this->assertSame(200, (int) $row['status_code']);
    }

    public function testEventToleratesNullActorAndTarget(): void
    {
        $this->platform->audit()->event('preview.disable_all', 'system', null, null, ['count' => 3]);

        $row = array_values(array_filter($this->platform->fileStore()->all('hermes_audit'), static fn (array $r): bool => (string) ($r['endpoint'] ?? '') === 'preview.disable_all'))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('panel:system', $row['credential_id']);
        $this->assertNull($row['target_uuid']);
    }

    public function testPruneRemovesOldEntries(): void
    {
        // One old, one recent.
        Clock::freeze('2020-01-01T00:00:00+00:00');
        $this->platform->audit()->event('preview.create', 'preview', 'old', 'a@b.c', []);
        Clock::unfreeze();
        $this->platform->audit()->event('preview.create', 'preview', 'new', 'a@b.c', []);

        $removed = $this->platform->audit()->prune(365);
        $this->assertSame(1, $removed);
        $this->assertSame(1, count($this->platform->fileStore()->all('hermes_audit')));
    }
}
