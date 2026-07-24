<?php

declare(strict_types=1);

namespace Breakfast\Platform\Vault;

use Breakfast\Platform\Settings\SecretBox;

/**
 * Key-versioned authenticated encryption for vault secrets.
 *
 * Each key version has its own libsodium key kept OUT of the database — in an
 * env var (VAULT_KEY_V{n}, base64 32 bytes) or a 0600 key file. Ciphertext is
 * stored with its key_version so old versions still decrypt during/after a
 * rotation. A database dump alone never reveals a secret. No custom crypto: this
 * delegates to the platform's proven {@see SecretBox}.
 */
final class VaultCrypto
{
    /** @var array<int,SecretBox> */
    private array $boxes = [];

    public function __construct(private readonly string $keyDir)
    {
    }

    /** Encrypt with the given key version. */
    public function encrypt(string $plaintext, int $keyVersion): string
    {
        return $this->box($keyVersion)->encrypt($plaintext);
    }

    /** Decrypt; returns null on tamper/invalid (integrity failure). */
    public function decrypt(string $ciphertext, int $keyVersion): ?string
    {
        return $this->box($keyVersion)->decrypt($ciphertext);
    }

    private function box(int $version): SecretBox
    {
        if (!isset($this->boxes[$version])) {
            $envKey = getenv('VAULT_KEY_V' . $version) ?: null;
            // Each version gets its own key file; SecretBox generates one (0600)
            // on first use if absent. The key is never written to the database.
            $this->boxes[$version] = new SecretBox($this->keyDir . '/v' . $version, $envKey);
        }

        return $this->boxes[$version];
    }
}
