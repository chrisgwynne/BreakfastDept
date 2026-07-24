<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

use Breakfast\Platform\Mail\AttachmentResolver;
use Breakfast\Platform\Mail\MailAttachment;

/**
 * Resolves contract-PDF attachment references to real, integrity-checked bytes
 * at send time. References:
 *   contract-signed:<uuid>   → the final signed PDF
 *   contract-unsigned:<uuid> → the unsigned contract PDF
 * Returns null (no attachment) rather than failing if the document is absent or
 * fails its hash check.
 */
final class ContractAttachmentResolver implements AttachmentResolver
{
    public function __construct(private readonly ContractDocumentService $documents)
    {
    }

    public function resolve(string $reference): ?MailAttachment
    {
        [$kind, $id] = array_pad(explode(':', $reference, 2), 2, '');
        if ($id === '') {
            return null;
        }
        $which = match ($kind) {
            'contract-signed'   => 'signed',
            'contract-unsigned' => 'unsigned',
            default             => null,
        };
        if ($which === null) {
            return null;
        }
        try {
            $result = $this->documents->download($id, $which);
        } catch (\Throwable) {
            return null;
        }

        return new MailAttachment($result['filename'], $result['bytes'], 'application/pdf');
    }
}
