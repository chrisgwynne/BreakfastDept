<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;

/**
 * Shared persistence helpers for CRM repositories.
 */
abstract class Repository
{
    public function __construct(protected readonly Database $db)
    {
    }

    protected function now(): string
    {
        return Clock::nowIso();
    }

    /**
     * @param array<int|string,mixed>|null $value
     */
    protected function encodeJson(?array $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $json = json_encode($value);

        return $json === false ? null : $json;
    }

    /**
     * @return array<int|string,mixed>
     */
    protected function decodeJson(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build an UPDATE clause and params from an associative set of columns.
     * Only whitelisted columns are ever written.
     *
     * @param array<string,mixed>  $data
     * @param list<string>         $allowed
     * @return array{0:string,1:array<string,mixed>}
     */
    protected function assignments(array $data, array $allowed): array
    {
        $sets   = [];
        $params = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]         = $col . ' = :' . $col;
                $params[$col]   = $data[$col];
            }
        }

        return [implode(', ', $sets), $params];
    }
}
