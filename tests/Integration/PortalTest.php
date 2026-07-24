<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Portal\PortalException;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Client portal foundation & identity. Proves passwordless magic-link auth
 * (single-use + expiring), server-side sessions (hashed at rest, revocable,
 * expiring), no email enumeration, access grants that gate project visibility,
 * suspension that kills live sessions — and that no raw token is ever stored.
 */
final class PortalTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private string $identity;
    private string $projectA;
    private string $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-portal-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();

        $p = breakfast();
        $contact = (string) $p->contacts()->create(['display_name' => 'Sian', 'email' => 'sian@roberts.example'])['uuid'];
        $this->identity = (string) $p->portal()->createIdentity(['email' => 'sian@roberts.example', 'display_name' => 'Sian Roberts', 'contact_uuid' => $contact], 'staff@breakfast')['uuid'];
        $this->projectA = (string) $p->projects()->create(['name' => 'Cafe website'], 'staff@breakfast')['uuid'];
        $this->projectB = (string) $p->projects()->create(['name' => 'Secret internal project'], 'staff@breakfast')['uuid'];
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

    public function testIdentityRequiresValidUniqueEmail(): void
    {
        $p = breakfast();
        try {
            $p->portal()->createIdentity(['email' => 'not-an-email'], 'staff@breakfast');
            $this->fail('expected invalid email rejection');
        } catch (PortalException $e) {
            $this->assertSame(422, $e->status);
        }
        // Duplicate (case-insensitive) is rejected.
        $this->expectException(PortalException::class);
        $p->portal()->createIdentity(['email' => 'SIAN@roberts.example'], 'staff@breakfast');
    }

    public function testRequestLoginDoesNotEnumerate(): void
    {
        $p = breakfast();
        $unknown = $p->portal()->requestLogin('nobody@nowhere.example');
        $this->assertFalse($unknown['sent']);
        $this->assertNull($unknown['token']);

        $known = $p->portal()->requestLogin('sian@roberts.example');
        $this->assertTrue($known['sent']);
        $this->assertNotNull($known['token']);
    }

    public function testMagicLinkIsSingleUseAndMintsSession(): void
    {
        $p = breakfast();
        $token = (string) $p->portal()->requestLogin('sian@roberts.example')['token'];
        $result = $p->portal()->consumeMagicLink($token, 'iphash', 'UA');
        $this->assertNotSame('', $result['session_token']);
        $this->assertSame('Sian Roberts', (string) $result['identity']['display_name']);

        // Reusing the link is rejected (one-shot).
        try {
            $p->portal()->consumeMagicLink($token);
            $this->fail('expected used link rejection');
        } catch (PortalException $e) {
            $this->assertSame(410, $e->status);
        }
    }

    public function testExpiredMagicLinkRejected(): void
    {
        $p = breakfast();
        // Insert a link whose expiry is already in the past.
        $raw = bin2hex(random_bytes(24));
        breakfast()->db()->run(
            'INSERT INTO portal_magic_links (uuid, identity_uuid, token_hash, email, expires_at, created_at) VALUES (:uuid, :i, :h, :e, :exp, :now)',
            ['uuid' => Uuid::v4(), 'i' => $this->identity, 'h' => hash('sha256', $raw), 'e' => 'sian@roberts.example', 'exp' => date('c', time() - 60), 'now' => Clock::nowIso()]
        );
        $this->expectException(PortalException::class);
        $p->portal()->consumeMagicLink($raw);
    }

    public function testSessionValidatesAndLogoutRevokes(): void
    {
        $p = breakfast();
        $token = (string) $p->portal()->requestLogin('sian@roberts.example')['token'];
        $session = (string) $p->portal()->consumeMagicLink($token)['session_token'];

        $this->assertNotNull($p->portal()->identityFromSession($session));
        $p->portal()->logout($session);
        $this->assertNull($p->portal()->identityFromSession($session), 'a revoked session fails closed');
        $this->assertNull($p->portal()->identityFromSession('garbage-token'));
    }

    public function testSuspensionKillsLiveSessions(): void
    {
        $p = breakfast();
        $token = (string) $p->portal()->requestLogin('sian@roberts.example')['token'];
        $session = (string) $p->portal()->consumeMagicLink($token)['session_token'];
        $this->assertNotNull($p->portal()->identityFromSession($session));

        $p->portal()->setStatus($this->identity, 'suspended', 'staff@breakfast');
        $this->assertNull($p->portal()->identityFromSession($session), 'suspending revokes live sessions');
        // A suspended identity cannot request a new link either.
        $this->assertFalse($p->portal()->requestLogin('sian@roberts.example')['sent']);
    }

    public function testAccessGrantsGateProjectVisibility(): void
    {
        $p = breakfast();
        // No grant → nothing visible.
        $this->assertSame([], $p->portal()->accessibleProjects($this->identity));
        $this->assertFalse($p->portal()->canAccessProject($this->identity, $this->projectA));

        $p->portal()->grantAccess($this->identity, 'project', $this->projectA, 'viewer', 'staff@breakfast');
        $visible = $p->portal()->accessibleProjects($this->identity);
        $this->assertCount(1, $visible);
        $this->assertSame($this->projectA, (string) $visible[0]['uuid']);
        $this->assertTrue($p->portal()->canAccessProject($this->identity, $this->projectA));
        // Project B was never granted — still invisible.
        $this->assertFalse($p->portal()->canAccessProject($this->identity, $this->projectB));

        // Revoking removes visibility.
        $p->portal()->revokeAccess($this->identity, 'project', $this->projectA, 'staff@breakfast');
        $this->assertSame([], $p->portal()->accessibleProjects($this->identity));
    }

    public function testRawTokensAreNeverStored(): void
    {
        $p = breakfast();
        $token = (string) $p->portal()->requestLogin('sian@roberts.example')['token'];
        $session = (string) $p->portal()->consumeMagicLink($token)['session_token'];

        $dump = (string) file_get_contents($this->tmp . '/database/crm.sqlite');
        $this->assertStringNotContainsString($token, $dump, 'raw magic-link token never in the database');
        $this->assertStringNotContainsString($session, $dump, 'raw session token never in the database');
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
