<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * The mail provider boundary. Every transactional send goes through an
 * implementation of this interface (Brevo / SMTP / Fake). Application code,
 * templates, controllers, Panel and Hermes never talk to a provider directly.
 */
interface MailProvider
{
    public function name(): string;

    /**
     * Attempt to send the message. Implementations MUST NOT throw for expected
     * failure conditions — they return a MailResult so the queue can decide
     * whether to retry. They MAY throw only for programmer errors.
     */
    public function send(MailMessage $message): MailResult;

    /**
     * Lightweight connectivity/authentication check that does NOT send an email.
     * Returns [ok, detail] where detail contains no secrets.
     *
     * @return array{ok:bool,detail:string}
     */
    public function healthCheck(): array;
}
