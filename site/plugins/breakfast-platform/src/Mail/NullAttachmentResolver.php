<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Default resolver used when nothing can supply attachment bytes (most mail has
 * no attachments). It resolves nothing, so providers transmit messages exactly
 * as before.
 */
final class NullAttachmentResolver implements AttachmentResolver
{
    public function resolve(string $reference): ?MailAttachment
    {
        return null;
    }
}
