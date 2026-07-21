<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Uuid;

/**
 * Provider-agnostic transactional message. Built by application services and
 * handed to a MailProvider. Carries the CRM correlation UUIDs and everything a
 * provider needs to send, without any provider-specific shape.
 *
 * A message uses EITHER a provider template id + params, OR application-rendered
 * html/text, OR both (template preferred when an id is present).
 */
final class MailMessage
{
    public readonly string $uuid;
    public readonly string $createdAt;

    /**
     * @param list<EmailAddress>   $to
     * @param list<EmailAddress>   $cc
     * @param list<EmailAddress>   $bcc
     * @param array<string,scalar> $params      template parameters (safe, structured)
     * @param list<string>         $tags
     * @param array<string,string> $headers
     * @param list<string>         $attachments stored upload UUIDs (never raw paths)
     */
    public function __construct(
        public readonly string $messageType,
        public readonly EmailAddress $sender,
        public readonly array $to,
        public readonly string $subject,
        public readonly ?string $html = null,
        public readonly ?string $text = null,
        public readonly ?string $templateId = null,
        public readonly array $params = [],
        public readonly ?EmailAddress $replyTo = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $tags = [],
        public readonly array $headers = [],
        public readonly array $attachments = [],
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $contactUuid = null,
        public readonly ?string $companyUuid = null,
        public readonly ?string $enquiryUuid = null,
        public readonly ?string $opportunityUuid = null,
        public readonly ?string $taskUuid = null,
        ?string $uuid = null,
    ) {
        $this->uuid = $uuid ?? Uuid::v4();
        $this->createdAt = Clock::nowIso();
    }

    public function primaryRecipient(): EmailAddress
    {
        return $this->to[0];
    }

    public function usesTemplate(): bool
    {
        return $this->templateId !== null && $this->templateId !== '';
    }

    /**
     * Serialise for a queue payload. This is the transient content the worker
     * needs to actually send; it is deleted when the completed job is pruned.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid'            => $this->uuid,
            'messageType'     => $this->messageType,
            'sender'          => $this->sender->toArray(),
            'to'              => array_map(static fn (EmailAddress $a): array => $a->toArray(), $this->to),
            'cc'              => array_map(static fn (EmailAddress $a): array => $a->toArray(), $this->cc),
            'bcc'             => array_map(static fn (EmailAddress $a): array => $a->toArray(), $this->bcc),
            'replyTo'         => $this->replyTo?->toArray(),
            'subject'         => $this->subject,
            'html'            => $this->html,
            'text'            => $this->text,
            'templateId'      => $this->templateId,
            'params'          => $this->params,
            'tags'            => $this->tags,
            'headers'         => $this->headers,
            'attachments'     => $this->attachments,
            'idempotencyKey'  => $this->idempotencyKey,
            'contactUuid'     => $this->contactUuid,
            'companyUuid'     => $this->companyUuid,
            'enquiryUuid'     => $this->enquiryUuid,
            'opportunityUuid' => $this->opportunityUuid,
            'taskUuid'        => $this->taskUuid,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $addr = static fn (mixed $a): EmailAddress => EmailAddress::fromArray(is_array($a) ? $a : []);
        $nstr = static fn (mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;

        /** @var list<EmailAddress> $to */
        $to = array_map($addr, is_array($data['to'] ?? null) ? $data['to'] : []);

        return new self(
            messageType: (string) ($data['messageType'] ?? ''),
            sender: $addr($data['sender'] ?? []),
            to: $to,
            subject: (string) ($data['subject'] ?? ''),
            html: $nstr($data['html'] ?? null),
            text: $nstr($data['text'] ?? null),
            templateId: $nstr($data['templateId'] ?? null),
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
            replyTo: isset($data['replyTo']) && is_array($data['replyTo']) ? EmailAddress::fromArray($data['replyTo']) : null,
            cc: array_map($addr, is_array($data['cc'] ?? null) ? $data['cc'] : []),
            bcc: array_map($addr, is_array($data['bcc'] ?? null) ? $data['bcc'] : []),
            tags: is_array($data['tags'] ?? null) ? $data['tags'] : [],
            headers: is_array($data['headers'] ?? null) ? $data['headers'] : [],
            attachments: is_array($data['attachments'] ?? null) ? $data['attachments'] : [],
            idempotencyKey: $nstr($data['idempotencyKey'] ?? null),
            contactUuid: $nstr($data['contactUuid'] ?? null),
            companyUuid: $nstr($data['companyUuid'] ?? null),
            enquiryUuid: $nstr($data['enquiryUuid'] ?? null),
            opportunityUuid: $nstr($data['opportunityUuid'] ?? null),
            taskUuid: $nstr($data['taskUuid'] ?? null),
            uuid: (string) ($data['uuid'] ?? ''),
        );
    }
}
