<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Vault\VaultException;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Secure credential vault. Proves field-level encryption at rest, masked
 * list/detail responses, step-up re-authentication for reveal/copy, reveal/copy
 * auditing (metadata only — never the secret), secret versioning on edit, key
 * rotation (old ciphertext still decrypts), and — using a known canary value —
 * that the secret never appears in the database plaintext, masked API responses,
 * audit descriptions, or the global search index.
 */
final class VaultTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private const CANARY = 'SUPER-SECRET-CANARY-9f83b2a1';

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-vault-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
    }

    protected function tearDown(): void
    {
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    private function makeItem(): string
    {
        $item = breakfast()->vault()->create([
            'label' => 'Hosting login', 'item_type' => 'hosting', 'username' => 'admin',
            'fields' => [['fkey' => 'password', 'label' => 'Password', 'value' => self::CANARY]],
        ], 'admin@breakfast');

        return (string) $item['id'];
    }

    public function testSecretEncryptedAtRestAndCanaryNeverInDatabase(): void
    {
        $this->makeItem();
        // The raw ciphertext column must not contain the plaintext.
        $cipher = (string) breakfast()->db()->scalar('SELECT ciphertext FROM vault_item_fields LIMIT 1');
        $this->assertNotSame('', $cipher);
        $this->assertStringNotContainsString(self::CANARY, $cipher);
        // A full dump of the SQLite file must not contain the canary anywhere.
        $dump = (string) file_get_contents($this->tmp . '/database/crm.sqlite');
        $this->assertStringNotContainsString(self::CANARY, $dump, 'canary must never touch the database in plaintext');
    }

    public function testListAndFindAreMaskedOnly(): void
    {
        $id = $this->makeItem();
        $list = breakfast()->vault()->list();
        $this->assertStringNotContainsString(self::CANARY, json_encode($list) ?: '');
        $detail = breakfast()->vault()->find($id);
        $this->assertStringNotContainsString(self::CANARY, json_encode($detail) ?: '');
        // Only a masked hint is exposed (last 2 chars).
        $this->assertSame('••••a1', (string) $detail['fields'][0]['hint']);
        $this->assertArrayNotHasKey('ciphertext', $detail['fields'][0]);
    }

    public function testRevealRequiresStepUpReauth(): void
    {
        $id = $this->makeItem();
        // Without re-auth, reveal is rejected.
        $this->assertError(401, fn () => breakfast()->vault()->reveal($id, 'password', 'admin@breakfast'));
        // After a step-up grant, reveal returns the plaintext.
        breakfast()->vault()->grantReauth('admin@breakfast');
        $this->assertSame(self::CANARY, breakfast()->vault()->reveal($id, 'password', 'admin@breakfast'));
        // A different user's session does not carry the grant.
        $this->assertError(401, fn () => breakfast()->vault()->reveal($id, 'password', 'other@breakfast'));
    }

    public function testExpiredReauthCannotReveal(): void
    {
        $id = $this->makeItem();
        breakfast()->vault()->grantReauth('admin@breakfast');
        // Force the session to have expired.
        breakfast()->db()->run("UPDATE vault_reauth_sessions SET expires_at = :e WHERE user_email = 'admin@breakfast'", ['e' => date('c', time() - 60)]);
        $this->assertFalse(breakfast()->vault()->hasValidReauth('admin@breakfast'));
        $this->assertError(401, fn () => breakfast()->vault()->reveal($id, 'password', 'admin@breakfast'));
    }

    public function testRevealAndCopyAreAuditedWithoutTheSecret(): void
    {
        $id = $this->makeItem();
        breakfast()->vault()->grantReauth('admin@breakfast');
        breakfast()->vault()->reveal($id, 'password', 'admin@breakfast');
        breakfast()->vault()->copy($id, 'password', 'admin@breakfast');
        $log = breakfast()->vault()->accessLog($id);
        $actions = array_map(static fn ($e) => $e['action'], $log);
        $this->assertContains('reveal', $actions);
        $this->assertContains('copy', $actions);
        // The audit log (hermes_audit) records the action but never the value.
        $auditDump = json_encode(breakfast()->db()->all("SELECT * FROM hermes_audit")) ?: '';
        $this->assertStringContainsString('vault.reveal', $auditDump);
        $this->assertStringNotContainsString(self::CANARY, $auditDump);
    }

    public function testEditSnapshotsPreviousSecretVersion(): void
    {
        $id = $this->makeItem();
        breakfast()->vault()->setSecret($id, 'password', 'Password', 'a-new-value', 'admin@breakfast');
        $versions = (int) breakfast()->db()->scalar('SELECT COUNT(*) FROM vault_item_versions WHERE item_uuid = :u', ['u' => $id]);
        $this->assertSame(1, $versions, 'the previous encrypted value is snapshotted');
        // The current value is the new one.
        breakfast()->vault()->grantReauth('admin@breakfast');
        $this->assertSame('a-new-value', breakfast()->vault()->reveal($id, 'password', 'admin@breakfast'));
    }

    public function testKeyRotationReEncryptsAndOldCiphertextStillReadable(): void
    {
        $id = $this->makeItem();
        $before = (int) breakfast()->db()->scalar('SELECT key_version FROM vault_item_fields WHERE item_uuid = :u', ['u' => $id]);
        $result = breakfast()->vault()->rotateKeys('admin@breakfast');
        $this->assertGreaterThan($before, $result['version']);
        $this->assertSame(1, $result['rotated']);
        $after = (int) breakfast()->db()->scalar('SELECT key_version FROM vault_item_fields WHERE item_uuid = :u', ['u' => $id]);
        $this->assertSame($result['version'], $after);
        // Still decrypts to the same plaintext after rotation.
        breakfast()->vault()->grantReauth('admin@breakfast');
        $this->assertSame(self::CANARY, breakfast()->vault()->reveal($id, 'password', 'admin@breakfast'));
    }

    public function testSecretNotIndexedBySearch(): void
    {
        $this->makeItem();
        // The global search must not surface the vault secret.
        $results = (new \Breakfast\Platform\Crm\SearchService(breakfast()->db()))->search(self::CANARY, 20);
        $this->assertStringNotContainsString(self::CANARY, json_encode($results) ?: '');
        $this->assertSame([], $results);
    }

    private function assertError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a VaultException with status ' . $status);
        } catch (VaultException $e) {
            $this->assertSame($status, $e->status);
            $this->assertStringNotContainsString(self::CANARY, $e->getMessage());
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
