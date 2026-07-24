<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

use Breakfast\Platform\Mail\AttachmentResolver;
use Breakfast\Platform\Mail\MailAttachment;

/**
 * Resolves invoice-PDF attachment references to real, integrity-checked bytes.
 *
 * Supported references:
 *   invoice:<invoiceUuid>       → the original issued PDF for that invoice
 *   invoice-doc:<documentUuid>  → a specific stored document version
 *
 * The bytes are read from the private document store and verified against the
 * recorded SHA-256 before being returned, so a corrupted or tampered file is
 * never attached. Returns null when there is no matching, valid document — the
 * provider then sends without the attachment rather than failing outright
 * (sending is gated separately, before queueing, on the document being ready).
 */
final class InvoiceAttachmentResolver implements AttachmentResolver
{
    public function __construct(
        private readonly InvoiceDocumentStore $store,
    ) {
    }

    public function resolve(string $reference): ?MailAttachment
    {
        [$kind, $id] = array_pad(explode(':', $reference, 2), 2, '');
        if ($id === '') {
            return null;
        }

        $doc = match ($kind) {
            'invoice'     => $this->store->currentIssued($id),
            'invoice-doc' => $this->store->find($id),
            default       => null,
        };
        if ($doc === null || !$this->store->verify($doc)) {
            return null;
        }

        return new MailAttachment(
            filename: (string) ($doc['filename'] ?? 'invoice.pdf'),
            bytes: $this->store->read($doc),
            mime: (string) ($doc['mime'] ?? 'application/pdf'),
        );
    }
}
