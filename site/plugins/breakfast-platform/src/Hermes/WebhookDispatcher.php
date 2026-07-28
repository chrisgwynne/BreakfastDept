<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

use Breakfast\Platform\Queue\Queue;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Outbound webhook system.
 *
 * dispatch() is called from application code (e.g. the form pipeline). It NEVER
 * performs HTTP inline — it records a signed, versioned delivery per subscribed
 * endpoint and enqueues a queue job. The queue worker later calls deliver(),
 * which does the HTTP POST with retries/backoff (handled by the queue) and
 * disables endpoints after repeated failures.
 */
final class WebhookDispatcher
{
    public const SCHEMA_VERSION = '1';

    private const DISABLE_AFTER = 10;

    /** Cap the response we read from an endpoint so a hostile server can't OOM the worker. */
    private const MAX_RESPONSE_BYTES = 256 * 1024;

    /**
     * IPv4 ranges an outbound webhook must never reach (SSRF guard): private,
     * loopback, link-local (incl. 169.254.169.254 cloud metadata), CGNAT,
     * TEST-NET, benchmarking, multicast and reserved space.
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /**
     * IPv6 ranges an outbound webhook must never reach. 6to4 (2002::/16) is
     * blocked outright (deprecated; embeds an arbitrary IPv4 that could be
     * internal). IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96) and NAT64
     * (64:ff9b::/96) are unwrapped to their embedded IPv4 in embeddedV4() and
     * checked against BLOCKED_V4 instead.
     */
    private const BLOCKED_V6 = [
        '::1/128', '::/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '2002::/16',
    ];

    /** @var null|callable(string,string,array<string,string>):array{status:int,error:?string} */
    private static mixed $httpClient = null;

    public function __construct(
        private readonly FileStore $store,
        private readonly Queue $queue,
        private readonly string $signingSecret
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function dispatch(string $eventType, array $data): void
    {
        $endpoints = $this->activeEndpointsFor($eventType);

        foreach ($endpoints as $endpoint) {
            $eventUuid = Uuid::v4();
            $payload   = [
                'id'             => $eventUuid,
                'type'           => $eventType,
                'schema_version' => self::SCHEMA_VERSION,
                'created_at'     => Clock::nowIso(),
                'data'           => $data,
            ];

            $deliveryUuid = Uuid::v4();
            $this->store->put('webhook_deliveries', [
                'uuid'           => $deliveryUuid,
                'endpoint_uuid'  => $endpoint['uuid'],
                'event_uuid'     => $eventUuid,
                'event_type'     => $eventType,
                'schema_version' => self::SCHEMA_VERSION,
                'payload'        => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
                'status'         => 'pending',
                'attempts'       => 0,
                'response_code'  => null,
                'last_error'     => null,
                'delivered_at'   => null,
                'created_at'     => Clock::nowIso(),
            ]);

            // Enqueue — delivery happens in the worker, never in this request.
            $this->queue->push('webhook.deliver', ['delivery_uuid' => $deliveryUuid], 'webhook:' . $deliveryUuid);
        }
    }

    /**
     * Perform the actual HTTP delivery for one delivery row. Called by the queue
     * worker. Throws on failure so the queue applies backoff/retry.
     */
    public function deliver(string $deliveryUuid): void
    {
        $delivery = $this->store->find('webhook_deliveries', $deliveryUuid);

        if ($delivery === null || ($delivery['status'] ?? '') === 'delivered') {
            return;
        }

        $endpoint = $this->store->find('webhook_endpoints', (string) $delivery['endpoint_uuid']);
        if ($endpoint === null || (int) ($endpoint['active'] ?? 0) !== 1) {
            $this->store->update('webhook_deliveries', $deliveryUuid, static function (array $r): array {
                $r['status']     = 'failed';
                $r['last_error'] = 'endpoint_inactive';

                return $r;
            });

            return;
        }

        $body      = (string) $delivery['payload'];
        $timestamp = (string) Clock::timestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $this->signingSecret);

        $headers = [
            'Content-Type'          => 'application/json',
            'X-Breakfast-Event'     => (string) $delivery['event_type'],
            'X-Breakfast-Event-Id'  => (string) $delivery['event_uuid'],
            'X-Breakfast-Timestamp' => $timestamp,
            'X-Breakfast-Signature' => $signature,
            'X-Breakfast-Schema'    => (string) $delivery['schema_version'],
        ];

        $result = $this->post((string) $endpoint['url'], $body, $headers);
        $attempts = (int) $delivery['attempts'] + 1;

        if ($result['status'] >= 200 && $result['status'] < 300) {
            $this->store->update('webhook_deliveries', $deliveryUuid, static function (array $r) use ($attempts, $result): array {
                $r['status']        = 'delivered';
                $r['attempts']      = $attempts;
                $r['response_code'] = $result['status'];
                $r['delivered_at']  = Clock::nowIso();

                return $r;
            });
            $this->store->update('webhook_endpoints', (string) $endpoint['uuid'], static function (array $r): array {
                $r['consecutive_fails'] = 0;
                $r['updated_at']        = Clock::nowIso();

                return $r;
            });

            return;
        }

        // Record the failed attempt and possibly disable the endpoint.
        $this->store->update('webhook_deliveries', $deliveryUuid, static function (array $r) use ($attempts, $result): array {
            $r['attempts']      = $attempts;
            $r['response_code'] = $result['status'] ?: null;
            $r['last_error']    = $result['error'] ?? 'http_error';

            return $r;
        });

        $fails = (int) ($endpoint['consecutive_fails'] ?? 0) + 1;
        $this->store->update('webhook_endpoints', (string) $endpoint['uuid'], static function (array $r) use ($fails): array {
            $r['consecutive_fails'] = $fails;
            $r['updated_at']        = Clock::nowIso();
            if ($fails >= self::DISABLE_AFTER) {
                $r['active']          = 0;
                $r['disabled_reason'] = 'repeated_failures';
            }

            return $r;
        });

        // Signal the queue to retry.
        throw new \RuntimeException('Webhook delivery failed: HTTP ' . $result['status']);
    }

