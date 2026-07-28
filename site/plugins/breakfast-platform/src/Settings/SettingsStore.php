<?php

declare(strict_types=1);

namespace Breakfast\Platform\Settings;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;

/**
 * Key/value store for application-managed settings, with first-class support
 * for encrypted secrets.
 *
 * Plain settings round-trip as strings. Secrets are encrypted with SecretBox on
 * write and can only ever be read back in two forms: a masked hint (last four
 * characters) for display, or — strictly server-side, never through the API —
 * the decrypted value for use by the mail provider. The full secret is never
 * returned to a client, logged, or exposed in diagnostics.
 *
 * Each setting is one flat-file record keyed on a hash of its name (names carry
 * dots the file store would otherwise flatten).
 */
final class SettingsStore
{
    private const COLLECTION = 'platform_settings';

    public function __construct(
        private readonly FileStore $store,
        private readonly SecretBox $box,
    ) {
    }

    public function get(string $name, string $default = ''): string
    {
        $row = $this->store->find(self::COLLECTION, $this->id($name));
        if ($row === null || (int) ($row['is_secret'] ?? 0) === 1) {
            return $default;
        }

        return (string) ($row['value'] ?? '');
    }

    /** @return array<string,string> Non-secret settings under a "prefix." namespace. */
    public function allWithPrefix(string $prefix): array
    {
        $out = [];
        foreach ($this->store->all(self::COLLECTION) as $r) {
            $name = (string) ($r['name'] ?? '');
            if ((int) ($r['is_secret'] ?? 0) === 0 && str_starts_with($name, $prefix)) {
                $out[$name] = (string) ($r['value'] ?? '');
            }
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
        $this->store->delete(self::COLLECTION, $this->id($name));
    }

    public function has(string $name): bool
    {
        return $this->store->exists(self::COLLECTION, $this->id($name));
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
        $row = $this->store->find(self::COLLECTION, $this->id($name));
        if ($row === null || (int) ($row['is_secret'] ?? 0) !== 1) {
            return null;
        }

        return $this->box->decrypt((string) ($row['value'] ?? ''));
    }

    private function upsert(string $name, string $value, int $isSecret, string $actor): void
    {
        $this->store->put(self::COLLECTION, [
            'uuid'       => $this->id($name),
            'name'       => $name,
            'value'      => $value,
            'is_secret'  => $isSecret,
            'updated_at' => Clock::nowIso(),
            'updated_by' => $actor,
        ]);
    }

    /** Deterministic record id for a setting name (which may contain dots). */
    private function id(string $name): string
    {
        return sha1($name);
    }
}
