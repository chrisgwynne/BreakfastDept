<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Parses and validates preview hostnames for per-preview origin isolation.
 *
 * In subdomain mode each preview lives on its own browser origin —
 * `{slug}.{wildcardBase}` (e.g. acme.preview.breakfastdept.com). Because the slug
 * is already constrained to exact DNS-label rules ([a-z0-9-], ≤63, no
 * leading/trailing/consecutive hyphens — see PreviewSlug), it doubles as the
 * subdomain label with no extra encoding. Multi-level subdomains, the apex host
 * and anything not matching an exact single-label prefix are rejected, so a
 * request host can never be spoofed into resolving to the wrong preview.
 */
final class PreviewHost
{
    /**
     * Extract the preview slug from a request host, or null if the host is not a
     * valid single-label subdomain of $wildcardBase.
     *
     * Rejects: the apex ($wildcardBase itself), multi-level subdomains
     * (a.b.base), empty labels, and labels that are not valid preview slugs.
     */
    public static function slugFromHost(?string $requestHost, string $wildcardBase): ?string
    {
        $wildcardBase = strtolower(trim($wildcardBase));
        if ($wildcardBase === '') {
            return null;
        }

        // Strip a port and lowercase.
        $host = strtolower(trim((string) $requestHost));
        $host = (string) preg_replace('/:\d+$/', '', $host);
        if ($host === '' || $host === $wildcardBase) {
            return null; // apex or empty — no preview
        }

        $suffix = '.' . $wildcardBase;
        if (str_ends_with($host, $suffix) === false) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));
        // Must be exactly ONE label (no dots) and a valid slug.
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }
        if (PreviewSlug::isValid($label) === false) {
            return null;
        }

        return $label;
    }

    /**
     * Whether a request host belongs to the preview wildcard zone (a subdomain
     * OR the apex). Used to decide whether to register the preview route.
     */
    public static function isPreviewZone(?string $requestHost, string $wildcardBase): bool
    {
        $wildcardBase = strtolower(trim($wildcardBase));
        if ($wildcardBase === '') {
            return false;
        }
        $host = (string) preg_replace('/:\d+$/', '', strtolower(trim((string) $requestHost)));

        return $host === $wildcardBase || str_ends_with($host, '.' . $wildcardBase);
    }
}
