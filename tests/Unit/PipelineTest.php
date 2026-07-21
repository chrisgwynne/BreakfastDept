<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Tests\Support\PlatformTestCase;
use InvalidArgumentException;

final class PipelineTest extends PlatformTestCase
{
    public function testStageTransitionRecordsActivityAndStampsWon(): void
    {
        $crm = $this->platform->crm();
        $opp = $crm->createOpportunity(['title' => 'Deal', 'estimated_value' => 100000]);
        $moved = $crm->moveOpportunity($opp['uuid'], 'won');

        $this->assertSame('won', $moved['stage']);
        $this->assertNotNull($moved['won_at']);
        $this->assertSame(100, $moved['probability']);

        $activity = $this->platform->activities()->forEntity('opportunity', $opp['uuid']);
        $types = array_column($activity, 'type');
        $this->assertContains('stage.changed', $types);
    }

    public function testLostStampsLostReason(): void
    {
        $crm = $this->platform->crm();
        $opp = $crm->createOpportunity(['title' => 'Deal']);
        $moved = $crm->moveOpportunity($opp['uuid'], 'lost', 'user', null, 'budget');
        $this->assertSame('budget', $moved['lost_reason']);
        $this->assertNotNull($moved['lost_at']);
    }

    public function testInvalidStageRejected(): void
    {
        $crm = $this->platform->crm();
        $opp = $crm->createOpportunity(['title' => 'Deal']);
        $this->expectException(InvalidArgumentException::class);
        $crm->moveOpportunity($opp['uuid'], 'nonsense');
    }

    public function testPipelineValueOnlyOpen(): void
    {
        $crm = $this->platform->crm();
        $a = $crm->createOpportunity(['title' => 'A', 'estimated_value' => 50000]);
        $b = $crm->createOpportunity(['title' => 'B', 'estimated_value' => 30000]);
        $crm->moveOpportunity($b['uuid'], 'won');
        $this->assertSame(50000, $this->platform->opportunities()->openPipelineValue());
    }
}
