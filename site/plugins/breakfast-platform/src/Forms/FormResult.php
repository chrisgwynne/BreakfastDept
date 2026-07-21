<?php

declare(strict_types=1);

namespace Breakfast\Platform\Forms;

/**
 * Outcome of processing a form submission. Consumed by the controller to drive
 * the Post/Redirect/Get response.
 */
final class FormResult
{
    /**
     * @param array<string,string> $errors    field => message (for re-render)
     * @param array<string,string> $old        previously entered values to repopulate
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $submissionId = null,
        public readonly ?string $reference = null,
        public readonly array $errors = [],
        public readonly ?string $generalError = null,
        public readonly array $old = [],
        public readonly bool $rateLimited = false
    ) {
    }

    public static function ok(string $submissionId, string $reference): self
    {
        return new self(true, $submissionId, $reference);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $old
     */
    public static function invalid(array $errors, array $old = []): self
    {
        return new self(false, null, null, $errors, null, $old);
    }

    /** @param array<string,string> $old */
    public static function error(string $message, array $old = [], bool $rateLimited = false): self
    {
        return new self(false, null, null, [], $message, $old, $rateLimited);
    }
}
