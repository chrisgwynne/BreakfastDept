<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Hermes\Scopes;
use PHPUnit\Framework\TestCase;

final class ScopesTest extends TestCase
{
    public function testKnownScopesValid(): void
    {
        $this->assertTrue(Scopes::isValid(Scopes::CRM_READ));
        $this->assertTrue(Scopes::isValid('content:draft'));
    }
    public function testUnknownInvalid(): void
    {
        $this->assertFalse(Scopes::isValid('crm:destroy'));
    }
    public function testExportIsSeparatePrivilegedScope(): void
    {
        $this->assertContains('crm:export', Scopes::all());
    }
}
