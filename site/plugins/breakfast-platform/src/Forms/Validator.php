<?php

declare(strict_types=1);

namespace Breakfast\Platform\Forms;

/**
 * Server-side field validator.
 *
 * Rules are declarative and defined in PHP (the trustworthy source of truth for
 * validation); the human-readable messages are supplied by the caller from
 * editable Kirby content, so no error text is hard-coded here.
 */
final class Validator
{
    /** @var array<string,string> field => message */
    private array $errors = [];

    /**
     * @param array<string,mixed>                       $data
     * @param array<string,array<string,mixed>>         $rules   field => rule spec
     * @param array<string,string>                      $messages field.rule => message
     */
    public function validate(array $data, array $rules, array $messages = []): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $spec) {
            $value = trim((string) ($data[$field] ?? ''));

            if (!empty($spec['required']) && $value === '') {
                $this->fail($field, 'required', $messages);
                continue;
            }

            // Optional + empty → nothing further to check.
            if ($value === '' && empty($spec['required'])) {
                continue;
            }

            if (isset($spec['max']) && mb_strlen($value) > (int) $spec['max']) {
                $this->fail($field, 'max', $messages);
                continue;
            }

            if (isset($spec['min']) && mb_strlen($value) < (int) $spec['min']) {
                $this->fail($field, 'min', $messages);
                continue;
            }

            $type = $spec['type'] ?? 'text';

            if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $this->fail($field, 'email', $messages);
                continue;
            }

            if ($type === 'url' && $this->looksLikeUrl($value) === false) {
                $this->fail($field, 'url', $messages);
                continue;
            }

            if ($type === 'tel' && preg_match('/^[0-9 +().\-]{6,30}$/', $value) !== 1) {
                $this->fail($field, 'tel', $messages);
                continue;
            }

            if (isset($spec['in']) && is_array($spec['in']) && in_array($value, $spec['in'], true) === false) {
                $this->fail($field, 'in', $messages);
                continue;
            }
        }

        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : reset($this->errors);
    }

    /** @param array<string,string> $messages */
    private function fail(string $field, string $rule, array $messages): void
    {
        $this->errors[$field] = $messages[$field . '.' . $rule]
            ?? $messages[$field]
            ?? $messages['default']
            ?? 'This field is invalid.';
    }

    private function looksLikeUrl(string $value): bool
    {
        // Accept bare domains too (people type "example.co.uk").
        if (!preg_match('~^https?://~i', $value)) {
            $value = 'https://' . $value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && str_contains($value, '.');
    }
}
