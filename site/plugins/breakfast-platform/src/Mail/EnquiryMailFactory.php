<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Builds the two enquiry emails (internal notification + visitor acknowledgement)
 * as provider-agnostic MailMessages.
 *
 * Sender is ALWAYS the verified Breakfast address — never the submitter's. For
 * the internal notification the submitter is set as Reply-To, but only after the
 * address is validated; an invalid submitted address falls back to the studio's
 * enquiries address so a reply never goes nowhere.
 */
final class EnquiryMailFactory
{
    /**
     * @param array{senderEmail:string,senderName:string,enquiriesTo:string} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly TemplateRegistry $templates,
    ) {
    }

    /**
     * @param array<string,mixed> $enquiry
     * @param array<string,mixed> $contact
     */
    public function internal(array $enquiry, array $contact): MailMessage
    {
        $sender = EmailAddress::create($this->config['senderEmail'], $this->config['senderName']);
        $to     = EmailAddress::create($this->config['enquiriesTo'], $this->config['senderName']);

        // Reply-To = the submitter, only if valid; otherwise the studio address.
        $submitter = is_string($contact['email'] ?? null) ? EmailAddress::tryCreate((string) $contact['email'], (string) ($contact['display_name'] ?? '')) : null;
        $replyTo   = $submitter ?? $to;

        $reference = (string) ($enquiry['reference'] ?? '');
        $formType  = (string) ($enquiry['form_type'] ?? 'contact');
        $fields    = is_array($enquiry['payload'] ?? null) ? $enquiry['payload'] : [];

        $params = [
            'enquiry_reference' => $reference,
            'enquiry_summary'   => (string) ($enquiry['summary'] ?? ''),
            'form_type'         => $formType,
            'contact_name'      => (string) ($contact['display_name'] ?? ''),
        ];

        $content = MailContent::enquiryInternal($reference, $formType, $this->scalarFields($fields));

        return new MailMessage(
            messageType: 'enquiry_internal',
            sender: $sender,
            to: [$to],
            subject: 'New ' . $formType . ' enquiry — ' . $reference,
            html: $content['html'],
            text: $content['text'],
            templateId: $this->templateIdString('enquiry_internal'),
            params: $params,
            replyTo: $replyTo,
            tags: ['enquiry', 'internal'],
            contactUuid: $contact['uuid'] ?? null,
            enquiryUuid: $enquiry['uuid'] ?? null,
            idempotencyKey: 'enquiry-internal:' . ($enquiry['uuid'] ?? ''),
        );
    }

    /**
     * @param array<string,mixed> $enquiry
     * @param array<string,mixed> $contact
     */
    public function acknowledgement(array $enquiry, array $contact): ?MailMessage
    {
        $email = is_string($contact['email'] ?? null) ? EmailAddress::tryCreate((string) $contact['email']) : null;
        if ($email === null) {
            return null; // no valid recipient — nothing to acknowledge
        }

        $sender  = EmailAddress::create($this->config['senderEmail'], $this->config['senderName']);
        $name    = (string) ($contact['display_name'] ?? '');
        $content = MailContent::enquiryAck($name, $this->config['senderName']);

        return new MailMessage(
            messageType: 'enquiry_ack',
            sender: $sender,
            to: [$email],
            subject: 'We got your message — ' . $this->config['senderName'],
            html: $content['html'],
            text: $content['text'],
            templateId: $this->templateIdString('enquiry_ack'),
            params: ['contact_name' => $name],
            replyTo: $sender,
            tags: ['enquiry', 'acknowledgement'],
            contactUuid: $contact['uuid'] ?? null,
            enquiryUuid: $enquiry['uuid'] ?? null,
            idempotencyKey: 'enquiry-ack:' . ($enquiry['uuid'] ?? ''),
        );
    }

    private function templateIdString(string $key): ?string
    {
        $id = $this->templates->brevoTemplateId($key);

        return $id !== null ? (string) $id : null;
    }

    /**
     * @param array<mixed> $fields
     * @return array<string,scalar>
     */
    private function scalarFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = $v;
            } elseif (is_array($v)) {
                $out[(string) $k] = implode(', ', array_map(static fn ($x): string => is_scalar($x) ? (string) $x : '', $v));
            }
        }

        return $out;
    }
}