    /**
     * Register (or update) an outbound endpoint.
     *
     * @param list<string> $events
     */
    public function registerEndpoint(string $label, string $url, array $events): string
    {
        self::assertRegistrableUrl($url);
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();

        $this->store->put('webhook_endpoints', [
            'uuid'              => $uuid,
            'label'             => $label,
            'url'               => $url,
            'events'            => json_encode(array_values($events)) ?: '["*"]',
            'active'            => 1,
            'consecutive_fails' => 0,
            'disabled_reason'   => null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return $uuid;
    }

    /** Re-enqueue a delivery for redelivery from the Panel. */
    public function redeliver(string $deliveryUuid): void
    {
        $this->store->update('webhook_deliveries', $deliveryUuid, static function (array $r): array {
            $r['status']     = 'pending';
            $r['last_error'] = null;

            return $r;
        });
        $this->queue->push('webhook.deliver', ['delivery_uuid' => $deliveryUuid]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function activeEndpointsFor(string $eventType): array
    {
        $rows = array_filter($this->store->all('webhook_endpoints'), static fn (array $r): bool => (int) ($r['active'] ?? 0) === 1);
        $out  = [];

        foreach ($rows as $row) {
            $events = json_decode((string) $row['events'], true);
            $events = is_array($events) ? $events : ['*'];

            if (in_array('*', $events, true) || in_array($eventType, $events, true)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,error:?string}
     */
    private function post(string $url, string $body, array $headers): array
    {
        if (self::$httpClient !== null) {
            return (self::$httpClient)($url, $body, $headers);
        }

        // SSRF guard: resolve the target ourselves, reject any host that maps to
        // a private/loopback/link-local/reserved address, and pin curl to the
        // validated IP so it cannot be re-resolved (DNS rebinding) between our
        // check and the connection.
        $pin = $this->resolveSafeTarget($url);
        if ($pin === null) {
            return ['status' => 0, 'error' => 'blocked_url'];
        }

        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $received = 0;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            // Never follow redirects — a 302 to an internal address would be a
            // trivial SSRF bypass — and restrict the scheme to http(s).
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [$pin['host'] . ':' . $pin['port'] . ':' . $pin['ip']],
            // Cap the response so a hostile endpoint cannot exhaust memory.
            CURLOPT_WRITEFUNCTION  => static function ($_ch, string $chunk) use (&$received): int {
                $received += strlen($chunk);

                return $received > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch) ?: null;
        curl_close($ch);

        return ['status' => $status, 'error' => $error];
    }

    /**
     * Parse + resolve a delivery URL and, if every resolved address is publicly
     * routable, return the host/port/IP to pin the connection to. Returns null
     * (delivery blocked) for a bad scheme, an unresolvable host, or any host
     * that resolves to a blocked address.
     *
     * @return array{host:string,port:int,ip:string}|null
     */
    private function resolveSafeTarget(string $url): ?array
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = trim((string) ($parts['host'] ?? ''), '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Collect every address the host resolves to (literal IP or DNS).
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
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
        }
        if ($ips === []) {
            return null; // unresolvable → do not connect
        }

        // Fail closed: if ANY resolved address is internal, block the delivery.
        foreach ($ips as $ip) {
            if (!self::isPubliclyRoutableIp($ip)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** Reject obviously-unsafe URLs at registration time (literal internal IPs, bad scheme). */
    private static function assertRegistrableUrl(string $url): void
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = trim((string) ($parts['host'] ?? ''), '[]');
        if ($parts === false || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Webhook URL must be an absolute http(s) URL.');
        }
        // A literal-IP host must be publicly routable. Hostnames are resolved
        // (and re-checked) at delivery time, not here.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && !self::isPubliclyRoutableIp($host)) {
            throw new \InvalidArgumentException('Webhook URL may not target a private or reserved address.');
        }
    }

    /**
     * True only if $ip is a valid, publicly-routable unicast address. IPv4-mapped
     * and NAT64 IPv6 addresses are unwrapped to their embedded IPv4 first so a
     * blocked v4 range can't be smuggled inside a v6 literal.
     */
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

    /** Extract the embedded IPv4 from an IPv4-mapped (::ffff:0:0/96) or NAT64 (64:ff9b::/96) address. */
    private static function embeddedV4(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }
        // IPv4-mapped, IPv4-compatible (::/96, e.g. ::7f00:1 = 127.0.0.1) and
        // NAT64 all carry the embedded IPv4 in the low 4 bytes.
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

    /** @internal test seam */
    public static function useHttpClient(?callable $client): void
    {
        self::$httpClient = $client;
    }
}
