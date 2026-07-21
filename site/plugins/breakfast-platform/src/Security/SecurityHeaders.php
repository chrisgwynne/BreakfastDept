<?php

declare(strict_types=1);

namespace Breakfast\Platform\Security;

/**
 * Builds the response security headers, including a nonce-based Content
 * Security Policy that avoids 'unsafe-inline' for scripts and 'unsafe-eval'
 * entirely. A per-request nonce is generated for the small amount of inline
 * bootstrap script the site needs (analytics + progressive enhancement flag).
 */
final class SecurityHeaders
{
    private string $nonce;

    public function __construct(private readonly bool $isProduction = true)
    {
        $this->nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    /**
     * @param list<string> $extraConnect additional connect-src hosts (e.g. analytics)
     * @param list<string> $extraScript additional script-src hosts
     * @return array<string,string>
     */
    public function headers(array $extraConnect = [], array $extraScript = []): array
    {
        return [
            'Content-Security-Policy'   => $this->csp($extraConnect, $extraScript),
            'X-Content-Type-Options'    => 'nosniff',
            'Referrer-Policy'           => 'strict-origin-when-cross-origin',
            'Permissions-Policy'        => 'geolocation=(), camera=(), microphone=(), payment=(), interest-cohort=()',
            'X-Frame-Options'           => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ] + ($this->isProduction ? [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ] : []);
    }

    /**
     * @param list<string> $extraConnect
     * @param list<string> $extraScript
     */
    public function csp(array $extraConnect = [], array $extraScript = []): string
    {
        $self   = "'self'";
        $script = array_merge([$self, "'nonce-" . $this->nonce . "'"], $extraScript);
        $connect = array_merge([$self], $extraConnect);

        $directives = [
            'default-src'     => [$self],
            'base-uri'        => [$self],
            'script-src'      => $script,
            // Styles are external files; 'unsafe-inline' kept only for style
            // attributes Kirby may emit. No inline <style> blocks are used.
            'style-src'       => [$self, "'unsafe-inline'"],
            'img-src'         => [$self, 'data:'],
            'font-src'        => [$self],
            'connect-src'     => $connect,
            'form-action'     => [$self],
            'frame-ancestors' => ["'none'"],
            'frame-src'       => [$self],
            'object-src'      => ["'none'"],
            'manifest-src'    => [$self],
        ];

        $parts = [];
        foreach ($directives as $name => $values) {
            $parts[] = $name . ' ' . implode(' ', array_unique($values));
        }

        return implode('; ', $parts);
    }
}
