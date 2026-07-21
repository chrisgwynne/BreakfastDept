<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use Kirby\Cms\App as Kirby;
use Throwable;

/**
 * SMTP / native-mail provider using Kirby's email component. Application-rendered
 * html/text only (Kirby has no concept of provider template ids). Kept so the
 * platform is never coupled to Brevo and can fall back to plain SMTP.
 */
final class SmtpMailProvider implements MailProvider
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(MailMessage $message): MailResult
    {
        if (class_exists(Kirby::class) === false || Kirby::instance(null, true) === null) {
            return MailResult::temporary('kirby_mail_unavailable');
        }

        try {
            Kirby::instance()->email([
                'transport' => $this->transport(),
                'from'      => $message->sender->email,
                'fromName'  => $message->sender->name ?? '',
                'replyTo'   => $message->replyTo?->email,
                'to'        => array_map(static fn (EmailAddress $a): string => $a->email, $message->to),
                'cc'        => array_map(static fn (EmailAddress $a): string => $a->email, $message->cc),
                'bcc'       => array_map(static fn (EmailAddress $a): string => $a->email, $message->bcc),
                'subject'   => $message->subject,
                'body'      => [
                    'html' => $message->html ?? nl2br(htmlspecialchars($message->text ?? '')),
                    'text' => $message->text ?? strip_tags($message->html ?? ''),
                ],
            ]);
        } catch (Throwable $e) {
            // SMTP errors are generally transient; let the queue retry.
            return MailResult::temporary('smtp_error');
        }

        // SMTP has no message id; use the application UUID for correlation.
        return MailResult::accepted($message->uuid, 250);
    }

    public function healthCheck(): array
    {
        $ok = ($this->config['host'] ?? '') !== '';

        return ['ok' => $ok, 'detail' => $ok ? 'smtp configured' : 'smtp host missing'];
    }

    /** @return array<string,mixed> */
    private function transport(): array
    {
        if (($this->config['transport'] ?? 'mail') !== 'smtp') {
            return ['type' => 'mail'];
        }

        return [
            'type'     => 'smtp',
            'host'     => (string) ($this->config['host'] ?? 'localhost'),
            'port'     => (int) ($this->config['port'] ?? 587),
            'security' => (string) ($this->config['security'] ?? 'tls'),
            'auth'     => ($this->config['username'] ?? '') !== '',
            'username' => $this->config['username'] ?? null,
            'password' => $this->config['password'] ?? null,
        ];
    }
}
