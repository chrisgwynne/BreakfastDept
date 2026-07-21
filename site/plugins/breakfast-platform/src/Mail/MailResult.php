<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Outcome of a provider send. Deliberately NOT reduced to a boolean or a generic
 * exception — the worker needs to distinguish retryable from permanent failures
 * and handle the "we don't know if it was accepted" case idempotently.
 */
enum MailOutcome: string
{
    case Accepted          = 'accepted';
    case TemporaryFailure  = 'temporary_failure';
    case PermanentFailure  = 'permanent_failure';
    case UnknownOutcome    = 'unknown_outcome';
}

final class MailResult
{
    private function __construct(
        public readonly MailOutcome $outcome,
        public readonly ?string $providerMessageId = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function accepted(?string $providerMessageId, ?int $statusCode = 201): self
    {
        return new self(MailOutcome::Accepted, $providerMessageId, $statusCode);
    }

    public static function temporary(string $error, ?int $statusCode = null): self
    {
        return new self(MailOutcome::TemporaryFailure, null, $statusCode, $error);
    }

    public static function permanent(string $error, ?int $statusCode = null): self
    {
        return new self(MailOutcome::PermanentFailure, null, $statusCode, $error);
    }

    public static function unknown(string $error, ?int $statusCode = null): self
    {
        return new self(MailOutcome::UnknownOutcome, null, $statusCode, $error);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === MailOutcome::Accepted;
    }

    public function isRetryable(): bool
    {
        return $this->outcome === MailOutcome::TemporaryFailure
            || $this->outcome === MailOutcome::UnknownOutcome;
    }
}
