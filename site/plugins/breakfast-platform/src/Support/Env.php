<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * Minimal, dependency-free .env loader.
 *
 * Secrets are read from the process environment only. In development a `.env`
 * file at the project base is loaded once into the process; in production the
 * host is expected to inject real environment variables and the file is absent.
 */
final class Env
{
    private static bool $loaded = false;

    /** @var array<string,string> */
    private static array $cache = [];

    public static function load(string $baseDir): void
    {
        if (self::$loaded === true) {
            return;
        }

        self::$loaded = true;
        $path = rtrim($baseDir, '/') . '/.env';

        if (is_file($path) === false || is_readable($path) === false) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = self::unquote(trim($value));

            if ($key === '') {
                continue;
            }

            // Real environment always wins over the file.
            if (getenv($key) === false && isset($_ENV[$key]) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            // An empty cached value means "unset" — fall back to the default
            // (an empty string must not shadow a caller-supplied default).
            $cached = self::$cache[$key];

            return $cached === '' ? $default : $cached;
        }

        $value = getenv($key);

        if ($value === false && isset($_ENV[$key])) {
            $value = (string) $_ENV[$key];
        }

        if ($value === false || $value === '') {
            self::$cache[$key] = '';

            return $default;
        }

        self::$cache[$key] = $value;

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int) $value;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /** @internal test seam */
    public static function reset(): void
    {
        self::$loaded = false;
        self::$cache  = [];
    }
}
