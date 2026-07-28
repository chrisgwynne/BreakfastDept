<?php

declare(strict_types=1);

namespace Breakfast\Platform\Vault;

use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Secure credential vault, stored as flat files.
 *
 * Sensitive field values are field-level encrypted (key-versioned, authenticated)
 * and NEVER returned by list/find — only masked hints. Revealing or copying a
 * secret requires a recent step-up re-authentication and is audited; the secret
 * value itself is never written to logs, audit descriptions or exceptions.
 * Editing a secret snapshots the previous encrypted value as an immutable
 * version. Key rotation re-encrypts every field to the new current version.
 *
 * The ciphertext is unchanged by the migration — only the storage engine moved
 * from the database to JSON files.
 */
final class Vault
{
    /** @var list<string> */
    public const SENSITIVE_FIELDS = ['password', 'api_key', 'token', 'recovery_code', 'private_key', 'db_password', 'secure_note'];

    private const REAUTH_TTL = 300; // seconds a step-up session stays valid

    public function __construct(
        private readonly FileStore $store,
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
        foreach ($this->store->all('vault_reauth_sessions') as $s) {
            if ((string) ($s['user_email'] ?? '') === $userEmail && (int) ($s['revoked'] ?? 0) === 0) {
                $this->store->update('vault_reauth_sessions', (string) $s['uuid'], static function (array $r): array {
                    $r['revoked'] = 1;

                    return $r;
                });
            }
        }
        $this->store->put('vault_reauth_sessions', [
            'uuid'       => Uuid::v4(),
            'user_email' => $userEmail,
            'revoked'    => 0,
            'expires_at' => date('c', time() + self::REAUTH_TTL),
            'created_at' => Clock::nowIso(),
        ]);
    }

    public function revokeReauth(string $userEmail): void
    {
        foreach ($this->store->all('vault_reauth_sessions') as $s) {
            if ((string) ($s['user_email'] ?? '') === $userEmail) {
                $this->store->update('vault_reauth_sessions', (string) $s['uuid'], static function (array $r): array {
                    $r['revoked'] = 1;

                    return $r;
                });
            }
        }
    }

