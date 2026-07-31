<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Support\SafeUrl;
use PHPUnit\Framework\TestCase;

final class SafeUrlTest extends TestCase
{
    public function testRejectsNonHttps(): void
    {
        $this->assertSame('scheme_not_allowed', SafeUrl::validate('http://acme.com/', ['acme.com'])['error']);
        $this->assertSame('scheme_not_allowed', SafeUrl::validate('ftp://acme.com/', ['acme.com'])['error']);
        $this->assertSame('not_absolute_url', SafeUrl::validate('/just/a/path', ['acme.com'])['error']);
    }

    public function testRejectsUserinfoAndOddPorts(): void
    {
        $this->assertSame('userinfo_forbidden', SafeUrl::validate('https://user:pw@acme.com/', ['acme.com'])['error']);
        $this->assertSame('port_not_allowed', SafeUrl::validate('https://acme.com:22/', ['acme.com'])['error']);
    }

    public function testHostMustBeApproved(): void
    {
        $this->assertSame('host_not_approved', SafeUrl::validate('https://evil.com/', ['acme.com'])['error']);
        // Subdomain matches its registrable host…
        $this->assertTrue(SafeUrl::hostAllowed('www.acme.com', ['acme.com']));
        $this->assertTrue(SafeUrl::hostAllowed('acme.com', ['acme.com']));
        // …but a look-alike does NOT.
        $this->assertFalse(SafeUrl::hostAllowed('notacme.com', ['acme.com']));
        $this->assertFalse(SafeUrl::hostAllowed('acme.com.evil.com', ['acme.com']));
    }

    public function testBlocksPrivateAndLoopbackLiteralHosts(): void
    {
        // Literal internal IPs are blocked even if allow-listed as a host.
        $this->assertSame('private_address_blocked', SafeUrl::validate('https://127.0.0.1/', ['127.0.0.1'])['error']);
        $this->assertSame('private_address_blocked', SafeUrl::validate('https://10.0.0.5/', ['10.0.0.5'])['error']);
        $this->assertSame('private_address_blocked', SafeUrl::validate('https://169.254.169.254/latest/', ['169.254.169.254'])['error']);
        $this->assertSame('private_address_blocked', SafeUrl::validate('https://192.168.1.1/', ['192.168.1.1'])['error']);
    }

    public function testPubliclyRoutableClassification(): void
    {
        $this->assertTrue(SafeUrl::isPubliclyRoutableIp('8.8.8.8'));
        $this->assertTrue(SafeUrl::isPubliclyRoutableIp('1.1.1.1'));
        $this->assertFalse(SafeUrl::isPubliclyRoutableIp('127.0.0.1'));
        $this->assertFalse(SafeUrl::isPubliclyRoutableIp('10.1.2.3'));
        $this->assertFalse(SafeUrl::isPubliclyRoutableIp('172.16.9.9'));
        $this->assertFalse(SafeUrl::isPubliclyRoutableIp('::1'));
        // IPv4-mapped loopback smuggled in IPv6 is unwrapped and blocked.
        $this->assertFalse(SafeUrl::isPubliclyRoutableIp('::ffff:127.0.0.1'));
        $this->assertFalse(SafeUrl::isPubliclyRoutableIp('64:ff9b::7f00:1'));
    }
}
