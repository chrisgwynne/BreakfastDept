<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Builds public preview URLs. Three modes, chosen by config:
 *
 *  1. Subdomain (preferred, per-preview origin isolation): when
 *     CLIENT_PREVIEW_WILDCARD_BASE is set, each preview lives on its own origin,
 *     `https://{slug}.{wildcardBase}/` — so one preview's JavaScript can never
 *     read another (different browser origins), and its host-only cookie is
 *     naturally scoped to that one preview.
 *  2. Single host + path (CLIENT_PREVIEW_BASE_URL): `{baseUrl}/{slug}/`. Isolated
 *     from the admin/main site but all previews share one origin.
 *  3. Development path prefix on the main host: `{mainHost}/{devPrefix}/{slug}/`.
 *     INSUFFICIENT isolation — local work only.
 */
final class PreviewUrlGenerator
{
    /**
     * @param array<string,mixed> $config previews config block
     */
    public function __construct(private readonly array $config)
    {
    }

    public function subdomainMode(): bool
    {
        return $this->wildcardBase() !== '';
    }

    /**
     * Whether each preview gets its OWN browser origin (the strongest isolation).
     */
    public function isPerPreviewIsolated(): bool
    {
        return $this->subdomainMode();
    }

    /**
     * Whether previews are served off a dedicated origin (subdomain OR single
     * host), i.e. not co-hosted on the admin/main site.
     */
    public function hasIsolatedOrigin(): bool
    {
        return $this->subdomainMode() || $this->baseUrl() !== '';
    }

    /**
     * Absolute public URL for a preview slug (always trailing-slashed).
     */
    public function url(string $slug): string
    {
        $slug = strtolower(trim($slug, '/'));

        if ($this->subdomainMode()) {
            return $this->scheme() . '://' . $slug . '.' . $this->wildcardBase() . '/';
        }

        $base = $this->baseUrl();
        if ($base !== '') {
            return rtrim($base, '/') . '/' . $slug . '/';
        }

        // Development fallback: main host + prefix.
        $prefix = '/' . trim((string) ($this->config['devPrefix'] ?? '/_preview'), '/');

        return rtrim($this->mainHost(), '/') . $prefix . '/' . $slug . '/';
    }

    /**
     * The canonical host (no scheme) a preview should be served from. In subdomain
     * mode this is `{slug}.{wildcardBase}`; otherwise the shared preview host.
     */
    public function canonicalHost(string $slug): string
    {
        if ($this->subdomainMode()) {
            return strtolower(trim($slug, '/')) . '.' . $this->wildcardBase();
        }

        return $this->host();
    }

    public function wildcardBase(): string
    {
        return strtolower(trim((string) ($this->config['wildcardBase'] ?? '')));
    }

    /**
     * The configured shared preview host (no scheme), used for path-mode routing.
     */
    public function host(): string
    {
        $host = (string) ($this->config['host'] ?? '');
        if ($host !== '') {
            return $host;
        }

        $base = $this->baseUrl();
        if ($base !== '') {
            return (string) (parse_url($base, PHP_URL_HOST) ?: '');
        }

        return '';
    }

    private function scheme(): string
    {
        $scheme = strtolower(trim((string) ($this->config['scheme'] ?? '')));

        return in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['baseUrl'] ?? ''), '/');
    }

    private function mainHost(): string
    {
        $kirby = \Kirby\Cms\App::instance(null, true);

        return $kirby !== null ? rtrim($kirby->url(), '/') : 'http://localhost:8000';
    }
}
