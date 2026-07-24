<?php

declare(strict_types=1);

namespace Breakfast\Platform\Vault;

use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Secure credential vault.
 *
 * Sensitive field values are field-level encrypted (key-versioned, authenticated)
 * and NEVER returned by list/find — only masked hints. Revealing or copying a
 * secret requires a recent step-up re-authentication and is audited; the secret
 * value itself is never written to logs, audit descriptions or exceptions.
 * Editing a secret snapshots the previous encrypted value as an immutable
 * version. Key rotation re-encrypts every field to the new current version.
 */
final class Vault
{
    /** @var list<string> */
    public const SENSITIVE_FIELDS = ['password', 'api_key', 'token', 'recovery_code', 'private_key', 'db_password', 'secure_note'];

    private const REAUTH_TTL = 300; // seconds a step-up session stays valid

    public function __construct(
        private readonly Database $db,
        private readonly VaultCrypto $crypto,
        private readonly AuditLog $audit,
    ) {
    }

    // ==================================================================
    // Re-authentication (step-up)
    // ==================================================================

    /** Record a fresh step-up session for a user (called after a verified password check). */
    public function grantReauth(string $userEmail): void
    {
        $this->db->run('UPDATE vault_reauth_sessions SET revoked = 1 WHERE user_email = :u AND revoked = 0', ['u' => $userEmail]);
        $this->db->run('INSERT INTO vault_reauth_sessions (uuid, user_email, expires_at, created_at) VALUES (:uuid, :u, :exp, :now)', ['uuid' => Uuid::v4(), 'u' => $userEmail, 'exp' => date('c', time() + self::REAUTH_TTL), 'now' => Clock::nowIso()]);
    }

    public function revokeReauth(string $userEmail): void
    {
        $this->db->run('UPDATE vault_reauth_sessions SET revoked = 1 WHERE user_email = :u', ['u' => $userEmail]);
    }

    public function hasValidReauth(string $userEmail): bool
    {
        $row = $this->db->one("SELECT expires_at FROM vault_reauth_sessions WHERE user_email = :u AND revoked = 0 ORDER BY created_at DESC LIMIT 1", ['u' => $userEmail]);
        if ($row === null) {
            return false;
        }
        $exp = strtotime((string) $row['expires_at']);

        return $exp !== false && $exp > time();
    }

    // ==================================================================
    // Read (masked only)
    // ==================================================================

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['archived = 0'];
        $params = [];
        if (!empty($filters['company_uuid'])) {
            $where[] = 'company_uuid = :c';
            $params['c'] = (string) $filters['company_uuid'];
        }
        if (!empty($filters['project_uuid'])) {
            $where[] = 'project_uuid = :p';
            $params['p'] = (string) $filters['project_uuid'];
        }
        $rows = $this->db->all('SELECT * FROM vault_items WHERE ' . implode(' AND ', $where) . ' ORDER BY label ASC LIMIT 500', $params);

