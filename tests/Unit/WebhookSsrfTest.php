<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Hermes\WebhookDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security regression (audit): outbound webhooks must not be usable as an SSRF
 * primitive. The address classifier is the core of the guard — it must reject
 * private, loopback, link-local (incl. 169.254.169.254 cloud metadata), CGNAT
 * and reserved addresses, including IPv4-mapped IPv6 forms, while still allowing
 * genuinely public addresses.
 */
final class WebhookSsrfTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function blockedIps(): array
    {
        return array_map(static fn ($ip) => [$ip], [
            '127.0.0.1', '127.0.0.53', '0.0.0.0', '10.0.0.5', '10.255.255.255',
            '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254',
            '169.254.0.1', '100.64.0.1', '100.127.255.255', '192.0.2.10',
            '198.18.0.1', '255.255.255.255', '224.0.0.1',
            '::1', '::', 'fe80::1', 'fc00::1', 'fd00::abcd', 'ff02::1',
            '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:10.0.0.1',
            '64:ff9b::7f00:1',   // NAT64-wrapped 127.0.0.1
            '::7f00:1',          // IPv4-compatible 127.0.0.1
            '::0a00:1',          // IPv4-compatible 10.0.0.1
            '2002:7f00:1::1',    // 6to4 wrapping 127.0.0.1 (whole 2002::/16 blocked)
            '2002:a00:1::',      // 6to4 wrapping 10.0.0.1
        ]);
    }

    /** @return list<array{0:string}> */
    public static function publicIps(): array
    {
        return array_map(static fn ($ip) => [$ip], [
            '8.8.8.8', '1.1.1.1', '93.184.216.34', '13.107.42.14',
            '2606:4700:4700::1111', '2001:4860:4860::8888', '::ffff:8.8.8.8',
        ]);
    }

    #[DataProvider('blockedIps')]
    public function testInternalAddressesAreBlocked(string $ip): void
    {
        $this->assertFalse(
            WebhookDispatcher::isPubliclyRoutableIp($ip),
            "$ip must be treated as non-routable (SSRF guard)"
        );
    }

    #[DataProvider('publicIps')]
    public function testPublicAddressesAreAllowed(string $ip): void
    {
        $this->assertTrue(
            WebhookDispatcher::isPubliclyRoutableIp($ip),
            "$ip should be allowed as a public destination"
        );
    }

    public function testGarbageIsRejected(): void
    {
        $this->assertFalse(WebhookDispatcher::isPubliclyRoutableIp('not-an-ip'));
        $this->assertFalse(WebhookDispatcher::isPubliclyRoutableIp(''));
    }
}
