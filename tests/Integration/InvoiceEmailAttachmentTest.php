<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Mail\FakeMailProvider;
use Breakfast\Platform\Mail\MailProviderFactory;
use Breakfast\Platform\Queue\Worker;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * Proves invoice emails carry the GENUINE issued PDF as a real attachment, and
 * that the attachment is handled correctly across the queue boundary:
 *
 *  - the queued job stores only a lightweight reference ("invoice:<uuid>"),
 *    never the file bytes or a base64 blob;
 *  - the worker resolves that reference to the real, integrity-checked PDF at
 *    send time (where base64 encoding for the provider happens);
 *  - a tampered/absent document yields no attachment rather than a corrupt one.
 */
final class InvoiceEmailAttachmentTest extends PlatformTestCase
{
    /** @return array{0:string,1:FakeMailProvider} issued invoice uuid + resolver-equipped fake provider */
    private function issuedInvoiceWithPdf(): array
    {
        $svc = $this->platform->invoices();
        $svc->updateSettings(['vat_registered' => true, 'company_legal_name' => 'Breakfast Ltd']);
        $inv = $svc->create([
            'bill_to_name'  => 'Roberts Cafe',
            'bill_to_email' => 'client@roberts.example',
            'items' => [['description' => 'Website', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 20]],
        ], 'admin@test');
        $uuid = (string) $inv['uuid'];
        $svc->issue($uuid, 'admin@test');
        $this->platform->invoiceDocuments()->generateIssued($uuid, 'admin@test');

        // Swap in a fake provider that shares the platform's real resolver, so
        // attachment resolution runs exactly as it would in production.
        $fake = new FakeMailProvider($this->platform->attachmentResolver());
        MailProviderFactory::override($fake);

        return [$uuid, $fake];
    }

    public function testQueueHoldsAReferenceNotTheBytesAndWorkerAttachesRealPdf(): void
    {
        [$uuid, $fake] = $this->issuedInvoiceWithPdf();

        $res = $this->platform->crmMail()->send('client@roberts.example', 'Invoice INV-1', 'Here it is', [], 'admin@test', ['invoice:' . $uuid]);
        $this->assertTrue($res['ok']);

        // The queued job payload contains the reference, and definitely NOT a
        // base64 PDF blob (%PDF → "JVBERi0" once base64-encoded).
        $payload = (string) ($this->platform->fileStore()->all('jobs')[0]['payload'] ?? '');
        $this->assertStringContainsString('invoice:' . $uuid, $payload);
        $this->assertStringNotContainsString('JVBERi0', $payload, 'the queue must not carry base64 PDF bytes');
        $this->assertLessThan(4000, strlen($payload), 'a reference keeps the job payload tiny');

        // The worker resolves the reference to the genuine PDF at send time.
        (new Worker($this->platform))->runOnce();
        $this->assertCount(1, $fake->sent);
        $this->assertCount(1, $fake->lastAttachments);
        $att = $fake->lastAttachments[0];
        $this->assertStringEndsWith('.pdf', $att->filename);
        $this->assertSame('%PDF', substr($att->bytes, 0, 4), 'a real PDF binary is attached');
        $this->assertStringStartsWith('JVBERi0', $att->base64(), 'base64 encoding happens in the worker/provider');
    }

    public function testMissingDocumentResolvesToNoAttachmentRatherThanCorruptOne(): void
    {
        [, $fake] = $this->issuedInvoiceWithPdf();

        // Reference an invoice with no document at all.
        $this->platform->crmMail()->send('client@roberts.example', 'Invoice', 'Body', [], 'admin@test', ['invoice:does-not-exist']);
        (new Worker($this->platform))->runOnce();

        $this->assertCount(1, $fake->sent);
        $this->assertCount(0, $fake->lastAttachments, 'an unresolvable reference is simply omitted');
    }
}
