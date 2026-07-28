<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Invoicing\InvoiceException;
use Breakfast\Platform\Invoicing\Invoices;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The invoicing service that powers "Create / issue / email / get paid".
 * Proves every action is REAL and server-authoritative: figures are computed
 * in exact integer pence, numbers are allocated monotonically, state only
 * advances through genuine operations, and the guards (edit-after-issue,
 * void-when-paid, pay-before-issue) actually block. Nothing is faked.
 */
final class InvoicesTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private Invoices $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-invoices-' . bin2hex(random_bytes(6));

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
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        $this->svc = breakfast()->invoices();
    }

    protected function tearDown(): void
    {
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testCreateDraftComputesExactLineMathWithVatAndDiscount(): void
    {
        // 10 × £300 = £3000, less 10% discount = £2700 subtotal, + 20% VAT = £540.
        $this->svc->updateSettings(['vat_registered' => true, 'default_vat_rate' => 2000]);
        $inv = $this->svc->create([
            'bill_to_name' => 'Roberts Cafe',
            'items' => [
                ['description' => 'Website build', 'quantity' => 10, 'unit_price' => 300, 'tax_rate' => 20, 'discount' => 10],
            ],
        ], 'admin@test');

        // Stored in pence.
        $this->assertSame('draft', $inv['status']);
        $this->assertSame(270000, (int) $inv['subtotal']);
        $this->assertSame(54000, (int) $inv['tax_total']);
        $this->assertSame(324000, (int) $inv['total']);
        $this->assertSame(324000, (int) $inv['amount_due']);
        $this->assertSame('', (string) $inv['number'], 'draft has no number yet');
        $this->assertCount(1, $inv['items']);
    }

    public function testIssueAllocatesMonotonicNumbersAndLocksTheInvoice(): void
    {
        $a = $this->issuedInvoice();
        $b = $this->issuedInvoice();

        $year = (int) date('Y');
        $this->assertSame("INV-{$year}-0001", (string) $a['number']);
        $this->assertSame("INV-{$year}-0002", (string) $b['number']);
        $this->assertSame('issued', $a['status']);
        $this->assertNotSame('', (string) $a['public_token'], 'issuing mints a signed public token');
        $this->assertNotEmpty($a['issue_date']);
        $this->assertNotEmpty($a['due_date']);
    }

    public function testIssuedInvoiceCannotBeEdited(): void
    {
        $inv = $this->issuedInvoice();
        $this->assertInvoiceError(409, fn () => $this->svc->update((string) $inv['uuid'], ['bill_to_name' => 'Changed'], 'admin@test'));
    }

    public function testIssuingRequiresAtLeastOneLineItem(): void
    {
        $inv = $this->svc->create(['bill_to_name' => 'Empty'], 'admin@test');
        $this->assertInvoiceError(422, fn () => $this->svc->issue((string) $inv['uuid'], 'admin@test'));
    }

    public function testPartialThenFullPaymentDrivesStatusToPaid(): void
    {
        $inv  = $this->issuedInvoice(); // £1200 total
        $uuid = (string) $inv['uuid'];
        $total = (int) $inv['total'];

        $after = $this->svc->recordPayment($uuid, ['amount' => 5, 'method' => 'bank'], 'admin@test');
        $this->assertSame('partial', $after['status']);
        $this->assertSame(500, (int) $after['amount_paid']);
        $this->assertSame($total - 500, (int) $after['amount_due']);

        $paid = $this->svc->recordPayment($uuid, ['amount' => ($total - 500) / 100, 'method' => 'bank'], 'admin@test');
        $this->assertSame('paid', $paid['status']);
        $this->assertSame(0, (int) $paid['amount_due']);
        $this->assertNotEmpty($paid['paid_at']);
        $this->assertCount(2, $paid['payments']);
    }

    public function testPaymentBeforeIssueIsRejected(): void
    {
        $inv = $this->svc->create([
            'bill_to_name' => 'Draft',
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]],
        ], 'admin@test');
        $this->assertInvoiceError(409, fn () => $this->svc->recordPayment((string) $inv['uuid'], ['amount' => 10], 'admin@test'));
    }

    public function testPaidInvoiceCannotBeVoided(): void
    {
        $inv  = $this->issuedInvoice();
        $uuid = (string) $inv['uuid'];
        $this->svc->recordPayment($uuid, ['amount' => (int) $inv['total'] / 100], 'admin@test');

        $this->assertInvoiceError(409, fn () => $this->svc->void($uuid, 'admin@test'));
    }

    public function testVoidStopsAnIssuedInvoiceAndBlocksPayment(): void
    {
        $inv  = $this->issuedInvoice();
        $uuid = (string) $inv['uuid'];
        $void = $this->svc->void($uuid, 'admin@test');
        $this->assertSame('void', $void['status']);
        $this->assertNotEmpty($void['voided_at']);

        $this->expectException(InvoiceException::class);
        $this->svc->recordPayment($uuid, ['amount' => 1], 'admin@test');
    }

    public function testFindByTokenReturnsInvoiceAndMarkViewedAdvancesState(): void
    {
        $inv   = $this->issuedInvoice();
        $token = (string) $inv['public_token'];

        $found = $this->svc->findByToken($token);
        $this->assertNotNull($found);
        $this->assertSame($inv['uuid'], $found['uuid']);

        $this->svc->markViewed((string) $inv['uuid']);
        $reloaded = $this->svc->find((string) $inv['uuid']);
        $this->assertSame('viewed', $reloaded['status']);
        $this->assertNotEmpty($reloaded['viewed_at']);
    }

    public function testEveryStateChangeIsRecordedAsAnImmutableEvent(): void
    {
        $inv  = $this->issuedInvoice();
        $uuid = (string) $inv['uuid'];
        $this->svc->markSent($uuid, 'admin@test', 'Emailed to client');
        $this->svc->recordPayment($uuid, ['amount' => 1], 'admin@test');

        $types = array_column($this->svc->find($uuid)['events'], 'type');
        $this->assertContains('created', $types);
        $this->assertContains('issued', $types);
        $this->assertContains('sent', $types);
        $this->assertContains('payment', $types);
    }

    /**
     * A £1200 issued invoice (1 × £1000 + 20% VAT), the common fixture.
     *
     * @return array<string,mixed>
     */
    private function issuedInvoice(): array
    {
        $this->svc->updateSettings(['vat_registered' => true, 'company_legal_name' => 'Breakfast Ltd']);
        $inv = $this->svc->create([
            'bill_to_name' => 'Client',
            'items' => [['description' => 'Design', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 20]],
        ], 'admin@test');

        return $this->svc->issue((string) $inv['uuid'], 'admin@test');
    }

    private function assertInvoiceError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected an InvoiceException');
        } catch (InvoiceException $e) {
            $this->assertSame($status, $e->status);
        }
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
