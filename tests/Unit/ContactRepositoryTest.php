<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Tests\Support\PlatformTestCase;

final class ContactRepositoryTest extends PlatformTestCase
{
    public function testUpsertByEmailDeduplicates(): void
    {
        $repo = $this->platform->contacts();
        $a = $repo->upsertByEmail(['display_name' => 'Sam', 'email' => 'Sam@Example.com']);
        $b = $repo->upsertByEmail(['display_name' => 'Sam', 'email' => 'sam@example.com', 'phone' => '0123']);

        $this->assertSame($a['uuid'], $b['uuid'], 'same email (case-insensitive) reuses the contact');
        $this->assertSame(1, $repo->count());
        $this->assertSame('0123', $b['phone'], 'blank fields get enriched');
    }

    public function testAnonymiseErasesPii(): void
    {
        $repo = $this->platform->contacts();
        $c = $repo->create(['display_name' => 'Jo', 'email' => 'jo@example.com', 'phone' => '999']);
        $repo->anonymise($c['uuid']);
        $after = $repo->find($c['uuid']);

        $this->assertNull($after['email']);
        $this->assertNull($after['phone']);
        $this->assertSame('anonymised', $after['status']);
        $this->assertNotNull($after['anonymised_at']);
    }

    public function testTagsRoundTrip(): void
    {
        $repo = $this->platform->contacts();
        $c = $repo->create(['display_name' => 'Tagged', 'email' => 't@e.co', 'tags' => ['vip', 'newsletter']]);
        $this->assertSame(['vip', 'newsletter'], $repo->find($c['uuid'])['tags']);
    }
}
