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
        breakfast()->fileStore()->put('portal_magic_links', [
            'uuid' => Uuid::v4(), 'identity_uuid' => $this->identity, 'token_hash' => hash('sha256', $raw),
            'email' => 'sian@roberts.example', 'ip_hash' => '', 'expires_at' => date('c', time() - 60), 'used_at' => null, 'created_at' => Clock::nowIso(),
        ]);
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

    public function testPortalProjectShowsOnlyClientVisibleContentAndEnforcesAccess(): void
    {
        $p = breakfast();
        // No grant → 403 even with a valid identity.
        try {
            $p->portal()->portalProject($this->identity, $this->projectA);
            $this->fail('expected access denial without a grant');
        } catch (PortalException $e) {
            $this->assertSame(403, $e->status);
        }

        $p->portal()->grantAccess($this->identity, 'project', $this->projectA, 'viewer', 'staff@breakfast');
        // A client-visible and a hidden task.
        $p->projectTasks()->create($this->projectA, ['title' => 'Client-facing task', 'client_visible' => true], 'staff@breakfast');
        $p->projectTasks()->create($this->projectA, ['title' => 'Internal only task'], 'staff@breakfast');

        $view = $p->portal()->portalProject($this->identity, $this->projectA);
        $this->assertSame($this->projectA, (string) $view['overview']['uuid']);
        $titles = array_map(static fn (array $t): string => (string) $t['title'], $view['tasks']);
        $this->assertContains('Client-facing task', $titles);
        $this->assertNotContains('Internal only task', $titles, 'internal tasks are never exposed to the client');

        // Still cannot reach a project that was never granted.
        $this->expectException(PortalException::class);
        $p->portal()->portalProject($this->identity, $this->projectB);
    }

    public function testFileDownloadGuardRequiresVisibilityAndGrant(): void
    {
        $p = breakfast();
        $p->portal()->grantAccess($this->identity, 'project', $this->projectA, 'viewer', 'staff@breakfast');

        // Store a hidden and a shared file on project A.
        $tmp = $this->tmp . '/doc.png';
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $hidden = (string) $p->files()->upload($tmp, 'internal.png', 'image/png', ['project_uuid' => $this->projectA, 'client_visible' => false], 'staff@breakfast')['uuid'];
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $shared = (string) $p->files()->upload($tmp, 'shared.png', 'image/png', ['project_uuid' => $this->projectA, 'client_visible' => true], 'staff@breakfast')['uuid'];

        // Shared + granted → allowed (no exception).
        $p->portal()->assertFileDownloadable($this->identity, $shared);
        $this->assertTrue(true);

        // Hidden file → 404 (never acknowledged).
        try {
            $p->portal()->assertFileDownloadable($this->identity, $hidden);
            $this->fail('expected hidden file to be denied');
        } catch (PortalException $e) {
            $this->assertSame(404, $e->status);
        }

        // Only the shared file appears in the project projection.
        $view = $p->portal()->portalProject($this->identity, $this->projectA);
        $names = array_map(static fn (array $f): string => (string) $f['display_name'], $view['files']);
        $this->assertSame(['shared.png'], $names);
    }

    public function testAccessiblePreviewsAreScopedToTheClientContactAndActive(): void
    {
        $p = breakfast();
        $contact = (string) $p->portal()->findIdentity($this->identity)['contact_uuid'];
        // An active preview for this client's contact, and one for someone else.
        $mine = $p->previews()->create([
            'name' => 'Roberts Cafe homepage', 'client_display_name' => 'Your new homepage',
            'contact_uuid' => $contact, 'public_slug' => 'roberts-home-' . bin2hex(random_bytes(3)), 'status' => 'active',
        ]);
        $otherContact = (string) $p->contacts()->create(['display_name' => 'Someone else', 'email' => 'else@x.co'])['uuid'];
        $p->previews()->create([
            'name' => 'Not yours', 'contact_uuid' => $otherContact,
            'public_slug' => 'not-yours-' . bin2hex(random_bytes(3)), 'status' => 'active',
        ]);
        // A draft preview for this client is NOT surfaced (active-only).
        $p->previews()->create([
            'name' => 'Still a draft', 'contact_uuid' => $contact,
            'public_slug' => 'draft-' . bin2hex(random_bytes(3)), 'status' => 'draft',
        ]);

        $previews = $p->portal()->accessiblePreviews($this->identity);
        $titles = array_map(static fn (array $x): string => (string) $x['title'], $previews);
        $this->assertContains('Your new homepage', $titles);
        $this->assertNotContains('Not yours', $titles, 'previews for other contacts are never surfaced');
        $this->assertNotContains('Still a draft', $titles, 'only active previews are surfaced');
        $this->assertNotSame('', (string) $previews[0]['url']);
        $this->assertNotSame('', (string) ($mine['uuid'] ?? ''));
    }

    public function testClientFeedbackAndSignOffAreRecordedAndSurfaced(): void
    {
        $p = breakfast();
        // Feedback on a project without a grant is refused.
        try {
            $p->portal()->submitFeedback($this->identity, $this->projectA, ['kind' => 'comment', 'body' => 'hi']);
            $this->fail('expected feedback without a grant to be denied');
        } catch (PortalException $e) {
            $this->assertSame(403, $e->status);
        }

        $p->portal()->grantAccess($this->identity, 'project', $this->projectA, 'viewer', 'staff@breakfast');
        // Empty comment rejected.
        try {
            $p->portal()->submitFeedback($this->identity, $this->projectA, ['kind' => 'comment', 'body' => '   ']);
            $this->fail('expected empty comment rejection');
        } catch (PortalException $e) {
            $this->assertSame(422, $e->status);
        }

        $comment = $p->portal()->submitFeedback($this->identity, $this->projectA, ['kind' => 'comment', 'body' => 'Looks great, one tweak on the header.']);
        $this->assertSame('comment', (string) $comment['kind']);
        $this->assertSame('open', (string) $comment['status']);

        $approval = $p->portal()->submitFeedback($this->identity, $this->projectA, ['kind' => 'approval', 'ip_hash' => 'iphash', 'user_agent' => 'UA']);
        $this->assertSame('approval', (string) $approval['kind']);

        // Staff see both on the project; the client's own thread shows them too.
        $this->assertCount(2, $p->portal()->feedbackForProject($this->projectA));
        $this->assertTrue($p->portal()->portalProject($this->identity, $this->projectA)['has_approved']);

        // The client's contact timeline carries the sign-off.
        $contact = (string) $p->portal()->findIdentity($this->identity)['contact_uuid'];
        $types = array_map(static fn (array $a): string => (string) $a['type'], $p->activities()->forEntity('contact', $contact, 50));
        $this->assertContains('portal.feedback_approval', $types);

        // Staff can move a feedback item through its lifecycle.
        $resolved = $p->portal()->setFeedbackStatus((string) $comment['uuid'], 'resolved', 'staff@breakfast');
        $this->assertSame('resolved', (string) $resolved['status']);
    }

    public function testAccessibleDocumentsAreScopedAndSentOnly(): void
    {
        $p = breakfast();
        $contact = (string) $p->portal()->findIdentity($this->identity)['contact_uuid'];

        // A sent proposal for this client, and a draft one (must not appear).
        $sent = $p->proposals()->create([
            'title' => 'Website build proposal', 'client_name' => 'Sian', 'client_email' => 'sian@roberts.example', 'contact_uuid' => $contact,
            'items' => [['kind' => 'fixed', 'description' => 'Build', 'quantity' => 1, 'unit_price' => 2000, 'tax_rate' => 0]],
        ], 'staff@breakfast');
        $p->proposals()->send((string) $sent['uuid'], 'staff@breakfast', ['company_legal_name' => 'Breakfast Ltd', 'proposal_prefix' => 'PRO']);
        $p->proposals()->create([
            'title' => 'Draft never sent', 'client_name' => 'Sian', 'client_email' => 'sian@roberts.example', 'contact_uuid' => $contact,
            'items' => [['kind' => 'fixed', 'description' => 'x', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]],
        ], 'staff@breakfast');

        $docs = $p->portal()->accessibleDocuments($this->identity);
        $titles = array_map(static fn (array $d): string => (string) $d['title'], $docs['proposals']);
        $this->assertContains('Website build proposal', $titles);
        $this->assertNotContains('Draft never sent', $titles, 'unsent drafts are never exposed to the client');
        $this->assertStringContainsString('/proposal/', (string) $docs['proposals'][0]['url']);

        // A different contact's proposal is not visible to this identity.
        $other = (string) $p->contacts()->create(['display_name' => 'Other', 'email' => 'o@x.co'])['uuid'];
        $op = $p->proposals()->create([
            'title' => 'Someone else’s proposal', 'client_name' => 'O', 'client_email' => 'o@x.co', 'contact_uuid' => $other,
            'items' => [['kind' => 'fixed', 'description' => 'y', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]],
        ], 'staff@breakfast');
        $p->proposals()->send((string) $op['uuid'], 'staff@breakfast', ['company_legal_name' => 'Breakfast Ltd', 'proposal_prefix' => 'PRO']);
        $stillTitles = array_map(static fn (array $d): string => (string) $d['title'], $p->portal()->accessibleDocuments($this->identity)['proposals']);
        $this->assertNotContains('Someone else’s proposal', $stillTitles);
    }

    public function testTwoWayMessagingWithReadTracking(): void
    {
        $p = breakfast();
        // Without a grant, the client cannot message.
        try {
            $p->portal()->postClientMessage($this->identity, $this->projectA, 'hello');
            $this->fail('expected message without a grant to be denied');
        } catch (PortalException $e) {
            $this->assertSame(403, $e->status);
        }

        $p->portal()->grantAccess($this->identity, 'project', $this->projectA, 'viewer', 'staff@breakfast');
        // Empty message rejected.
        try {
            $p->portal()->postClientMessage($this->identity, $this->projectA, '   ');
            $this->fail('expected empty message rejection');
        } catch (PortalException $e) {
            $this->assertSame(422, $e->status);
        }

        // Client sends → staff has one unread.
        $p->portal()->postClientMessage($this->identity, $this->projectA, 'When will the homepage be ready?');
        $this->assertSame(1, $p->portal()->unreadForStaff($this->projectA));

        // Staff reading the thread clears the client-unread; staff replies.
        $p->portal()->messageThread($this->projectA, $this->identity, 'staff');
        $this->assertSame(0, $p->portal()->unreadForStaff($this->projectA));
        $p->portal()->postStaffMessage($this->projectA, $this->identity, 'By Friday!', 'staff@breakfast');

        // The thread is ordered oldest-first and carries both sides.
        $thread = $p->portal()->messageThread($this->projectA, $this->identity, 'client');
        $this->assertCount(2, $thread);
        $this->assertSame('client', (string) $thread[0]['sender']);
        $this->assertSame('staff', (string) $thread[1]['sender']);

        // The client message reached the contact's CRM timeline.
        $contact = (string) $p->portal()->findIdentity($this->identity)['contact_uuid'];
        $types = array_map(static fn (array $a): string => (string) $a['type'], $p->activities()->forEntity('contact', $contact, 50));
        $this->assertContains('portal.message', $types);
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
