<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Tries each delegate resolver in turn and returns the first match. Lets the
 * mail provider resolve attachment references from several modules (invoices,
 * contracts, …) without any of them knowing about the others.
 */
final class CompositeAttachmentResolver implements AttachmentResolver
{
    /** @var list<AttachmentResolver> */
    private array $resolvers;

    public function __construct(AttachmentResolver ...$resolvers)
    {
        $this->resolvers = array_values($resolvers);
    }

    public function resolve(string $reference): ?MailAttachment
    {
        foreach ($this->resolvers as $resolver) {
            $found = $resolver->resolve($reference);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
