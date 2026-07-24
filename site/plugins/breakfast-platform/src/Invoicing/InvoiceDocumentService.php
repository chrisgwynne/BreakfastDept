<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

use Breakfast\Platform\Crm\ActivityRepository;
use Breakfast\Platform\Hermes\AuditLog;

/**
 * Orchestrates invoice PDF generation, storage and retrieval.
 *
 * Issued invoices render from their frozen snapshot (immutable); drafts render
 * from live data with a DRAFT watermark and are replaceable. Generation is
 * synchronous — Dompdf renders an invoice in well under a second, so the
 * document is ready the moment issuance completes; a failure leaves the invoice
 * issued but in a document_status of 'failed' so sending stays blocked until a
 * safe retry succeeds.
 */
final class InvoiceDocumentService
{
    public function __construct(
        private readonly Invoices $invoices,
        private readonly InvoicePdfRenderer $renderer,
        private readonly InvoiceDocumentStore $store,
        private readonly ActivityRepository $activities,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Generate (or first-generate) the immutable PDF for an issued invoice.
     *
     * @return array<string,mixed>
     */
    public function generateIssued(string $uuid, string $actor): array
    {
        $inv = $this->invoices->find($uuid);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        if (($inv['status'] ?? 'draft') === 'draft') {
            throw new InvoiceException(409, 'Only issued invoices have a final PDF.');
        }
        $snapshot = $this->invoices->snapshot($uuid);
        if ($snapshot === null) {
            throw new InvoiceException(409, 'This invoice has no snapshot to render.');
        }

        try {
            $bytes = $this->renderer->render($snapshot, false);
            $doc = $this->store->store($uuid, $bytes, $this->filename($snapshot), 'issued', $actor, InvoiceSnapshot::VERSION, $this->renderer->version());
        } catch (\Throwable $e) {
            $this->invoices->setDocumentStatus($uuid, 'failed');
            throw $e instanceof InvoiceException ? $e : new InvoiceException(500, 'The invoice PDF could not be generated.');
        }

        $this->invoices->setDocumentStatus($uuid, 'generated');
        $this->invoices->logEvent($uuid, 'document_generated', 'PDF generated (' . (string) $doc['filename'] . ')', $actor);
        $this->crmActivity($inv, 'invoice.document_generated', 'Invoice ' . (string) ($inv['number'] ?? '') . ' PDF generated');
        $this->audit->event('invoice.pdf_generated', 'invoice', $uuid, $actor, ['sha256' => $doc['sha256'] ?? '', 'version' => $doc['version'] ?? 1]);

        return $doc;
    }

    /**
     * Regenerate an issued invoice's PDF as a NEW version, preserving the
     * original. Requires an explicit reason and is audited.
     *
     * @return array<string,mixed>
     */
    public function regenerateIssued(string $uuid, string $actor, string $reason): array
    {
        if (trim($reason) === '') {
            throw new InvoiceException(422, 'A reason is required to regenerate an issued document.');
        }
        $snapshot = $this->invoices->snapshot($uuid);
        if ($snapshot === null) {
            throw new InvoiceException(409, 'This invoice has no snapshot to render.');
        }
        $bytes = $this->renderer->render($snapshot, false);
        $doc = $this->store->store($uuid, $bytes, $this->filename($snapshot), 'issued', $actor, InvoiceSnapshot::VERSION, $this->renderer->version());

        $this->invoices->logEvent($uuid, 'document_regenerated', 'PDF regenerated: ' . $reason, $actor);
        $this->audit->event('invoice.pdf_regenerated', 'invoice', $uuid, $actor, ['reason' => $reason, 'version' => $doc['version'] ?? 1]);

        return $doc;
    }

    /**
     * Generate a replaceable draft preview PDF (DRAFT watermark) from live data.
     *
     * @return array<string,mixed>
     */
    public function generateDraft(string $uuid, string $actor): array
    {
        $inv = $this->invoices->find($uuid);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        $snapshot = InvoiceSnapshot::build($inv, $this->invoices->settings());
        $bytes = $this->renderer->render($snapshot, true);

        return $this->store->store($uuid, $bytes, $this->draftFilename($uuid), 'draft', $actor, InvoiceSnapshot::VERSION, $this->renderer->version());
    }

    /**
     * Resolve the document to download and return its verified bytes.
     * Ordinary downloads return the original issued file; drafts fall back to
     * the latest draft.
     *
     * @return array{doc:array<string,mixed>,bytes:string}
     */
    public function download(string $uuid, ?string $documentUuid = null): array
    {
        $doc = $documentUuid !== null && $documentUuid !== ''
            ? $this->store->find($documentUuid)
            : ($this->store->currentIssued($uuid) ?? $this->store->currentDraft($uuid));
        if ($doc === null || (string) ($doc['invoice_uuid'] ?? '') !== $uuid) {
            throw new InvoiceException(404, 'No document available for this invoice.');
        }
        if (!$this->store->verify($doc)) {
            throw new InvoiceException(409, 'The stored document failed its integrity check.');
        }

        return ['doc' => $doc, 'bytes' => $this->store->read($doc)];
    }

    /** @return list<array<string,mixed>> */
    public function versions(string $uuid): array
    {
        return $this->store->versions($uuid);
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function filename(array $snapshot): string
    {
        $number = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($snapshot['number'] ?? 'invoice')) ?: 'invoice';

        return 'BREAKFAST-' . $number . '.pdf';
    }

    private function draftFilename(string $uuid): string
    {
        return 'BREAKFAST-DRAFT-' . substr($uuid, 0, 8) . '.pdf';
    }

    /** @param array<string,mixed> $inv */
    private function crmActivity(array $inv, string $type, string $summary): void
    {
        $contact = (string) ($inv['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->activities->record('contact', $contact, $type, $summary, 'system', 'invoice', ['invoice' => (string) ($inv['uuid'] ?? '')]);
        }
    }
}
