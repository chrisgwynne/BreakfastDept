<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Invoicing\InvoiceDocumentService;
use Breakfast\Platform\Invoicing\InvoiceException;
use Breakfast\Platform\Invoicing\Invoices;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Proves the invoice PDF pipeline produces GENUINE .pdf files, not a
 * print-friendly webpage: a real binary (%PDF signature) is rendered from the
 * frozen snapshot, stored outside the webroot under an opaque key with its
 * hash/size/renderer recorded, verified on download, and the issued original is
 * preserved when a regeneration adds a new version.
 */
final class InvoiceDocumentsTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private Invoices $svc;
    private InvoiceDocumentService $docs;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-invdocs-' . bin2hex(random_bytes(6));

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
        $this->svc  = breakfast()->invoices();
        $this->docs = breakfast()->invoiceDocuments();
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

    public function testIssuingGeneratesARealStoredVerifiablePdf(): void
    {
        $inv  = $this->issuedInvoice();
        $uuid = (string) $inv['uuid'];

        $doc = $this->docs->generateIssued($uuid, 'admin@test');

        // The stored artefact is a genuine PDF binary, not HTML.
        $bytes = $this->docs->download($uuid)['bytes'];
        $this->assertSame('%PDF', substr($bytes, 0, 4), 'a real PDF binary is produced');
        $this->assertGreaterThan(500, strlen($bytes));

        // Metadata is complete and the on-disk hash matches what we recorded.
        $this->assertSame('issued', (string) $doc['kind']);
        $this->assertSame('dompdf', (string) $doc['renderer']);
        $this->assertSame(hash('sha256', $bytes), (string) $doc['sha256']);
        $this->assertSame(strlen($bytes), (int) $doc['byte_size']);
        $this->assertSame('generated', (string) $this->svc->find($uuid)['document_status']);
    }

    public function testStoredFileLivesOutsideTheWebrootUnderAnOpaqueKey(): void
    {
        $uuid = (string) $this->issuedInvoice()['uuid'];
        $doc  = $this->docs->generateIssued($uuid, 'admin@test');

        $key = (string) $doc['storage_key'];
        // Opaque: a random hex name, never the invoice number or a guessable path.
        $this->assertMatchesRegularExpression('#^' . preg_quote($uuid, '#') . '/[0-9a-f]{32}\.pdf$#', $key);
        $this->assertStringNotContainsString('public', (string) breakfast()->storageDir());
        $this->assertFileExists($this->tmp . '/invoices/' . $key);
    }

    public function testDownloadFailsIfTheStoredFileIsTamperedWith(): void
    {
        $uuid = (string) $this->issuedInvoice()['uuid'];
        $doc  = $this->docs->generateIssued($uuid, 'admin@test');

        file_put_contents($this->tmp . '/invoices/' . (string) $doc['storage_key'], '%PDF-not-the-original');

        $this->assertInvoiceError(409, fn () => $this->docs->download($uuid));
    }

    public function testRegenerationPreservesTheOriginalIssuedDocument(): void
    {
        $uuid = (string) $this->issuedInvoice()['uuid'];
        $original = $this->docs->generateIssued($uuid, 'admin@test');

        $regenerated = $this->docs->regenerateIssued($uuid, 'admin@test', 'Corrected VAT number');

        $this->assertSame(2, (int) $regenerated['version']);
        $this->assertNotSame($original['storage_key'], $regenerated['storage_key']);

        // The original (version 1) is still on disk and is what ordinary
        // downloads return — issued documents are immutable.
        $this->assertFileExists($this->tmp . '/invoices/' . (string) $original['storage_key']);
        $default = $this->docs->download($uuid)['doc'];
        $this->assertSame($original['uuid'], $default['uuid']);
        $this->assertCount(2, $this->docs->versions($uuid));
    }

    public function testRegenerationRequiresAReason(): void
    {
        $uuid = (string) $this->issuedInvoice()['uuid'];
        $this->docs->generateIssued($uuid, 'admin@test');

        $this->assertInvoiceError(422, fn () => $this->docs->regenerateIssued($uuid, 'admin@test', '   '));
    }

    public function testDraftPreviewIsReplaceableAndWatermarked(): void
    {
        $inv = $this->svc->create([
            'bill_to_name' => 'Draft Co',
            'items' => [['description' => 'Discovery', 'quantity' => 1, 'unit_price' => 500]],
        ], 'admin@test');
        $uuid = (string) $inv['uuid'];

        $first  = $this->docs->generateDraft($uuid, 'admin@test');
        $second = $this->docs->generateDraft($uuid, 'admin@test');

        // Regenerating a draft replaces the prior one (only one draft is kept).
        $this->assertFileDoesNotExist($this->tmp . '/invoices/' . (string) $first['storage_key']);
        $this->assertFileExists($this->tmp . '/invoices/' . (string) $second['storage_key']);
        $this->assertSame('%PDF', substr($this->docs->download($uuid)['bytes'], 0, 4));
    }

    public function testDraftInvoiceHasNoIssuedDocument(): void
    {
        $inv = $this->svc->create([
            'bill_to_name' => 'Nope',
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]],
        ], 'admin@test');

        $this->assertInvoiceError(409, fn () => $this->docs->generateIssued((string) $inv['uuid'], 'admin@test'));
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

    public function testBackfillGeneratesMissingPdfsIdempotentlyAndDryRunWritesNothing(): void
    {
        // Two issued invoices whose documents were never generated (simulating
        // invoices issued before the PDF pipeline existed).
        $a = (string) $this->issuedInvoice()['uuid'];
        $b = (string) $this->issuedInvoice()['uuid'];

        // Dry run reports both but writes nothing.
        $preview = $this->docs->backfill(false, 'cli');
        $this->assertSame(2, $preview['scanned']);
        $this->assertSame(0, $preview['generated']);
        $this->assertFalse(is_dir($this->tmp . '/invoices/' . $a), 'dry-run must not write files');

        // Apply generates real documents for both.
        $applied = $this->docs->backfill(true, 'cli');
        $this->assertSame(2, $applied['generated']);
        $this->assertSame(0, $applied['failed']);
        $this->assertSame('%PDF', substr($this->docs->download($a)['bytes'], 0, 4));
        $this->assertSame('%PDF', substr($this->docs->download($b)['bytes'], 0, 4));

        // Idempotent: a second apply finds nothing left to do.
        $again = $this->docs->backfill(true, 'cli');
        $this->assertSame(0, $again['scanned']);
    }

    /**
     * @return array<string,mixed>
     */
    private function issuedInvoice(): array
    {
        $this->svc->updateSettings(['vat_registered' => true, 'company_legal_name' => 'Breakfast Ltd']);
        $inv = $this->svc->create([
            'bill_to_name' => 'Roberts Cafe',
            'bill_to_email' => 'hello@roberts.example',
            'items' => [['description' => 'Website design', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 20]],
        ], 'admin@test');

        return $this->svc->issue((string) $inv['uuid'], 'admin@test');
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
