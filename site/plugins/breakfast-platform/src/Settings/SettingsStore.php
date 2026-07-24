<?php

declare(strict_types=1);

namespace Breakfast\Platform\Settings;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;

/**
 * Key/value store for application-managed settings, with first-class support
 * for encrypted secrets.
 *
 * Plain settings round-trip as strings. Secrets are encrypted with SecretBox on
 * write and can only ever be read back in two forms: a masked hint (last four
 * characters) for display, or — strictly server-side, never through the API —
 * the decrypted value for use by the mail provider. The full secret is never
 * returned to a client, logged, or exposed in diagnostics.
 */
final class SettingsStore
{
    public function __construct(
        private readonly Database $db,
        private readonly SecretBox $box,
    ) {
    }

    public function get(string $name, string $default = ''): string
    {
        $row = $this->db->one('SELECT value, is_secret FROM platform_settings WHERE name = :n', ['n' => $name]);
        if ($row === null || (int) $row['is_secret'] === 1) {
            return $default;
        }

        return (string) $row['value'];
    }

    /** @return array<string,string> Non-secret settings under a "prefix." namespace. */
    public function allWithPrefix(string $prefix): array
    {
        $rows = $this->db->all(
            'SELECT name, value FROM platform_settings WHERE is_secret = 0 AND name LIKE :p',
            ['p' => $prefix . '%']
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['name']] = (string) $r['value'];
        }

        return $out;
    }

    public function set(string $name, string $value, string $actor): void
    {
        $this->upsert($name, $value, 0, $actor);
    }

    public function setSecret(string $name, string $plaintext, string $actor): void
    {
        $this->upsert($name, $this->box->encrypt($plaintext), 1, $actor);
    }

    public function clear(string $name, string $actor): void
    {
        $this->db->run('DELETE FROM platform_settings WHERE name = :n', ['n' => $name]);
    }

    public function has(string $name): bool
    {
        return $this->db->one('SELECT 1 FROM platform_settings WHERE name = :n', ['n' => $name]) !== null;
    }

    /**
     * A safe masked hint for a stored secret, e.g. "••••4F9C", or null if unset
     * or undecryptable. Only the last four characters are ever revealed.
     */
    public function secretHint(string $name): ?string
    {
        $plain = $this->revealSecret($name);
        if ($plain === null || $plain === '') {
            return null;
        }
        $tail = substr($plain, -4);

        return '••••' . $tail;
    }

    /**
     * Decrypt a stored secret for server-side use ONLY (e.g. the mail provider).
     * Never expose the return value through the API, logs, or diagnostics.
     */
    public function revealSecret(string $name): ?string
    {
        $row = $this->db->one('SELECT value, is_secret FROM platform_settings WHERE name = :n', ['n' => $name]);
        if ($row === null || (int) $row['is_secret'] !== 1) {
            return null;
        }

        return $this->box->decrypt((string) $row['value']);
    }

    private function upsert(string $name, string $value, int $isSecret, string $actor): void
    {
        $this->db->run(
            'INSERT INTO platform_settings (name, value, is_secret, updated_at, updated_by)
             VALUES (:n, :v, :s, :at, :by)
             ON CONFLICT (name) DO UPDATE SET value = :v, is_secret = :s, updated_at = :at, updated_by = :by',
            ['n' => $name, 'v' => $value, 's' => $isSecret, 'at' => Clock::nowIso(), 'by' => $actor]
        );
    }
}
