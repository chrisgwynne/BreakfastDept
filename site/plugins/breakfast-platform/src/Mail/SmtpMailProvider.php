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
    public function __construct(
        private readonly array $config = [],
        private readonly AttachmentResolver $attachments = new NullAttachmentResolver(),
    ) {
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

        // Resolve attachment references to real files on disk (Kirby's email
        // component attaches by path). Written to temp files only at send time.
        [$paths, $temps] = $this->materialiseAttachments($message);

        try {
            Kirby::instance()->email([
                'transport'   => $this->transport(),
                'from'        => $message->sender->email,
                'fromName'    => $message->sender->name ?? '',
                'replyTo'     => $message->replyTo?->email,
                'to'          => array_map(static fn (EmailAddress $a): string => $a->email, $message->to),
                'cc'          => array_map(static fn (EmailAddress $a): string => $a->email, $message->cc),
                'bcc'         => array_map(static fn (EmailAddress $a): string => $a->email, $message->bcc),
                'subject'     => $message->subject,
                'attachments' => $paths,
                'body'        => [
                    'html' => $message->html ?? nl2br(htmlspecialchars($message->text ?? '')),
                    'text' => $message->text ?? strip_tags($message->html ?? ''),
                ],
            ]);
        } catch (Throwable $e) {
            // SMTP errors are generally transient; let the queue retry.
            return MailResult::temporary('smtp_error');
        } finally {
            foreach ($temps as $tmp) {
                @unlink($tmp);
            }
        }

        // SMTP has no message id; use the application UUID for correlation.
        return MailResult::accepted($message->uuid, 250);
    }

    public function healthCheck(): array
    {
        $ok = ($this->config['host'] ?? '') !== '';

        return ['ok' => $ok, 'detail' => $ok ? 'smtp configured' : 'smtp host missing'];
    }

    /**
     * Write each resolved attachment to a temp file for Kirby's email component.
     *
     * @return array{0:list<string>,1:list<string>} [paths to attach, temp files to delete]
     */
    private function materialiseAttachments(MailMessage $message): array
    {
        $paths = [];
        $temps = [];
        foreach ($message->attachments as $ref) {
            $file = $this->attachments->resolve($ref);
            if ($file === null) {
                continue;
            }
            $dir  = rtrim(sys_get_temp_dir(), '/') . '/bf-mail-att';
            @mkdir($dir, 0700, true);
            $safe = preg_replace('/[^A-Za-z0-9.\-]/', '_', $file->filename) ?: 'attachment.pdf';
            $path = $dir . '/' . bin2hex(random_bytes(8)) . '-' . $safe;
            if (file_put_contents($path, $file->bytes) !== false) {
                @chmod($path, 0600);
                $paths[] = $path;
                $temps[] = $path;
            }
        }

        return [$paths, $temps];
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
