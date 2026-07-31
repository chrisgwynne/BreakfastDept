<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * SSRF-hardened URL validator for outbound capture (screenshots).
 *
 * A capture target must be:
 *   - an absolute https:// URL (http allowed only when explicitly permitted),
 *   - on the standard web port (or a small allow-list),
 *   - with no userinfo (user:pass@) component,
 *   - whose host matches the approved host allow-list (exact host or a
 *     registrable-domain suffix), and
 *   - every resolved A/AAAA address is publicly routable — private, loopback,
 *     link-local (incl. 169.254.169.254 cloud metadata), CGNAT, reserved and
 *     IPv4-in-IPv6 smuggling are all rejected. Fails closed.
 *
 * Redirects are the driver's responsibility: the headless driver MUST re-run
 * {@see hostAllowed()} + {@see isPubliclyRoutableIp()} on every hop.
 */
final class SafeUrl
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::1/128', '::/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '2002::/16',
    ];

    private const ALLOWED_PORTS = [80, 443];

    /**
     * Validate a capture URL against an allow-list of hosts.
     *
     * @param list<string> $allowedHosts approved hosts (e.g. ['acme.com', 'www.acme.com'])
     * @param bool         $allowHttp     permit http:// (default https-only)
     * @return array{ok:bool,error?:string,host?:string,ips?:list<string>}
     */
    public static function validate(string $url, array $allowedHosts, bool $allowHttp = false): array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return ['ok' => false, 'error' => 'not_absolute_url'];
        }
        $scheme = strtolower((string) $parts['scheme']);
        $allowedSchemes = $allowHttp ? ['http', 'https'] : ['https'];
        if (!in_array($scheme, $allowedSchemes, true)) {
            return ['ok' => false, 'error' => 'scheme_not_allowed'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'error' => 'userinfo_forbidden'];
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            return ['ok' => false, 'error' => 'port_not_allowed'];
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '') {
            return ['ok' => false, 'error' => 'empty_host'];
        }
        if (!self::hostAllowed($host, $allowedHosts)) {
            return ['ok' => false, 'error' => 'host_not_approved'];
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            return ['ok' => false, 'error' => 'unresolvable_host'];
        }
        foreach ($ips as $ip) {
            if (!self::isPubliclyRoutableIp($ip)) {
                return ['ok' => false, 'error' => 'private_address_blocked'];
            }
        }

        return ['ok' => true, 'host' => $host, 'ips' => $ips];
    }

    /**
     * Host is allowed if it exactly equals an entry, or is a subdomain of one
     * (suffix match on a dot boundary — "a.acme.com" matches "acme.com" but
     * "notacme.com" does not).
     *
     * @param list<string> $allowedHosts
     */
    public static function hostAllowed(string $host, array $allowedHosts): bool
    {
        $host = strtolower(trim($host, '.'));
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim((string) $allowed, '.'));
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a host to every A/AAAA address, or return [$host] if it is already
     * a literal IP. Empty when unresolvable.
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ip'])) {
                $ips[] = (string) $r['ip'];
            }
            if (!empty($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : [];
        }

        return $ips;
    }

    /** True only if $ip is a valid, publicly-routable unicast address. */
    public static function isPubliclyRoutableIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $mapped = self::embeddedV4($ip);
            if ($mapped !== null) {
                $ip = $mapped;
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function embeddedV4(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }
        if (self::ipInCidr($ip, '::ffff:0:0/96') || self::ipInCidr($ip, '64:ff9b::/96') || self::ipInCidr($ip, '::/96')) {
            $v4 = @inet_ntop(substr($bin, 12, 4));

            return $v4 !== false ? $v4 : null;
        }

        return null;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsRaw] = explode('/', $cidr);
        $ipBin  = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $bits  = (int) $bitsRaw;
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        if ($rem > 0) {
            $mask = 0xFF << (8 - $rem) & 0xFF;

            return (ord($ipBin[$bytes]) & $mask) === (ord($subBin[$bytes]) & $mask);
        }

        return true;
    }
}
