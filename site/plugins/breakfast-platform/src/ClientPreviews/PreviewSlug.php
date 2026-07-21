<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Public preview slug rules.
 *
 * A slug is the last path/subdomain segment of a preview's public URL. It must be
 * predictable, collision-free and never able to shadow a reserved route. This
 * class only enforces *format* and *reserved words*; DB uniqueness (case-
 * insensitive) and immediate-reuse-of-a-previous-slug are enforced by the
 * repository/service against the database.
 */
final class PreviewSlug
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 63;

    /**
     * Words a preview slug may never take — these would collide with platform
     * routes, the admin, integration endpoints or well-known files.
     *
     * @var list<string>
     */
    public const RESERVED = [
        'admin', 'breakfast-admin', 'panel', 'api', 'assets', 'media', 'static',
        'index', 'robots', 'sitemap', 'favicon', 'well-known', 'www', 'mail',
        'ftp', 'smtp', 'preview', 'previews', 'login', 'logout', 'account',
        'accounts', 'system', 'health', 'status', 'webhooks', 'hermes', 'brevo',
        'crm', 'dashboard', 'ops', 'null', 'undefined', 'test', 'security',
        'password', 'reset',
    ];

    /**
     * Normalise arbitrary operator input toward a valid slug: lowercase, ASCII,
     * spaces/underscores to hyphens, illegal characters dropped, hyphens
     * collapsed and trimmed. May still be invalid (e.g. reserved or too short) —
     * always follow with validate().
     */
    public static function normalise(string $input): string
    {
        $slug = strtolower(trim($input));
        $slug = (string) preg_replace('/[\s_]+/', '-', $slug);
        $slug = (string) preg_replace('/[^a-z0-9-]/', '', $slug);
        $slug = (string) preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Validate a slug's format and reserved-word status.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function validate(string $slug): array
    {
        if ($slug !== self::normalise($slug)) {
            return ['ok' => false, 'error' => 'not_normalised'];
        }
        if ($slug === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        if (strlen($slug) < self::MIN_LENGTH) {
            return ['ok' => false, 'error' => 'too_short'];
        }
        if (strlen($slug) > self::MAX_LENGTH) {
            return ['ok' => false, 'error' => 'too_long'];
        }
        // No leading/trailing/consecutive hyphens. (normalise() already collapses
        // these, so a non-conforming input is first caught as `not_normalised`;
        // this guards direct validate() calls on hand-built slugs.)
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            return ['ok' => false, 'error' => 'invalid_format'];
        }
        if (in_array($slug, self::RESERVED, true)) {
            return ['ok' => false, 'error' => 'reserved'];
        }

        return ['ok' => true];
    }

    public static function isValid(string $slug): bool
    {
        return self::validate($slug)['ok'];
    }
}