    public function hasValidReauth(string $userEmail): bool
    {
        $sessions = array_values(array_filter($this->store->all('vault_reauth_sessions'), static fn (array $s): bool => (string) ($s['user_email'] ?? '') === $userEmail && (int) ($s['revoked'] ?? 0) === 0));
        usort($sessions, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $row = $sessions[0] ?? null;
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
        $rows = array_filter($this->store->all('vault_items'), static fn (array $i): bool => (int) ($i['archived'] ?? 0) === 0);
        if (!empty($filters['company_uuid'])) {
            $rows = array_filter($rows, static fn (array $i): bool => (string) ($i['company_uuid'] ?? '') === (string) $filters['company_uuid']);
        }
        if (!empty($filters['project_uuid'])) {
            $rows = array_filter($rows, static fn (array $i): bool => (string) ($i['project_uuid'] ?? '') === (string) $filters['project_uuid']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));

        return array_map(fn (array $i): array => $this->maskItem($i, false), array_slice($rows, 0, 500));
    }

    /**
     * Metadata + MASKED fields (hints only). Never returns ciphertext or plaintext.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $uuid): ?array
    {
        $i = $this->store->find('vault_items', $uuid);

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

        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->store->put('vault_items', [
            'uuid'          => $uuid,
            'label'         => $label,
            'item_type'     => (string) ($data['item_type'] ?? 'other'),
            'company_uuid'  => $this->nullable($data['company_uuid'] ?? null),
            'project_uuid'  => $this->nullable($data['project_uuid'] ?? null),
            'url'           => (string) ($data['url'] ?? ''),
            'account_id'    => (string) ($data['account_id'] ?? ''),
            'username'      => (string) ($data['username'] ?? ''),
            'owner'         => (string) ($data['owner'] ?? $actor),
            'status'        => 'active',
            'tags'          => is_array($data['tags'] ?? null) ? array_values($data['tags']) : [],
            'notes'         => (string) ($data['notes'] ?? ''),
            'expiry'        => $this->nullable($data['expiry'] ?? null),
            'archived'      => 0,
            'revision'      => 0,
            'last_verified' => null,
            'created_by'    => $actor,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
            if (is_array($field) && !empty($field['fkey']) && isset($field['value']) && (string) $field['value'] !== '') {
                $this->putField($uuid, (string) $field['fkey'], (string) ($field['label'] ?? ''), (string) $field['value'], $actor, 'created');
            }
        }
        $this->accessEvent($uuid, '', 'create', $actor);
        $this->audit->event('vault.created', 'vault', $uuid, $actor, ['label' => $label]);

        return $this->find($uuid) ?? [];
    }

    /**
     * Update non-secret metadata (optimistic concurrency).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updateMetadata(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $item = $this->store->find('vault_items', $uuid);
        if ($item === null) {
            throw new VaultException(404, 'Vault item not found.');
        }
        if ($expectedRevision !== null && (int) ($item['revision'] ?? 0) !== $expectedRevision) {
            throw new VaultException(409, 'This item was changed by someone else. Reload and try again.');
        }
        $this->store->update('vault_items', $uuid, function (array $row) use ($data): array {
            foreach (['label', 'item_type', 'url', 'account_id', 'username', 'owner', 'status', 'notes'] as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = (string) $data[$f];
                }
            }
            if (array_key_exists('expiry', $data)) {
                $row['expiry'] = $this->nullable($data['expiry']);
            }
            if (array_key_exists('tags', $data) && is_array($data['tags'])) {
                $row['tags'] = array_values($data['tags']);
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
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
        if ($this->store->find('vault_items', $uuid) === null) {
            throw new VaultException(404, 'Vault item not found.');
        }
        if ($value === '') {
            throw new VaultException(422, 'Enter a value.');
        }
        $this->putField($uuid, $fkey, $label, $value, $actor, 'edited');
        $this->accessEvent($uuid, $fkey, 'edit_secret', $actor);
        $this->audit->event('vault.secret_set', 'vault', $uuid, $actor, ['field' => $fkey]); // never the value

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Reveal / copy (require step-up re-auth)
    // ==================================================================

    /**
     * Reveal ONE field's plaintext. Requires a recent step-up re-auth; audited.
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
        $field = $this->store->find('vault_item_fields', $this->fieldId($uuid, $fkey));
        if ($field === null) {
            throw new VaultException(404, 'That field does not exist.');
        }
        $plain = $this->crypto->decrypt((string) $field['ciphertext'], (int) $field['key_version']);
        if ($plain === null) {
            // Integrity failure — never echo anything derived from the ciphertext.
            throw new VaultException(409, 'The stored secret failed its integrity check.');
        }
        $this->accessEvent($uuid, $fkey, $action, $actor);
        $this->audit->event('vault.' . $action, 'vault', $uuid, $actor, ['field' => $fkey]); // metadata only

        return $plain;
    }

    // ==================================================================
    // Archive / links / access log
    // ==================================================================

    /** @return array<string,mixed> */
    public function archive(string $uuid, string $actor): array
    {
        $this->setArchived($uuid, 1);
        $this->accessEvent($uuid, '', 'archive', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function restore(string $uuid, string $actor): array
    {
        $this->setArchived($uuid, 0);
        $this->accessEvent($uuid, '', 'restore', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function accessLog(string $uuid): array
    {
        $rows = array_values(array_filter($this->store->all('vault_access_events'), static fn (array $e): bool => (string) ($e['item_uuid'] ?? '') === $uuid));
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice(array_map(static fn (array $e): array => [
            'fkey' => $e['fkey'] ?? '', 'action' => $e['action'] ?? '', 'actor' => $e['actor'] ?? '', 'created_at' => $e['created_at'] ?? '',
        ], $rows), 0, 200);
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
        $next = $this->currentKeyVersion() + 1;
        $this->store->put('vault_key_versions', ['uuid' => 'v' . $next, 'version' => $next, 'created_at' => Clock::nowIso(), 'note' => 'rotation by ' . $actor]);

        $rotated = 0;
        foreach ($this->store->all('vault_item_fields') as $field) {
            if ((int) ($field['key_version'] ?? 0) >= $next) {
                continue;
            }
            $plain = $this->crypto->decrypt((string) $field['ciphertext'], (int) $field['key_version']);
            if ($plain === null) {
                continue; // leave unreadable field untouched rather than lose it
            }
            $newCipher = $this->crypto->encrypt($plain, $next);
            $this->store->update('vault_item_fields', (string) $field['uuid'], static function (array $row) use ($newCipher, $next): array {
                $row['ciphertext']  = $newCipher;
                $row['key_version'] = $next;
                $row['updated_at']  = Clock::nowIso();

                return $row;
            });
            $rotated++;
        }
        $this->audit->event('vault.keys_rotated', 'vault', 'system', $actor, ['version' => $next, 'rotated' => $rotated]);

        return ['version' => $next, 'rotated' => $rotated];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function currentKeyVersion(): int
    {
        $max = 1;
        foreach ($this->store->all('vault_key_versions') as $v) {
            $max = max($max, (int) ($v['version'] ?? 0));
        }

        return $max;
    }

    /** Deterministic file id for a field, keyed on (item, fkey) — the old UNIQUE. */
    private function fieldId(string $itemUuid, string $fkey): string
    {
        return sha1($itemUuid . ':' . $fkey);
    }

    private function setArchived(string $uuid, int $archived): void
    {
        $this->store->update('vault_items', $uuid, static function (array $row) use ($archived): array {
            $row['archived']   = $archived;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
    }

    private function putField(string $uuid, string $fkey, string $label, string $value, string $actor, string $reason): void
    {
        $keyVersion = $this->currentKeyVersion();
        $id = $this->fieldId($uuid, $fkey);
        // Snapshot the previous encrypted value (if any) before overwriting.
        $existing = $this->store->find('vault_item_fields', $id);
        if ($existing !== null) {
            $ver = 1;
            foreach ($this->store->all('vault_item_versions') as $v) {
                if ((string) ($v['item_uuid'] ?? '') === $uuid) {
                    $ver = max($ver, (int) ($v['version'] ?? 0) + 1);
                }
            }
            $this->store->put('vault_item_versions', [
                'uuid'        => Uuid::v4(),
                'item_uuid'   => $uuid,
                'version'     => $ver,
                'fkey'        => $fkey,
                'ciphertext'  => (string) $existing['ciphertext'],
                'key_version' => (int) $existing['key_version'],
                'editor'      => $actor,
                'reason'      => $reason,
                'created_at'  => Clock::nowIso(),
            ]);
        }
        $cipher = $this->crypto->encrypt($value, $keyVersion);
        $this->store->put('vault_item_fields', [
            'uuid'        => $id,
            'item_uuid'   => $uuid,
            'fkey'        => $fkey,
            'label'       => $label !== '' ? $label : $fkey,
            'ciphertext'  => $cipher,
            'key_version' => $keyVersion,
            'hint'        => $this->hint($value),
            'sort_order'  => $existing !== null ? (int) ($existing['sort_order'] ?? 0) : $this->nextSortOrder($uuid),
            'updated_at'  => Clock::nowIso(),
        ]);
    }

    private function nextSortOrder(string $itemUuid): int
    {
        $count = 0;
        foreach ($this->store->all('vault_item_fields') as $f) {
            if ((string) ($f['item_uuid'] ?? '') === $itemUuid) {
                $count++;
            }
        }

        return $count;
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
            'tags' => is_array($i['tags'] ?? null) ? array_values(array_map('strval', $i['tags'])) : $this->decodeTags($i['tags'] ?? '[]'),
            'updated_at' => (string) $i['updated_at'],
        ];
        // Fields are ALWAYS masked here — hint only, never ciphertext/plaintext.
        $fields = array_values(array_filter($this->store->all('vault_item_fields'), static fn (array $f): bool => (string) ($f['item_uuid'] ?? '') === $uuid));
        usort($fields, static fn ($a, $b) => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
        $out['fields'] = array_map(static fn (array $f): array => [
            'fkey' => (string) $f['fkey'], 'label' => (string) $f['label'], 'hint' => (string) $f['hint'], 'has_value' => true,
        ], $fields);

        return $out;
    }

    /** @return list<string> */
    private function decodeTags(mixed $raw): array
    {
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function accessEvent(string $uuid, string $fkey, string $action, string $actor): void
    {
        $this->store->put('vault_access_events', [
            'uuid'       => Uuid::v4(),
            'item_uuid'  => $uuid,
            'fkey'       => $fkey,
            'action'     => $action,
            'actor'      => $actor,
            'created_at' => Clock::nowIso(),
        ]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
