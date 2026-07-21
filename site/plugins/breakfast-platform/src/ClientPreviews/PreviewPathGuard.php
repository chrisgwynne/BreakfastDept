<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * The single source of truth for "what may a preview contain, and how is a path
 * resolved safely". Used both at upload time (validation) and at serve time
 * (the responder). It is deny-by-default: only allow-listed extensions pass, and
 * every path is normalised and re-checked before it is ever touched on disk.
 */
final class PreviewPathGuard
{
    /**
     * Allow-listed extension => MIME type. Anything not in this map is rejected.
     * SVG is included but is treated as ACTIVE content: it is sanitised at upload
     * time AND served from the isolated preview origin, whose CSP blocks objects,
     * workers and cross-origin framing (it does allow inline scripts so static
     * mock-ups run, so sanitisation is a real control here, not merely redundant).
     *
     * @var array<string,string>
     */
    public const ALLOWED = [
        'html'  => 'text/html; charset=utf-8',
        'htm'   => 'text/html; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'text/javascript; charset=utf-8',
        'mjs'   => 'text/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'txt'   => 'text/plain; charset=utf-8',
        'xml'   => 'application/xml; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'map'   => 'application/json; charset=utf-8',
    ];

    /** Video types, only permitted when the preview opts in (config.allowVideo). */
    public const VIDEO = [
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
    ];

    /**
     * Explicitly executable / dangerous extensions. The allow-list already
     * excludes these; the deny-list is defence in depth and drives clear errors.
     *
     * @var list<string>
     */
    public const FORBIDDEN_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht',
        'phar', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll', 'so',
        'jsp', 'asp', 'aspx', 'jspx', 'com', 'bat', 'cmd', 'ps1', 'wasm',
    ];

    /**
     * File/segment names that must never appear (server config, secrets, VCS).
     *
     * @var list<string>
     */
    public const FORBIDDEN_NAMES = [
        '.htaccess', '.htpasswd', '.user.ini', 'web.config', '.env', '.git',
        '.gitignore', '.gitmodules', '.npmrc', '.dockerignore', 'dockerfile',
        '.ds_store', 'thumbs.db', 'desktop.ini',
    ];

    /**
     * Service-worker file names. Blocked so a preview cannot register a SW that
     * could persist or control a later preview at the same origin/path.
     *
     * @var list<string>
     */
    public const SERVICE_WORKER_NAMES = [
        'sw.js', 'service-worker.js', 'serviceworker.js', 'workbox-sw.js',
    ];

    /**
     * Normalise a relative path from inside a package. Returns the cleaned path
     * (forward slashes, no leading slash) or null if it is unsafe: absolute,
     * traversal (`..`), null byte, backslash, drive letter or UNC.
     */
    public static function normaliseRelative(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        // Reject Windows separators, drive letters and UNC before anything else.
        if (str_contains($path, '\\') || preg_match('#^[a-zA-Z]:#', $path) === 1) {
            return null;
        }

        $path = ltrim($path, '/');
        $segments = explode('/', $path);
        $clean = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null; // never allow traversal
            }
            $clean[] = $segment;
        }

        if ($clean === []) {
            return null;
        }

        return implode('/', $clean);
    }

    public static function extension(string $path): string
    {
        $base = strtolower(basename($path));
        $dot  = strrpos($base, '.');

        return $dot === false ? '' : substr($base, $dot + 1);
    }

    /**
     * Whether a relative path is an allowed file to store/serve.
     *
     * @param bool $allowVideo permit the opt-in video types
     */
    public static function isAllowedFile(string $relativePath, bool $allowVideo = false): bool
    {
        $norm = self::normaliseRelative($relativePath);
        if ($norm === null) {
            return false;
        }

        foreach (explode('/', $norm) as $segment) {
            $lower = strtolower($segment);
            // No dotfiles/dotdirs and no forbidden names anywhere in the path.
            if ($lower !== '' && $lower[0] === '.') {
                return false;
            }
            if (in_array($lower, self::FORBIDDEN_NAMES, true)) {
                return false;
            }
        }

        $ext = self::extension($norm);
        if ($ext === '' || in_array($ext, self::FORBIDDEN_EXT, true)) {
            return false;
        }

        $allowed = self::ALLOWED;
        if ($allowVideo) {
            $allowed += self::VIDEO;
        }

        return isset($allowed[$ext]);
    }

    /**
     * MIME type for an allowed path, or null if not allowed.
     */
    public static function mimeFor(string $relativePath, bool $allowVideo = false): ?string
    {
        if (self::isAllowedFile($relativePath, $allowVideo) === false) {
            return null;
        }

        $ext     = self::extension($relativePath);
        $allowed = $allowVideo ? self::ALLOWED + self::VIDEO : self::ALLOWED;

        return $allowed[$ext] ?? null;
    }

    public static function isServiceWorkerName(string $path): bool
    {
        return in_array(strtolower(basename($path)), self::SERVICE_WORKER_NAMES, true);
    }

    /**
     * Resolve a served request path against a version's files directory,
     * guaranteeing the result stays inside that directory. Returns the absolute
     * file path or null (404) — never throws, never escapes.
     */
    public static function resolveServed(string $filesDir, string $requestPath): ?string
    {
        $rel = self::normaliseRelative($requestPath === '' ? 'index.html' : $requestPath);
        if ($rel === null) {
            return null;
        }

        $base = rtrim($filesDir, '/');
        $full = $base . '/' . $rel;

        // The parent dir must exist to realpath; guard first on the normalised
        // form, then confirm with realpath containment.
        $real     = realpath($full);
        $realBase = realpath($base);
        if ($real === false || $realBase === false) {
            return null;
        }
        if ($real !== $realBase && str_starts_with($real, $realBase . '/') === false) {
            return null;
        }
        if (is_file($real) === false) {
            return null;
        }

        return $real;
    }
}
