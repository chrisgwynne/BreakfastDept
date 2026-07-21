<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * Generates URLs into the relocated "Breakfast Admin" (the Kirby Panel, moved to
 * a private, configurable slug). Never hard-code `/panel` anywhere; ask this
 * helper so a change to BREAKFAST_ADMIN_SLUG is reflected everywhere at once.
 *
 * The slug is a discoverability control, not a security boundary — authentication,
 * permissions, CSRF and server-side authorisation remain mandatory on every route.
 */
final class AdminUrl
{
    public const DEFAULT_SLUG = 'breakfast-admin';

    /**
     * Resolve the configured admin slug from the platform config, normalised to
     * lowercase [a-z0-9-]. Falls back to the default if unset or invalid.
     */
    public static function slug(): string
    {
        $slug = self::DEFAULT_SLUG;

        if (Platform::booted()) {
            $configured = Platform::instance()->config('adminSlug');
            if (is_string($configured) && $configured !== '') {
                $slug = $configured;
            }
        }

        $slug = strtolower($slug);
        $slug = (string) preg_replace('/[^a-z0-9-]/', '', $slug);

        return ($slug === '' || $slug === 'panel') ? self::DEFAULT_SLUG : $slug;
    }

    /**
     * Root-relative admin path, e.g. "/breakfast-admin" or
     * "/breakfast-admin/breakfast/leads".
     */
    public static function path(string $view = ''): string
    {
        $base = '/' . self::slug();
        $view = trim($view, '/');

        return $view === '' ? $base : $base . '/' . $view;
    }

    /**
     * Absolute admin URL using Kirby's configured base URL when available.
     */
    public static function url(string $view = ''): string
    {
        $path  = self::path($view);
        $kirby = \Kirby\Cms\App::instance(null, true);

        if ($kirby !== null) {
            return rtrim($kirby->url(), '/') . $path;
        }

        return $path;
    }
}
