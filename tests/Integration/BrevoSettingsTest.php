<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Mail\HttpClient;
use Breakfast\Platform\Mail\HttpResponse;
use Breakfast\Platform\Settings\SecretBox;
use Breakfast\Platform\Settings\SettingsException;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Secure, application-managed Brevo configuration. Proves the API key can be set
 * from Settings and is stored ENCRYPTED (never plaintext), only ever surfaced as
 * a masked hint, that the effective mail config actually picks it up, that the
 * connection test exercises real provider calls (faked transport, never a live
 * key), and that config changes validate + persist.
 */
final class BrevoSettingsTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-brevo-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);

        Database::reset();
        Platform::reset();

        $this->kirby = new App([
            'roots' => [
                'index'    => $base . '/public',
                'base'     => $base,
                'site'     => $base . '/site',
                'content'  => $base . '/content',
                'storage'  => $this->tmp,
                'sessions' => $this->tmp . '/sessions',
                'accounts' => $this->tmp . '/accounts',
            ],
            'options' => [
                'debug'  => false,
                'whoops' => false,
                'breakfast' => [
                    'production' => false,
                    'storageDir' => $this->tmp,
                    'dbPath'     => $this->tmp . '/database/crm.sqlite',
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
    }

    protected function tearDown(): void
    {
        HttpClient::useTransport(null);
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testSecretBoxRoundTripsAndRejectsTampering(): void
    {
        $box = new SecretBox($this->tmp);
        $cipher = $box->encrypt('super-secret-value');
        $this->assertStringNotContainsString('super-secret', $cipher);
        $this->assertSame('super-secret-value', $box->decrypt($cipher));
        // A tampered ciphertext returns null, never garbage.
        $this->assertNull($box->decrypt(substr($cipher, 0, -2) . 'zz'));
    }

    public function testSettingKeyStoresCiphertextAndReturnsOnlyAMaskedHint(): void
    {
        $b = breakfast()->brevoSettings();
        $b->setKey('xkeysib-VERYLONGSECRET-ab12EF9C', 'admin@test');

        $ov = $b->overview();
        $this->assertTrue($ov['configured']);
        $this->assertSame('settings', $ov['source']);
        $this->assertSame('••••EF9C', $ov['key_hint']);
        // The full key is nowhere in the overview payload.
        $this->assertStringNotContainsString('VERYLONGSECRET', json_encode($ov) ?: '');

        // Stored value is ciphertext, flagged secret, plaintext absent.
        $row = breakfast()->db()->one("SELECT value, is_secret FROM platform_settings WHERE name = 'brevo.api_key'");
        $this->assertSame(1, (int) $row['is_secret']);
        $this->assertStringNotContainsString('VERYLONGSECRET', (string) $row['value']);
    }

    public function testEffectiveMailConfigPicksUpTheStoredKey(): void
    {
        breakfast()->brevoSettings()->setKey('xkeysib-STOREDKEY-000012345678', 'admin@test');
        Platform::reset(); // fresh services, must still resolve from the DB
        $this->assertSame('xkeysib-STOREDKEY-000012345678', breakfast()->mailConfig()['apiKey'] ?? null);
    }

    public function testRejectsAnObviouslyInvalidKey(): void
    {
        try {
            breakfast()->brevoSettings()->setKey('short', 'admin@test');
            $this->fail('Expected validation failure');
        } catch (SettingsException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('api_key', $e->fields);
        }
    }

    public function testConfigUpdatesValidateAndPersist(): void
    {
        $b = breakfast()->brevoSettings();
        try {
            $b->update(['sender_email' => 'not-an-email'], 'admin@test');
            $this->fail('Expected validation failure');
        } catch (SettingsException $e) {
            $this->assertArrayHasKey('sender_email', $e->fields);
        }

        $b->update(['enabled' => true, 'sender_email' => 'hi@breakfastdept.com', 'sender_name' => 'Breakfast'], 'admin@test');
        Platform::reset();
        $config = breakfast()->brevoSettings()->config();
        $this->assertTrue($config['enabled']);
        $this->assertSame('hi@breakfastdept.com', $config['sender_email']);
    }

    public function testConnectionTestExercisesAccountAndSenderWithFakedTransport(): void
    {
        $b = breakfast()->brevoSettings();
        $b->setKey('xkeysib-TESTKEY-0000abcd1234', 'admin@test');
        $b->update(['sender_email' => 'hi@breakfastdept.com'], 'admin@test');

        HttpClient::useTransport(function (string $m, string $url): HttpResponse {
            if (str_contains($url, '/account')) {
                return new HttpResponse(200, '{"email":"acct@x"}');
            }
            if (str_contains($url, '/senders')) {
                return new HttpResponse(200, '{"senders":[{"email":"hi@breakfastdept.com"}]}');
            }

            return new HttpResponse(404, '{}');
        });

        $result = breakfast()->brevoSettings()->test('admin@test');
        $this->assertTrue($result['ok']);
        $keys = array_column($result['steps'], 'key');
        $this->assertSame(['key', 'account', 'sender'], $keys);
    }

    public function testConnectionTestFailsClearlyOnBadAuth(): void
    {
        $b = breakfast()->brevoSettings();
        $b->setKey('xkeysib-BADKEY-0000abcd1234', 'admin@test');

        HttpClient::useTransport(fn (): HttpResponse => new HttpResponse(401, '{"message":"unauthorized"}'));

        $result = breakfast()->brevoSettings()->test('admin@test');
        $this->assertFalse($result['ok']);
        $account = array_values(array_filter($result['steps'], fn ($s) => $s['key'] === 'account'))[0];
        $this->assertFalse($account['ok']);
        // Raw provider bodies are never surfaced — only a bounded status.
        $this->assertStringNotContainsString('unauthorized', $account['detail']);
    }

    public function testRemovingTheKeyClearsConfiguredState(): void
    {
        $b = breakfast()->brevoSettings();
        $b->setKey('xkeysib-TOREMOVE-0000abcd1234', 'admin@test');
        $this->assertTrue($b->overview()['configured']);

        $b->clearKey('admin@test');
        $this->assertFalse(breakfast()->brevoSettings()->overview()['configured']);
    }

    public function testKeyChangesAreAudited(): void
    {
        breakfast()->brevoSettings()->setKey('xkeysib-AUDITME-0000abcd1234', 'admin@test');
        // AuditLog stores the action in the 'endpoint' column.
        $actions = array_column(breakfast()->audit()->recent(10), 'endpoint');
        $this->assertContains('brevo.key_set', $actions);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
