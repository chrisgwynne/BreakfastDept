<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Controlled one-to-one / operational CRM email. NOT a bulk marketing tool:
 * one recipient, an approved sender identity, suppression enforced, permission
 * enforced by the caller, and everything queued through the durable outbox.
 */
final class CrmMailService
{
    /**
     * @param array{senderEmail:string,senderName:string} $config
     */
    public function __construct(
        private readonly MailService $mail,
        private readonly SuppressionService $suppressions,
        private readonly TemplateRegistry $templates,
        private readonly array $config,
    ) {
    }

    /**
     * Compose a preview (no send) from operator input. Body is escaped when
     * rendered to HTML; the plain-text alternative is the raw body.
     *
     * @return array{subject:string,html:string,text:string}
     */
    public function preview(string $subject, string $body): array
    {
        $content = MailContent::crmReply($body);

        return ['subject' => mb_substr($subject, 0, 200), 'html' => $content['html'], 'text' => $content['text']];
    }

    /**
     * Queue a one-to-one CRM email. Returns a structured result — never throws
     * for expected conditions (suppression, invalid recipient).
     *
     * @param array{contact_uuid?:?string,enquiry_uuid?:?string,opportunity_uuid?:?string} $links
     * @param list<string> $attachments storage references (never raw bytes), resolved at send time
     * @return array{ok:bool,error?:string,uuid?:string}
     */
    public function send(string $toEmail, string $subject, string $body, array $links, ?string $actorEmail, array $attachments = []): array
    {
        $recipient = EmailAddress::tryCreate($toEmail);
        if ($recipient === null) {
            return ['ok' => false, 'error' => 'invalid_recipient'];
        }

        // Never send to a transactionally-suppressed recipient.
        if ($this->suppressions->canSendTransactional($recipient->email) === false) {
            return ['ok' => false, 'error' => 'recipient_suppressed'];
        }

        $subject = trim($subject);
        if ($subject === '') {
            return ['ok' => false, 'error' => 'subject_required'];
        }

        $content = MailContent::crmReply($body);
        $sender  = EmailAddress::create($this->config['senderEmail'], $this->config['senderName']);

        $message = new MailMessage(
            messageType: 'crm_reply',
            sender: $sender,
            to: [$recipient],
            subject: mb_substr($subject, 0, 200),
            html: $content['html'],
            text: $content['text'],
            templateId: $this->templates->brevoTemplateId('crm_reply') !== null ? (string) $this->templates->brevoTemplateId('crm_reply') : null,
            params: ['message_body' => mb_substr($body, 0, 2000)],
            replyTo: $sender,
            tags: ['crm', 'reply'],
            attachments: array_values(array_filter($attachments, static fn (string $a): bool => $a !== '')),
            contactUuid: $links['contact_uuid'] ?? null,
            enquiryUuid: $links['enquiry_uuid'] ?? null,
            opportunityUuid: $links['opportunity_uuid'] ?? null,
        );

        $uuid = $this->mail->queue($message);

        return ['ok' => true, 'uuid' => $uuid];
    }

    /**
     * Send a test copy to the operator's own verified address.
     *
     * @return array{ok:bool,error?:string,uuid?:string}
     */
    public function sendTest(string $operatorEmail, string $subject, string $body): array
    {
        return $this->send($operatorEmail, '[test] ' . $subject, $body, [], $operatorEmail);
    }
}
