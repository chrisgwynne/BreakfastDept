<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\ChangeRequests\ChangeRequestException;
use Breakfast\Platform\ChangeRequests\ChangeRequests;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Change requests + scope control. Proves server-computed pricing (fixed items
 * only, optional excluded), the real state machine, an immutable frozen snapshot
 * + real PDF + client token on send, draft-only editing, view ≠ approve,
 * explicit evidenced approval, idempotent decisions, and — above all — that
 * applying an approved change adds value to the PROJECT (never the proposal),
 * generates tasks with deterministic keys (idempotent), and drafts an invoice
 * whose total matches the approved version to the penny.
 */
final class ChangeRequestsTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private ChangeRequests $svc;
    private string $projectUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-cr-' . bin2hex(random_bytes(6));
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
        $this->svc = breakfast()->changeRequests();
        $project = breakfast()->projects()->create(['name' => 'Roberts Cafe website', 'quoted_value' => 3000], 'staff@breakfast');
        $this->projectUuid = (string) $project['uuid'];
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
    private function make(array $data = []): array
    {
        return $this->svc->create($this->projectUuid, array_merge([
            'title' => 'Add a bilingual blog',
            'items' => [
                ['description' => 'Blog templates', 'quantity' => 1, 'unit_price' => 800, 'kind' => 'fixed', 'estimate_hours' => 6],
                ['description' => 'Welsh translation', 'quantity' => 1, 'unit_price' => 200, 'kind' => 'optional'],
            ],
        ], $data), 'staff@breakfast');
    }

    public function testCreateRequiresTitle(): void
    {
        $this->expectException(ChangeRequestException::class);
        $this->svc->create($this->projectUuid, ['title' => '   '], 'staff@breakfast');
    }

    public function testPricingCountsFixedItemsOnly(): void
    {
        $cr = $this->make();
        // £800 fixed only; the £200 optional line does not count toward total.
        $this->assertSame(80000, (int) $cr['subtotal']);
        $this->assertSame(80000, (int) $cr['total']);
        $this->assertSame('draft', (string) $cr['status']);
        $this->assertSame('', (string) $cr['number']); // no number until sent
    }

    public function testDiscountAndTaxApplyInBasisPoints(): void
    {
        $cr = $this->make(['discount' => 10, 'tax_rate' => 20]); // 10% off, 20% VAT
        // gross 80000 → discount 8000 → subtotal 72000 → tax 14400 → total 86400
        $this->assertSame(72000, (int) $cr['subtotal']);
        $this->assertSame(14400, (int) $cr['tax_total']);
        $this->assertSame(86400, (int) $cr['total']);
    }

    public function testDraftIsEditableAndConcurrencyGuarded(): void
    {
        $cr = $this->make();
        $updated = $this->svc->update((string) $cr['uuid'], ['title' => 'Add a trilingual blog'], 'staff@breakfast', (int) $cr['revision']);
        $this->assertSame('Add a trilingual blog', (string) $updated['title']);

        $this->expectException(ChangeRequestException::class);
        $this->svc->update((string) $cr['uuid'], ['title' => 'stale write'], 'staff@breakfast', (int) $cr['revision']);
    }

    public function testInvalidTransitionRejected(): void
    {
        $cr = $this->make();
        $this->expectException(ChangeRequestException::class);
        $this->svc->transition((string) $cr['uuid'], 'approved', 'staff@breakfast');
    }

    public function testSendFreezesSnapshotRealPdfAndToken(): void
    {
        $cr = $this->make();
        $result = $this->svc->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');
        $sent = $result['change_request'];

        $this->assertSame('sent', (string) $sent['status']);
        $this->assertMatchesRegularExpression('/^CR-\d{4}-0001$/', (string) $sent['number']);
        $this->assertNotSame('', (string) $sent['public_token']);
        $this->assertStringContainsString('/change/' . (string) $sent['public_token'], $result['url']);
        $this->assertSame('generated', (string) $sent['document_status']);
        $this->assertSame(64, strlen((string) $sent['document_sha256']));

        // A real PDF is retrievable and passes its integrity check.
        $doc = $this->svc->download((string) $sent['uuid']);
        $this->assertSame('%PDF', substr($doc['bytes'], 0, 4));

        // A version row was frozen.
        $versions = breakfast()->db()->all('SELECT * FROM change_request_versions WHERE change_request_uuid = :u', ['u' => (string) $sent['uuid']]);
        $this->assertCount(1, $versions);
    }

    public function testSentChangeRequestCannotBeEdited(): void
    {
        $cr = $this->make();
        $this->svc->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');
        $this->expectException(ChangeRequestException::class);
        $this->svc->update((string) $cr['uuid'], ['title' => 'tamper'], 'staff@breakfast');
    }

    public function testViewingDoesNotApprove(): void
    {
        $cr = $this->make();
        $this->svc->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');
        $this->svc->markViewed((string) $cr['uuid']);
        $after = $this->svc->find((string) $cr['uuid']);
        $this->assertSame('viewed', (string) $after['status']);
    }

    public function testApprovalRequiresNameAndIsEvidenced(): void
    {
        $cr = $this->make();
        $this->svc->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');

        try {
            $this->svc->decide((string) $cr['uuid'], 'approved', [], 'client');
            $this->fail('Expected approval without a name to be rejected.');
        } catch (ChangeRequestException $e) {
            $this->assertSame(422, $e->status);
        }

        $approved = $this->svc->decide((string) $cr['uuid'], 'approved', ['name' => 'Angharad Roberts', 'email' => 'a@roberts.test'], 'client');
        $this->assertSame('approved', (string) $approved['status']);
        $this->assertNotEmpty($approved['acceptances']);
        $this->assertSame('Angharad Roberts', (string) $approved['acceptances'][0]['name']);
        $this->assertSame(80000, (int) $approved['acceptances'][0]['approved_total']);
    }

    public function testDecisionIsIdempotent(): void
    {
        $cr = $this->make();
        $this->svc->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');
        $this->svc->decide((string) $cr['uuid'], 'approved', ['name' => 'Angharad'], 'client');
        // Second approval returns state without adding a second acceptance.
        $again = $this->svc->decide((string) $cr['uuid'], 'approved', ['name' => 'Angharad'], 'client');
        $this->assertSame('approved', (string) $again['status']);
        $this->assertCount(1, $again['acceptances']);
    }

    public function testApplyAddsProjectValueGeneratesTasksAndDraftsMatchingInvoice(): void
    {
        $cr = $this->make(['tax_rate' => 20]); // total 96000
        $this->svc->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');
        $this->svc->decide((string) $cr['uuid'], 'approved', ['name' => 'Angharad'], 'client');
        $approvedTotal = (int) $this->svc->find((string) $cr['uuid'])['total'];

        $result = $this->svc->apply((string) $cr['uuid'], [], 'staff@breakfast');
        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['tasks']); // only the fixed item becomes a task
        $this->assertNotNull($result['invoice']);

        // Project carries the approved variation value; proposal is untouched
        // (there is no proposal — the value lives on the project, by design).
        $project = breakfast()->projects()->find($this->projectUuid);
        $this->assertSame($approvedTotal, (int) $project['approved_variations']);
        $this->assertSame(300000, (int) $project['quoted_value']); // original quote unchanged

        // Draft invoice total matches the approved change to the penny.
        $inv = breakfast()->invoices()->find((string) $result['invoice']);
        $this->assertSame('draft', (string) $inv['status']);
        $this->assertSame($approvedTotal, (int) $inv['total']);

        // The generated task links back deterministically.
        $link = breakfast()->db()->one('SELECT source_ref FROM change_request_task_links WHERE change_request_uuid = :u', ['u' => (string) $cr['uuid']]);
        $this->assertStringContainsString('change_request:' . (string) $cr['uuid'] . ':0', (string) $link['source_ref']);
    }

    public function testApplyIsIdempotent(): void
    {
        $cr = $this->make();
        $this->svc->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');
        $this->svc->decide((string) $cr['uuid'], 'approved', ['name' => 'Angharad'], 'client');
        $this->svc->apply((string) $cr['uuid'], [], 'staff@breakfast');

        $this->expectException(ChangeRequestException::class);
        $this->svc->apply((string) $cr['uuid'], [], 'staff@breakfast');
    }

    public function testCannotApplyUnapproved(): void
    {
        $cr = $this->make();
        $this->svc->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');
        $this->expectException(ChangeRequestException::class);
        $this->svc->apply((string) $cr['uuid'], [], 'staff@breakfast');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
