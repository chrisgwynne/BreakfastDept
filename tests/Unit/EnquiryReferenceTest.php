<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Tests\Support\PlatformTestCase;

final class EnquiryReferenceTest extends PlatformTestCase
{
    public function testReferencesAreSequentialAndUnique(): void
    {
        $repo = $this->platform->enquiries();
        $a = $repo->create(['form_type' => 'contact', 'payload' => []]);
        $b = $repo->create(['form_type' => 'contact', 'payload' => []]);
        $this->assertNotSame($a['reference'], $b['reference']);
        $this->assertStringStartsWith('ENQ-', $a['reference']);
    }

    public function testFindByReferenceRequiresAnExactPersistedReference(): void
    {
        $repo = $this->platform->enquiries();
        $enquiry = $repo->create(['form_type' => 'start-project', 'payload' => []]);

        $this->assertSame($enquiry['uuid'], $repo->findByReference($enquiry['reference'])['uuid'] ?? null);
        $this->assertNull($repo->findByReference($enquiry['reference'] . '-forged'));
    }

    public function testIpHashPruning(): void
    {
        $repo = $this->platform->enquiries();
        $repo->create(['form_type' => 'contact', 'payload' => [], 'ip_hash' => 'abc']);
        // Freshly-created rows are within retention, so nothing prunes yet.
        $this->assertSame(0, $repo->pruneIpHashes(30));
    }
}
