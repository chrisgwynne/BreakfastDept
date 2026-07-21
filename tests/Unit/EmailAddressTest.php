<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Mail\EmailAddress;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testValidAddress(): void
    {
        $a = EmailAddress::create('Person@Example.com', 'A Person');
        $this->assertSame('person@example.com', $a->email);
        $this->assertSame('A Person', $a->name);
    }

    public function testInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailAddress::create('not-an-email');
    }

    public function testTryCreateReturnsNull(): void
    {
        $this->assertNull(EmailAddress::tryCreate('nope'));
    }

    public function testHeaderInjectionRejected(): void
    {
        $this->assertNull(EmailAddress::tryCreate("a@b.com\r\nBcc: victim@evil.com"));
    }

    public function testNameHeaderInjectionStripped(): void
    {
        $a = EmailAddress::create('a@b.com', "Real\r\nBcc: x");
        $this->assertStringNotContainsString("\n", (string) $a->name);
        $this->assertStringNotContainsString("\r", (string) $a->name);
    }

    public function testRoundTrip(): void
    {
        $a = EmailAddress::create('a@b.com', 'Name');
        $this->assertEquals($a, EmailAddress::fromArray($a->toArray()));
    }
}
