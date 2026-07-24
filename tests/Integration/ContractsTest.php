<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Contracts\ContractException;
use Breakfast\Platform\Contracts\Contracts;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Contracts & electronic signature. Proves the real state machine, immutable
 * version freezing at send, merge-field resolution (unresolved required fields
 * block sending), ordered multi-party signing (view ≠ sign), completion after
 * all required parties sign, evidenced signatures with a document hash, and
 * supersede/void guards.
 */
final class ContractsTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private Contracts $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-contracts-' . bin2hex(random_bytes(6));
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
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
        $this->svc = breakfast()->contracts();
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

    /** @return array<string,string> */
    private function merge(): array
    {
        return [
            'client_name' => 'Sian Roberts', 'company_name' => 'Roberts Cafe', 'seller_name' => 'Breakfast Ltd',
            'proposal_number' => 'PRO-2026-0001', 'project_name' => 'Website', 'contract_value' => '£3,600',
            'deposit_amount' => '£1,800', 'start_date' => '2026-08-01', 'target_date' => '2026-09-01',
            'revision_limit' => '2', 'governing_law' => 'the laws of England and Wales',
        ];
    }

    /** @return array<string,mixed> a sent contract */
    private function sent(): array
    {
        $c = $this->svc->create([
            'title' => 'Website build', 'template_key' => 'website_build',
            'client_name' => 'Sian Roberts', 'client_email' => 'sian@roberts.example', 'seller_name' => 'Breakfast Ltd',
            'contract_value' => 3600, 'deposit_amount' => 1800,
        ], 'admin@test');

        return $this->svc->send((string) $c['uuid'], 'admin@test', $this->merge());
    }

    public function testCreateSeedsBreakfastAndClientParties(): void
    {
        $c = $this->svc->create(['title' => 'X', 'client_name' => 'Sian', 'client_email' => 'sian@x.co'], 'admin@test');
        $roles = array_column($c['parties'], 'role');
        $this->assertContains('breakfast', $roles);
        $this->assertContains('client', $roles);
        $this->assertSame('draft', $c['status']);
    }

    public function testSendFreezesVersionAllocatesNumberAndResolvesMergeFields(): void
    {
        $c = $this->sent();
        $year = (int) date('Y');
        $this->assertSame("CTR-{$year}-0001", (string) $c['number']);
        $this->assertSame('sent', $c['status']);
        $this->assertNotEmpty($c['public_token']);
        $snap = $this->svc->snapshot((string) $c['uuid']);
        $this->assertNotNull($snap);
        // Merge fields resolved into the frozen clause text.
        $body = implode("\n", array_column($snap['sections'], 'body'));
        $this->assertStringContainsString('Roberts Cafe', $body);
        $this->assertStringNotContainsString('{{client_name}}', $body);
    }

    public function testUnresolvedRequiredMergeFieldBlocksSending(): void
    {
        $c = $this->svc->create(['title' => 'X', 'client_name' => 'Sian', 'client_email' => 'sian@x.co'], 'admin@test');
        // Missing governing_law etc. in the merge set.
        $this->assertContractError(422, fn () => $this->svc->send((string) $c['uuid'], 'admin@test', ['client_name' => 'Sian']));
    }

    public function testSentContractCannotBeEdited(): void
    {
        $c = $this->sent();
        $this->assertContractError(409, fn () => $this->svc->update((string) $c['uuid'], ['title' => 'Changed'], 'admin@test'));
    }

    public function testViewingIsNotSigning(): void
    {
        $c = $this->sent();
        $this->svc->markViewed((string) $c['uuid']);
        $reloaded = $this->svc->find((string) $c['uuid']);
        $this->assertSame('viewed', $reloaded['status']);
        $this->assertCount(0, $reloaded['signatures']);
    }

    public function testOrderedSigningRequiresBreakfastFirstThenClientCompletes(): void
    {
        $c = $this->sent();
        $uuid = (string) $c['uuid'];
        $client = $this->party($c, 'client');

        // Client can't sign before Breakfast (order 1).
        $this->assertContractError(409, fn () => $this->svc->sign($uuid, $client, ['name' => 'Sian']));

        // Breakfast countersigns first.
        $r1 = $this->svc->signInternal($uuid, ['name' => 'Chris Gwynne']);
        $this->assertFalse($r1['completed']);
        $this->assertSame('signed', (string) $this->party2($r1['contract'], 'breakfast')['status']);

        // Then the client signs → completed.
        $r2 = $this->svc->sign($uuid, $client, ['name' => 'Sian Roberts', 'email' => 'sian@roberts.example', 'ip_hash' => 'abc', 'user_agent' => 'UA']);
        $this->assertTrue($r2['completed']);
        $final = $this->svc->find($uuid);
        $this->assertSame('signed', $final['status']);
        $this->assertNotEmpty($final['completed_at']);
        $this->assertCount(2, $final['signatures']);
        $this->assertSame(64, strlen((string) $final['signatures'][0]['document_hash']));
    }

    public function testAlreadySignedPartyCannotSignTwice(): void
    {
        $c = $this->sent();
        $uuid = (string) $c['uuid'];
        $this->svc->signInternal($uuid, ['name' => 'Chris']);
        $this->assertContractError(409, fn () => $this->svc->signInternal($uuid, ['name' => 'Chris again']));
    }

    public function testExpiredContractCannotBeSigned(): void
    {
        $c = $this->svc->create(['title' => 'Old', 'client_name' => 'A', 'client_email' => 'a@x.co', 'expiry_date' => '2000-01-01'], 'admin@test');
        $c = $this->svc->send((string) $c['uuid'], 'admin@test', $this->merge());
        $this->assertContractError(410, fn () => $this->svc->signInternal((string) $c['uuid'], ['name' => 'Chris']));
    }

    public function testVoidRequiresReasonAndSupersedePreservesOriginal(): void
    {
        $c = $this->sent();
        $uuid = (string) $c['uuid'];
        $this->assertContractError(422, fn () => $this->svc->void($uuid, '  ', 'admin@test'));

        $v2 = $this->svc->supersede($uuid, 'Corrected fee', 'admin@test');
        $this->assertSame('draft', $v2['status']);
        $this->assertSame($uuid, (string) $v2['supersedes_uuid']);
        $this->assertSame('superseded', $this->svc->find($uuid)['status']);
    }

    public function testEveryTransitionIsRecorded(): void
    {
        $c = $this->sent();
        $uuid = (string) $c['uuid'];
        $this->svc->markViewed($uuid);
        $this->svc->signInternal($uuid, ['name' => 'Chris']);
        $this->svc->sign($uuid, $this->party($c, 'client'), ['name' => 'Sian']);

        $types = array_column($this->svc->find($uuid)['events'], 'type');
        foreach (['created', 'sent', 'viewed', 'countersigned', 'signed_by_client', 'completed'] as $t) {
            $this->assertContains($t, $types, "missing event $t");
        }
    }

    /** @param array<string,mixed> $c */
    private function party(array $c, string $role): string
    {
        foreach ($c['parties'] as $p) {
            if ((string) $p['role'] === $role) {
                return (string) $p['uuid'];
            }
        }
        $this->fail("no $role party");
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function party2(array $c, string $role): array
    {
        foreach ($c['parties'] as $p) {
            if ((string) $p['role'] === $role) {
                return $p;
            }
        }
        $this->fail("no $role party");
    }

    private function assertContractError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a ContractException');
        } catch (ContractException $e) {
            $this->assertSame($status, $e->status);
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
