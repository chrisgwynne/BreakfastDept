<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use InvalidArgumentException;

/**
 * A validated, header-safe email address (optionally with a display name).
 *
 * Guarantees no CR/LF (header-injection) reaches a mail header, and that the
 * address itself is syntactically valid before it is used as a recipient,
 * sender or reply-to.
 */
final class EmailAddress
{
    private function __construct(
        public readonly string $email,
        public readonly ?string $name
    ) {
    }

    public static function create(string $email, ?string $name = null): self
    {
        $email = self::stripHeaders(strtolower(trim($email)));

        if (self::isValid($email) === false) {
            throw new InvalidArgumentException('Invalid email address');
        }

        $name = $name !== null ? mb_substr(self::stripHeaders($name), 0, 120) : null;

        return new self($email, $name === '' ? null : $name);
    }

    /** Returns null instead of throwing when the address is invalid. */
    public static function tryCreate(string $email, ?string $name = null): ?self
    {
        try {
            return self::create($email, $name);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function isValid(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && str_contains($email, "\n") === false
            && str_contains($email, "\r") === false;
    }

    private static function stripHeaders(string $value): string
    {
        return trim(str_ireplace(["\r", "\n", '%0a', '%0d'], '', $value));
    }

    /** @return array{email:string,name:?string} */
    public function toArray(): array
    {
        return ['email' => $this->email, 'name' => $this->name];
    }

    /** @param array{email:string,name?:?string} $data */
    public static function fromArray(array $data): self
    {
        return self::create($data['email'], $data['name'] ?? null);
    }
}
