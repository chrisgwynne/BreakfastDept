<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Mail\TemplateRegistry;
use PHPUnit\Framework\TestCase;

final class TemplateRegistryTest extends TestCase
{
    public function testBrevoTemplateIdFromConfig(): void
    {
        $r = new TemplateRegistry(['enquiry_ack' => '42']);
        $this->assertSame(42, $r->brevoTemplateId('enquiry_ack'));
        $this->assertNull($r->brevoTemplateId('crm_reply'));
    }

    public function testMissingRequiredParams(): void
    {
        $r = new TemplateRegistry();
        $this->assertSame(['enquiry_reference'], $r->missingParams('enquiry_internal', []));
        $this->assertSame([], $r->missingParams('enquiry_internal', ['enquiry_reference' => 'ENQ-1']));
    }

    public function testUnknownTemplate(): void
    {
        $this->assertFalse((new TemplateRegistry())->has('nope'));
    }
}