        return array_map(fn (array $i): array => $this->maskItem($i, false), $rows);
    }

    /**
     * Metadata + MASKED fields (hints only). Never returns ciphertext or plaintext.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $uuid): ?array
    {
        $i = $this->db->one('SELECT * FROM vault_items WHERE uuid = :u', ['u' => $uuid]);

        return $i === null ? null : $this->maskItem($i, true);
    }

    // ==================================================================
    // Create / edit metadata + secrets
    // ==================================================================

    /**
     * @param array<string,mixed> $data label, item_type, company_uuid, project_uuid, url, account_id, username, notes, fields[]
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new VaultException(422, 'Enter a label.');
        }

        return $this->db->transaction(function (Database $db) use ($data, $label, $actor): array {
            $uuid = Uuid::v4();
            $now = Clock::nowIso();
            $db->run(
                'INSERT INTO vault_items (uuid, label, item_type, company_uuid, project_uuid, url, account_id, username, owner, status, tags, notes, expiry, created_by, created_at, updated_at)
                 VALUES (:uuid, :label, :type, :company, :project, :url, :account, :username, :owner, \'active\', :tags, :notes, :expiry, :actor, :now, :now)',
                [
                    'uuid' => $uuid, 'label' => $label, 'type' => (string) ($data['item_type'] ?? 'other'),
                    'company' => $this->nullable($data['company_uuid'] ?? null), 'project' => $this->nullable($data['project_uuid'] ?? null),
                    'url' => (string) ($data['url'] ?? ''), 'account' => (string) ($data['account_id'] ?? ''), 'username' => (string) ($data['username'] ?? ''),
                    'owner' => (string) ($data['owner'] ?? $actor), 'tags' => json_encode(is_array($data['tags'] ?? null) ? array_values($data['tags']) : [], JSON_UNESCAPED_SLASHES) ?: '[]',
                    'notes' => (string) ($data['notes'] ?? ''), 'expiry' => $this->nullable($data['expiry'] ?? null), 'actor' => $actor, 'now' => $now,
                ]
            );
            foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
                if (is_array($field) && !empty($field['fkey']) && isset($field['value']) && (string) $field['value'] !== '') {
                    $this->putField($db, $uuid, (string) $field['fkey'], (string) ($field['label'] ?? ''), (string) $field['value'], $actor, 'created');
                }
            }
            $this->accessEvent($db, $uuid, '', 'create', $actor);
            $this->audit->event('vault.created', 'vault', $uuid, $actor, ['label' => $label]);

            return $this->find($uuid) ?? [];
        });
    }

    /**
     * Update non-secret metadata (optimistic concurrency).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updateMetadata(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $item = $this->db->one('SELECT revision FROM vault_items WHERE uuid = :u', ['u' => $uuid]);
        if ($item === null) {
            throw new VaultException(404, 'Vault item not found.');
        }
        if ($expectedRevision !== null && (int) $item['revision'] !== $expectedRevision) {
            throw new VaultException(409, 'This item was changed by someone else. Reload and try again.');
        }
        $sets = ['updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach (['label', 'item_type', 'url', 'account_id', 'username', 'owner', 'status', 'notes'] as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[$f] = (string) $data[$f];
            }
        }
        if (array_key_exists('expiry', $data)) {
            $sets[] = 'expiry = :expiry';
            $params['expiry'] = $this->nullable($data['expiry']);
        }
        if (array_key_exists('tags', $data) && is_array($data['tags'])) {
            $sets[] = 'tags = :tags';
            $params['tags'] = json_encode(array_values($data['tags']), JSON_UNESCAPED_SLASHES) ?: '[]';
        }
        $this->db->run('UPDATE vault_items SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);
        $this->audit->event('vault.metadata_updated', 'vault', $uuid, $actor, ['fields' => array_keys($data)]);

        return $this->find($uuid) ?? [];
    }

    /**
     * Set (or replace) a secret field. Snapshots the previous encrypted value.
     *
     * @return array<string,mixed>
     */
    public function setSecret(string $uuid, string $fkey, string $label, string $value, string $actor): array
    {
        if ($this->db->one('SELECT uuid FROM vault_items WHERE uuid = :u', ['u' => $uuid]) === null) {
            throw new VaultException(404, 'Vault item not found.');
        }
        if ($value === '') {
            throw new VaultException(422, 'Enter a value.');
        }
        $this->db->transaction(function (Database $db) use ($uuid, $fkey, $label, $value, $actor): void {
            $this->putField($db, $uuid, $fkey, $label, $value, $actor, 'edited');
        });
        $this->accessEvent($this->db, $uuid, $fkey, 'edit_secret', $actor);
        $this->audit->event('vault.secret_set', 'vault', $uuid, $actor, ['field' => $fkey]); // never the value

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Reveal / copy (require step-up re-auth)
    // ==================================================================

    /**
     * Reveal ONE field's plaintext. Requires a recent step-up re-auth; audited.
     * The value is returned to the caller only — never logged.
     */
    public function reveal(string $uuid, string $fkey, string $actor): string
    {
        return $this->accessSecret($uuid, $fkey, $actor, 'reveal');
    }

    /** Copy ONE field (same guard as reveal; recorded as a copy access event). */
    public function copy(string $uuid, string $fkey, string $actor): string
    {
        return $this->accessSecret($uuid, $fkey, $actor, 'copy');
    }

    private function accessSecret(string $uuid, string $fkey, string $actor, string $action): string
    {
        if (!$this->hasValidReauth($actor)) {
            throw new VaultException(401, 'Re-authentication is required to reveal a secret.');
        }
        $field = $this->db->one('SELECT ciphertext, key_version FROM vault_item_fields WHERE item_uuid = :u AND fkey = :k', ['u' => $uuid, 'k' => $fkey]);
        if ($field === null) {
            throw new VaultException(404, 'That field does not exist.');
        }
        $plain = $this->crypto->decrypt((string) $field['ciphertext'], (int) $field['key_version']);
        if ($plain === null) {
            // Integrity failure — never echo anything derived from the ciphertext.
            throw new VaultException(409, 'The stored secret failed its integrity check.');
        }
        $this->accessEvent($this->db, $uuid, $fkey, $action, $actor);
        $this->audit->event('vault.' . $action, 'vault', $uuid, $actor, ['field' => $fkey]); // metadata only

        return $plain;
    }

    // ==================================================================
    // Archive / links / access log
    // ==================================================================

    /** @return array<string,mixed> */
    public function archive(string $uuid, string $actor): array
    {
        $this->db->run('UPDATE vault_items SET archived = 1, updated_at = :now WHERE uuid = :u', ['now' => Clock::nowIso(), 'u' => $uuid]);
        $this->accessEvent($this->db, $uuid, '', 'archive', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function restore(string $uuid, string $actor): array
    {
        $this->db->run('UPDATE vault_items SET archived = 0, updated_at = :now WHERE uuid = :u', ['now' => Clock::nowIso(), 'u' => $uuid]);
        $this->accessEvent($this->db, $uuid, '', 'restore', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function accessLog(string $uuid): array
    {
        return $this->db->all('SELECT fkey, action, actor, created_at FROM vault_access_events WHERE item_uuid = :u ORDER BY created_at DESC LIMIT 200', ['u' => $uuid]);
    }

    // ==================================================================
    // Key rotation
    // ==================================================================

    /**
     * Re-encrypt every field to a new current key version. Interruptible: fields
     * already on the new version are skipped, so a re-run resumes safely.
     *
     * @return array{version:int,rotated:int}
     */
    public function rotateKeys(string $actor): array
    {
        $current = (int) $this->db->scalar('SELECT COALESCE(MAX(version),1) FROM vault_key_versions');
        if ($current === 0) {
            $current = 1;
        }
        $next = $current + 1;
        $this->db->run('INSERT INTO vault_key_versions (version, created_at, note) VALUES (:v, :now, :note) ON CONFLICT(version) DO NOTHING', ['v' => $next, 'now' => Clock::nowIso(), 'note' => 'rotation by ' . $actor]);

        $rotated = 0;
        foreach ($this->db->all('SELECT uuid, item_uuid, ciphertext, key_version FROM vault_item_fields WHERE key_version < :v', ['v' => $next]) as $field) {
            $plain = $this->crypto->decrypt((string) $field['ciphertext'], (int) $field['key_version']);
            if ($plain === null) {
                continue; // leave unreadable field untouched rather than lose it
            }
            $newCipher = $this->crypto->encrypt($plain, $next);
            $this->db->run('UPDATE vault_item_fields SET ciphertext = :c, key_version = :v, updated_at = :now WHERE uuid = :u', ['c' => $newCipher, 'v' => $next, 'now' => Clock::nowIso(), 'u' => (string) $field['uuid']]);
            $rotated++;
        }
        $this->audit->event('vault.keys_rotated', 'vault', 'system', $actor, ['version' => $next, 'rotated' => $rotated]);

        return ['version' => $next, 'rotated' => $rotated];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function putField(Database $db, string $uuid, string $fkey, string $label, string $value, string $actor, string $reason): void
    {
        $keyVersion = (int) $db->scalar('SELECT COALESCE(MAX(version),1) FROM vault_key_versions');
        if ($keyVersion === 0) {
            $keyVersion = 1;
        }
        // Snapshot the previous encrypted value (if any) before overwriting.
        $existing = $db->one('SELECT ciphertext, key_version FROM vault_item_fields WHERE item_uuid = :u AND fkey = :k', ['u' => $uuid, 'k' => $fkey]);
        if ($existing !== null) {
            $ver = (int) $db->scalar('SELECT COALESCE(MAX(version),0) + 1 FROM vault_item_versions WHERE item_uuid = :u', ['u' => $uuid]);
            $db->run('INSERT INTO vault_item_versions (uuid, item_uuid, version, fkey, ciphertext, key_version, editor, reason, created_at) VALUES (:uuid, :i, :v, :k, :c, :kv, :editor, :reason, :now)', ['uuid' => Uuid::v4(), 'i' => $uuid, 'v' => $ver, 'k' => $fkey, 'c' => (string) $existing['ciphertext'], 'kv' => (int) $existing['key_version'], 'editor' => $actor, 'reason' => $reason, 'now' => Clock::nowIso()]);
        }
        $cipher = $this->crypto->encrypt($value, $keyVersion);
        $hint = $this->hint($value);
        $db->run(
            'INSERT INTO vault_item_fields (uuid, item_uuid, fkey, label, ciphertext, key_version, hint, updated_at)
             VALUES (:uuid, :i, :k, :label, :c, :kv, :hint, :now)
             ON CONFLICT (item_uuid, fkey) DO UPDATE SET ciphertext = excluded.ciphertext, key_version = excluded.key_version, hint = excluded.hint, label = excluded.label, updated_at = excluded.updated_at',
            ['uuid' => Uuid::v4(), 'i' => $uuid, 'k' => $fkey, 'label' => $label !== '' ? $label : $fkey, 'c' => $cipher, 'kv' => $keyVersion, 'hint' => $hint, 'now' => Clock::nowIso()]
        );
    }

    /** A safe masked hint — never more than the last 2 characters. */
    private function hint(string $value): string
    {
        $len = mb_strlen($value);
        if ($len <= 2) {
            return str_repeat('•', $len);
        }

        return '••••' . mb_substr($value, -2);
    }

    /**
     * @param array<string,mixed> $i
     * @return array<string,mixed>
     */
    private function maskItem(array $i, bool $detail): array
    {
        $uuid = (string) $i['uuid'];
        $out = [
            'id' => $uuid, 'label' => (string) $i['label'], 'item_type' => (string) $i['item_type'],
            'company_uuid' => (string) ($i['company_uuid'] ?? ''), 'project_uuid' => (string) ($i['project_uuid'] ?? ''),
            'url' => (string) $i['url'], 'account_id' => (string) $i['account_id'], 'username' => (string) $i['username'],
            'owner' => (string) $i['owner'], 'status' => (string) $i['status'], 'expiry' => (string) ($i['expiry'] ?? ''),
            'last_verified' => (string) ($i['last_verified'] ?? ''), 'archived' => (int) $i['archived'] === 1,
            'revision' => (int) $i['revision'], 'notes' => (string) $i['notes'],
            'tags' => $this->decodeTags($i['tags'] ?? '[]'),
            'updated_at' => (string) $i['updated_at'],
        ];
        // Fields are ALWAYS masked here — hint only, never ciphertext/plaintext.
        $out['fields'] = array_map(static fn (array $f): array => [
            'fkey' => (string) $f['fkey'], 'label' => (string) $f['label'], 'hint' => (string) $f['hint'], 'has_value' => true,
        ], $this->db->all('SELECT fkey, label, hint FROM vault_item_fields WHERE item_uuid = :u ORDER BY sort_order ASC', ['u' => $uuid]));

        return $out;
    }

    /** @return list<string> */
    private function decodeTags(mixed $raw): array
    {
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function accessEvent(Database $db, string $uuid, string $fkey, string $action, string $actor): void
    {
        $db->run('INSERT INTO vault_access_events (uuid, item_uuid, fkey, action, actor, created_at) VALUES (:uuid, :i, :k, :a, :actor, :now)', ['uuid' => Uuid::v4(), 'i' => $uuid, 'k' => $fkey, 'a' => $action, 'actor' => $actor, 'now' => Clock::nowIso()]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
