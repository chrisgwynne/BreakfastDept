<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Mail\SuppressionService;
use Breakfast\Tests\Support\PlatformTestCase;

final class SuppressionTest extends PlatformTestCase
{
    public function testTransactionalAndMarketingAreSeparate(): void
    {
        $s = $this->platform->suppressions();
        $s->suppress('a@b.com', SuppressionService::SCOPE_MARKETING, 'unsubscribe');

        // Marketing suppression must NOT block a lawful transactional reply.
        $this->assertTrue($s->canSendTransactional('a@b.com'));
        $this->assertTrue($s->isSuppressed('a@b.com', SuppressionService::SCOPE_MARKETING));
    }

    public function testHardBounceBlocksTransactional(): void
    {
        $s = $this->platform->suppressions();
        $s->applyFromEvent('hard_bounce', 'a@b.com', null);
        $this->assertFalse($s->canSendTransactional('a@b.com'));
    }

    public function testUnsubscribeOnlyMarketing(): void
    {
        $s = $this->platform->suppressions();
        $s->applyFromEvent('unsubscribed', 'a@b.com', null);
        $this->assertTrue($s->canSendTransactional('a@b.com'));
        $this->assertTrue($s->isSuppressed('a@b.com', SuppressionService::SCOPE_MARKETING));
    }

    public function testUnsuppressIsExplicitAndAudited(): void
    {
        $contact = $this->platform->contacts()->create(['display_name' => 'X', 'email' => 'a@b.com']);
        $s = $this->platform->suppressions();
        $s->suppress('a@b.com', SuppressionService::SCOPE_TRANSACTIONAL, 'hard_bounce', $contact['uuid']);
        $s->unsuppress('a@b.com', SuppressionService::SCOPE_TRANSACTIONAL, $contact['uuid'], 'admin@example.com');

        $this->assertTrue($s->canSendTransactional('a@b.com'));
        $types = array_column($this->platform->activities()->forEntity('contact', $contact['uuid']), 'type');
        $this->assertContains('email.unsuppressed', $types);
    }

    public function testSoftBounceDoesNotSuppress(): void
    {
        $s = $this->platform->suppressions();
        $s->applyFromEvent('soft_bounce', 'a@b.com', null);
        $this->assertTrue($s->canSendTransactional('a@b.com'));
    }
}
