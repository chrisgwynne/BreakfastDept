<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Proposals\ProposalException;
use Breakfast\Platform\Proposals\Proposals;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The proposals service — the commercial entry point. Proves pricing is exact
 * integer maths (fixed + selected optional, recurring summarised), state
 * transitions are real and guarded, sending freezes an immutable snapshot with a
 * monotonic number, and acceptance is a deliberate evidenced act (never a page
 * visit) that records who/when/what with a document hash.
 */
final class ProposalsTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private Proposals $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-proposals-' . bin2hex(random_bytes(6));
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
        $this->svc = breakfast()->proposals();
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

    /** @return array<string,mixed> */
    private function settings(): array
    {
        return ['company_legal_name' => 'Breakfast Ltd', 'vat_registered' => 1, 'proposal_prefix' => 'PRO'];
    }

    public function testCreateComputesTotalsFromFixedAndSelectedOptionalOnly(): void
    {
        $p = $this->svc->create([
            'title' => 'Website build',
            'client_name' => 'Roberts Cafe',
            'items' => [
                ['kind' => 'fixed', 'description' => 'Design & build', 'quantity' => 1, 'unit_price' => 3000, 'tax_rate' => 20],
                ['kind' => 'optional', 'description' => 'Copywriting', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 20],
                ['kind' => 'recurring', 'description' => 'Care plan', 'quantity' => 1, 'unit_price' => 50, 'recurrence' => 'monthly'],
            ],
        ], 'admin@test');

        // Only the fixed item counts until the optional one is selected.
        $this->assertSame(300000, (int) $p['subtotal']);
        $this->assertSame(60000, (int) $p['tax_total']);
        $this->assertSame(360000, (int) $p['total']);
        $this->assertSame(5000, (int) $p['recurring_total'], 'recurring is summarised separately');
        $this->assertSame('draft', $p['status']);
        $this->assertSame('', (string) $p['number'], 'no number until sent');
    }

    public function testSelectingAnOptionalItemAddsItToTheTotal(): void
    {
        $p = $this->sentProposal();
        $uuid = (string) $p['uuid'];
        $optional = array_values(array_filter($p['items'], fn ($i) => $i['kind'] === 'optional'))[0];

        $after = $this->svc->selectOptions($uuid, [(string) $optional['uuid']], 'client');
        $this->assertSame(360000 + 60000, (int) $after['total']); // + £500 + 20% VAT
    }

    public function testSendAllocatesMonotonicNumberFreezesSnapshotAndLocksEditing(): void
    {
        $a = $this->sentProposal();
        $b = $this->sentProposal();
        $year = (int) date('Y');
        $this->assertSame("PRO-{$year}-0001", (string) $a['number']);
        $this->assertSame("PRO-{$year}-0002", (string) $b['number']);
        $this->assertSame('sent', $a['status']);
        $this->assertNotEmpty($a['public_token']);
        $this->assertNotNull($this->svc->snapshot((string) $a['uuid']));

        $this->assertProposalError(409, fn () => $this->svc->update((string) $a['uuid'], ['title' => 'Changed'], 'admin@test'));
    }

    public function testViewingIsNotAcceptance(): void
    {
        $p = $this->sentProposal();
        $uuid = (string) $p['uuid'];
        $this->svc->markViewed($uuid);

        $reloaded = $this->svc->find($uuid);
        $this->assertSame('viewed', $reloaded['status']);
        $this->assertNotEmpty($reloaded['viewed_at']);
        $this->assertCount(0, $reloaded['acceptances'], 'a view records no acceptance');
    }

    public function testAcceptRecordsEvidencedAcceptanceWithHash(): void
    {
        $p = $this->sentProposal();
        $uuid = (string) $p['uuid'];

        $accepted = $this->svc->accept($uuid, [
            'name' => 'Sian Roberts', 'email' => 'sian@roberts.example',
            'ip_hash' => 'abc123', 'user_agent' => 'Mozilla/5.0', 'wording' => 'I accept this proposal.',
        ]);

        $this->assertSame('accepted', $accepted['status']);
        $this->assertNotEmpty($accepted['accepted_at']);
        $this->assertCount(1, $accepted['acceptances']);
        $acc = $accepted['acceptances'][0];
        $this->assertSame('Sian Roberts', (string) $acc['accepted_name']);
        $this->assertSame(64, strlen((string) $acc['document_hash']));
        $this->assertSame(360000, (int) $acc['total_accepted']);

        // A second acceptance is rejected.
        $this->assertProposalError(409, fn () => $this->svc->accept($uuid, ['name' => 'Someone']));
    }

    public function testAcceptRequiresANameAndRejectsExpired(): void
    {
        $p = $this->sentProposal();
        $this->assertProposalError(422, fn () => $this->svc->accept((string) $p['uuid'], ['name' => '  ']));

        $expired = $this->svc->create([
            'title' => 'Old', 'expiry_date' => '2000-01-01',
            'items' => [['kind' => 'fixed', 'description' => 'x', 'quantity' => 1, 'unit_price' => 100]],
        ], 'admin@test');
        $expired = $this->svc->send((string) $expired['uuid'], 'admin@test', $this->settings());
        $this->assertProposalError(410, fn () => $this->svc->accept((string) $expired['uuid'], ['name' => 'A']));
    }

    public function testSupersedeCopiesContentAndMarksOriginalSuperseded(): void
    {
        $p = $this->sentProposal();
        $v2 = $this->svc->supersede((string) $p['uuid'], 'admin@test');

        $this->assertSame('draft', $v2['status']);
        $this->assertSame((string) $p['uuid'], (string) $v2['supersedes_uuid']);
        $this->assertCount(count($p['items']), $v2['items']);
        $this->assertSame('superseded', $this->svc->find((string) $p['uuid'])['status']);
    }

    public function testEveryStateChangeIsRecordedAsAnEvent(): void
    {
        $p = $this->sentProposal();
        $uuid = (string) $p['uuid'];
        $this->svc->markViewed($uuid);
        $this->svc->accept($uuid, ['name' => 'Sam']);

        $types = array_column($this->svc->find($uuid)['events'], 'type');
        $this->assertContains('created', $types);
        $this->assertContains('sent', $types);
        $this->assertContains('viewed', $types);
        $this->assertContains('accepted', $types);
    }

    public function testGeneratesARealStoredVerifiablePdf(): void
    {
        $p = $this->sentProposal();
        $uuid = (string) $p['uuid'];

        $after = breakfast()->proposalDocuments()->generate($uuid, 'admin@test');
        $this->assertSame('generated', (string) $after['document_status']);
        $this->assertSame(64, strlen((string) $after['document_hash']));

        $dl = breakfast()->proposalDocuments()->download($uuid);
        $this->assertSame('%PDF', substr($dl['bytes'], 0, 4), 'a real PDF binary is produced');
        $this->assertGreaterThan(500, strlen($dl['bytes']));
        $this->assertStringEndsWith('.pdf', $dl['filename']);

        // Tamper detection.
        $key = (string) breakfast()->proposals()->find($uuid)['document_key'];
        file_put_contents($this->tmp . '/proposals/' . $key, '%PDF-not-original');
        $this->assertProposalError(409, fn () => breakfast()->proposalDocuments()->download($uuid));
    }

    /** A £3600 sent proposal (1 fixed £3000 +20% VAT, 1 optional, 1 recurring). */
    private function sentProposal(): array
    {
        $p = $this->svc->create([
            'title' => 'Website build',
            'client_name' => 'Roberts Cafe', 'client_email' => 'sian@roberts.example',
            'items' => [
                ['kind' => 'fixed', 'description' => 'Design & build', 'quantity' => 1, 'unit_price' => 3000, 'tax_rate' => 20],
                ['kind' => 'optional', 'description' => 'Copywriting', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 20],
                ['kind' => 'recurring', 'description' => 'Care plan', 'quantity' => 1, 'unit_price' => 50, 'recurrence' => 'monthly'],
            ],
        ], 'admin@test');

        return $this->svc->send((string) $p['uuid'], 'admin@test', $this->settings());
    }

    private function assertProposalError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a ProposalException');
        } catch (ProposalException $e) {
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
