<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Turns a lightweight, persisted attachment REFERENCE (e.g. "invoice:<uuid>")
 * into the real file bytes at send time.
 *
 * The queue stores only references — never file content — so a resolver runs
 * exclusively inside the worker, immediately before a provider transmits the
 * message. Returning null means "no such attachment"; the provider then simply
 * omits it rather than failing the whole send.
 */
interface AttachmentResolver
{
    public function resolve(string $reference): ?MailAttachment;
}
