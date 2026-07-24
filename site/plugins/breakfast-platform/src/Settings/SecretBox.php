<?php

declare(strict_types=1);

namespace Breakfast\Platform\Settings;

use RuntimeException;

/**
 * Authenticated symmetric encryption for application-managed secrets (e.g. the
 * Brevo API key), using libsodium's XSalsa20-Poly1305 secretbox.
 *
 * The encryption key is kept OUT of the database that stores the ciphertext:
 * it comes from the PLATFORM_SECRET_KEY environment variable (base64, 32 bytes)
 * when set, otherwise from a locally generated key file (0600) under the storage
 * directory. A database dump alone therefore never reveals a stored secret.
 */
final class SecretBox
{
    private string $key;

    public function __construct(string $storageDir, ?string $envKey = null)
    {
        $env = $envKey ?? (getenv('PLATFORM_SECRET_KEY') ?: '');
        if ($env !== '') {
            $decoded = base64_decode($env, true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException('PLATFORM_SECRET_KEY must be base64-encoded 32 bytes.');
            }
            $this->key = $decoded;

            return;
        }
        $this->key = $this->keyFromFile($storageDir . '/secret.key');
    }

    /** Encrypt plaintext → base64(nonce ‖ ciphertext). */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        $out = base64_encode($nonce . $cipher);
        sodium_memzero($plaintext);

        return $out;
    }

    /** Decrypt base64(nonce ‖ ciphertext) → plaintext, or null if tampered/invalid. */
    public function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        return $plain === false ? null : $plain;
    }

    private function keyFromFile(string $path): string
    {
        if (is_file($path)) {
            $decoded = base64_decode((string) file_get_contents($path), true);
            if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $decoded;
            }
        }
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // Write atomically with restrictive permissions.
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, base64_encode($key)) === false) {
            throw new RuntimeException('Unable to persist the encryption key.');
        }
        @chmod($tmp, 0600);
        rename($tmp, $path);
        @chmod($path, 0600);

        return $key;
    }
}
