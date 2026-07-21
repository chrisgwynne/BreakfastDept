<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Minimal typed JSON HTTP client used by the Brevo provider and webhook tooling.
 *
 * Kept tiny and dependency-free (curl) so it is trivially fakeable in tests via
 * a transport closure — no live network in CI. Enforces timeouts and never
 * follows redirects.
 */
final class HttpClient
{
    /** @var null|callable(string,string,array<string,string>,?string):HttpResponse */
    private static mixed $transport = null;

    public function __construct(
        private readonly int $timeout = 10,
        private readonly int $connectTimeout = 5,
    ) {
    }

    /**
     * @param array<string,string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (self::$transport !== null) {
            return (self::$transport)($method, $url, $headers, $body);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return new HttpResponse(0, '', 'curl_init_failed');
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch) ?: null;
        curl_close($ch);

        return new HttpResponse($status, is_string($raw) ? $raw : '', $err);
    }

    /**
     * @param null|callable(string,string,array<string,string>,?string):HttpResponse $transport
     * @internal test seam
     */
    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }
}
