<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\AdminApi;
use Breakfast\Platform\Admin\HermesAdmin;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Hermes admin console service. The two things that matter most: the
 * shared secret is NEVER exposed anywhere the UI or audit trail can see it, and
 * the safe backend surface (scopes, health, self-test, env-line generation) is
 * correct. Also verifies the admin API gate rejects unauthenticated callers.
 */
final class HermesAdminTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-hermesadmin-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);

        Database::reset();
        Platform::reset();

        // A configured Hermes credential in the environment.
        $this->secret = base64_encode(random_bytes(32));
        $_SERVER['HERMES_KEY_studio'] = 'crm:read,crm:notes,content:read|' . $this->secret;

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
                    'hermes'     => ['enabled' => true, 'replayWindow' => 300],
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HERMES_KEY_studio']);
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    private function hermes(): HermesAdmin
    {
        return new HermesAdmin(breakfast());
    }

    // -- Secret is never exposed ------------------------------------------

    public function testCredentialInventoryNeverExposesTheSecret(): void
    {
        $creds = $this->hermes()->credentials();
        $this->assertCount(1, $creds);
        $json = json_encode($creds);
        $this->assertIsString($json);
        $this->assertStringNotContainsString($this->secret, $json, 'The secret must never appear in the credential inventory');
        $this->assertArrayNotHasKey('secret', $creds[0]);
        $this->assertSame('studio', $creds[0]['id']);
        $this->assertSame('deployment', $creds[0]['managed']);
    }

    public function testGeneratedLineIsNotPersistedAndSecretNotAudited(): void
    {
        $gen = $this->hermes()->generateCredentialLine('bot', ['crm:read', 'content:read'], 'admin@test');

        $this->assertStringStartsWith('HERMES_KEY_bot=', $gen['env_line']);
        $this->assertFalse($gen['rotating']);

        // The generated secret must not be findable in the audit trail.
        $secret = explode('|', (string) $gen['env_line'])[1] ?? '';
        $this->assertNotSame('', $secret);
        $rows = breakfast()->db()->all('SELECT * FROM hermes_audit');
        $auditJson = json_encode($rows);
        $this->assertIsString($auditJson);
        $this->assertStringNotContainsString($secret, $auditJson, 'A generated secret must never be written to the audit log');
        // The generation event itself IS recorded (without the secret).
        $this->assertNotEmpty($rows);
    }

    // -- Scopes -----------------------------------------------------------

    public function testScopeCatalogueGroupedAndComplete(): void
    {
        $scopes = $this->hermes()->scopes();
        $this->assertSame(17, $scopes['total']);
        $this->assertNotEmpty($scopes['groups']);

        $domains = array_column($scopes['groups'], 'domain');
        $this->assertContains('CRM', $domains);
        $this->assertContains('Content', $domains);
        $this->assertContains('Calendar', $domains);

        // Every scope carries a risk + kind + description, and holders are listed.
        foreach ($scopes['groups'] as $group) {
            foreach ($group['scopes'] as $s) {
                $this->assertArrayHasKey('risk', $s);
                $this->assertArrayHasKey('kind', $s);
                $this->assertNotSame('', $s['description']);
            }
        }
    }

    // -- Health derivation ------------------------------------------------

    public function testHealthReportsHealthyThenAttentionThenDegraded(): void
    {
        $h = $this->hermes();
        $this->assertSame('healthy', $h->health()['status'], 'configured + enabled + no failures → healthy');

        // A minority of failures (1 of 4) → attention, not degraded.
        breakfast()->audit()->record('studio', 'crm:read', 'GET', '/crm/enquiries', 'ok', 200, 'ok1');
        breakfast()->audit()->record('studio', 'crm:read', 'GET', '/crm/enquiries', 'ok', 200, 'ok2');
        breakfast()->audit()->record('studio', 'crm:read', 'GET', '/crm/enquiries', 'ok', 200, 'ok3');
        breakfast()->audit()->record('studio', 'crm:read', 'GET', '/crm/enquiries', 'denied', 403, 'f1');
        $this->assertSame('attention', $this->hermes()->health()['status'], 'a minority of failures → attention');

        // A majority of failures (>= 50%) → degraded.
        breakfast()->audit()->record('studio', 'crm:read', 'GET', '/crm/enquiries', 'error', 500, 'f2');
        breakfast()->audit()->record('studio', 'crm:read', 'GET', '/crm/enquiries', 'error', 500, 'f3');
        breakfast()->audit()->record('studio', 'crm:read', 'GET', '/crm/enquiries', 'error', 500, 'f4');
        $this->assertSame('degraded', $this->hermes()->health()['status'], 'a majority of failures → degraded');
    }

    public function testHealthDisabledWhenSwitchedOff(): void
    {
        // Rebuild the platform with hermes disabled.
        Platform::reset();
        $base = dirname(__DIR__, 2);
        Platform::boot($base, ['storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'hermes' => ['enabled' => false]]);
        $this->assertSame('disabled', (new HermesAdmin(Platform::instance()))->health()['status']);
    }

    // -- Self-test --------------------------------------------------------

    public function testSelfTestPassesEveryStepForAValidCredential(): void
    {
        $result = $this->hermes()->selfTest('studio', 'admin@test');
        $this->assertTrue($result['ok']);
        foreach ($result['steps'] as $step) {
            $this->assertTrue($step['ok'], "step {$step['key']} should pass");
        }
    }

    public function testSelfTestFailsGracefullyForUnknownCredential(): void
    {
        $result = $this->hermes()->selfTest('nope', 'admin@test');
        $this->assertFalse($result['ok']);
        $credStep = array_values(array_filter($result['steps'], static fn ($s) => $s['key'] === 'credential'))[0];
        $this->assertFalse($credStep['ok']);
    }

    // -- API gate ---------------------------------------------------------

    public function testHermesEndpointsRequireAuthentication(): void
    {
        $api = new AdminApi($this->kirby, breakfast());
        foreach (['hermes/overview', 'hermes/credentials', 'hermes/scopes', 'hermes/activity'] as $path) {
            $res = $api->handle($path);
            $this->assertSame(401, $res->code(), "$path must require authentication");
        }
    }

    public function testAuthenticatedAdminGetsHermesOverview(): void
    {
        $this->kirby->impersonate('kirby');
        $res = $this->hermesResponse('hermes/overview');
        $this->assertSame(200, $res['code']);
        $this->assertTrue($res['data']['configured']);
        $this->assertSame(1, $res['data']['credential_count']);
    }

    /** @return array{code:int,data:array<string,mixed>} */
    private function hermesResponse(string $path): array
    {
        $res  = (new AdminApi($this->kirby, breakfast()))->handle($path);
        $body = json_decode((string) $res->body(), true);
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];

        return ['code' => $res->code(), 'data' => $data];
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
