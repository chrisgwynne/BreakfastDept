<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Deterministic in-memory provider for development and tests. Records every
 * message and NEVER touches the network — so automated tests can never send a
 * real email. The next outcome can be scripted to exercise retry/permanent
 * paths.
 */
final class FakeMailProvider implements MailProvider
{
    /** @var list<MailMessage> */
    public array $sent = [];

    /** @var list<MailAttachment> attachments resolved during the last send() */
    public array $lastAttachments = [];

    /** @var list<MailResult> queued outcomes; falls back to accepted */
    private array $scripted = [];

    private int $counter = 0;

    public function __construct(
        private readonly AttachmentResolver $attachments = new NullAttachmentResolver(),
    ) {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function scriptNext(MailResult $result): void
    {
        $this->scripted[] = $result;
    }

    public function send(MailMessage $message): MailResult
    {
        $this->sent[] = $message;

        // Resolve attachment references exactly as a real provider would, so
        // tests can assert the genuine PDF bytes were produced at send time.
        $this->lastAttachments = [];
        foreach ($message->attachments as $ref) {
            $file = $this->attachments->resolve($ref);
            if ($file !== null) {
                $this->lastAttachments[] = $file;
            }
        }

        if ($this->scripted !== []) {
            return array_shift($this->scripted);
        }

        $this->counter++;

        return MailResult::accepted('fake-' . $this->counter);
    }

    public function healthCheck(): array
    {
        return ['ok' => true, 'detail' => 'fake provider'];
    }

    /** @return list<MailMessage> */
    public function messagesOfType(string $type): array
    {
        return array_values(array_filter($this->sent, static fn (MailMessage $m): bool => $m->messageType === $type));
    }
}
