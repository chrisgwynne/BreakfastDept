<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * A fully-resolved email attachment: the real file bytes plus a download name.
 *
 * This object only ever exists transiently inside the worker at send time — it
 * is produced by an {@see AttachmentResolver} from a lightweight storage
 * reference held on the queued message, and handed straight to the provider.
 * The raw bytes (and their base64 form) are NEVER persisted in the queue, so a
 * pending email carries a reference, not a megabyte blob.
 */
final class MailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $bytes,
        public readonly string $mime = 'application/pdf',
    ) {
    }

    /** Base64 for providers (e.g. Brevo) that inline attachment content. */
    public function base64(): string
    {
        return base64_encode($this->bytes);
    }
}
