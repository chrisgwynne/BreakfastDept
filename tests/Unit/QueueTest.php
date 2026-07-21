<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Tests\Support\PlatformTestCase;

final class QueueTest extends PlatformTestCase
{
    public function testPushAndReserve(): void
    {
        $q = $this->platform->queue();
        $q->push('mail.test', ['x' => 1]);
        $job = $q->reserve();
        $this->assertNotNull($job);
        $this->assertSame('mail.test', $job->type);
        $this->assertSame(1, $job->payload['x']);
    }

    public function testDedupeKeyPreventsDuplicate(): void
    {
        $q = $this->platform->queue();
        $a = $q->push('mail.ack', ['e' => 1], 'ack:1');
        $b = $q->push('mail.ack', ['e' => 1], 'ack:1');
        $this->assertSame($a, $b, 'same dedupe key returns the existing job');
        $this->assertSame(1, $q->pendingCount());
    }

    public function testFailureRetriesThenFailsPermanently(): void
    {
        $q = $this->platform->queue();
        $q->push('mail.test', [], null, 1); // max 1 attempt
        $job = $q->reserve();
        $q->markFailed($job, 'boom');
        $this->assertSame(1, $q->failedCount());
    }

    public function testReserveReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->platform->queue()->reserve());
    }

    public function testRetryRequeuesFailedJob(): void
    {
        $q = $this->platform->queue();
        $q->push('mail.test', [], null, 1);
        $job = $q->reserve();
        $q->markFailed($job, 'x');
        $failed = $q->failedJobs();
        $this->assertNotEmpty($failed);
        $q->retry($failed[0]['uuid']);
        $this->assertSame(0, $q->failedCount());
    }
}
