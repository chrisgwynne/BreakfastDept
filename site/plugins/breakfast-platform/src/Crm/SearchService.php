<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Platform;

/**
 * Global search across every CRM entity — contacts, companies, leads,
 * opportunities, tasks, invoices, previews and calendar events. Returns typed,
 * link-carrying results so the command palette can jump straight to a record or
 * offer a quick action. Read-only; each row includes the client route.
 *
 * The CRM core (contacts, companies, leads, opportunities, tasks) is stored as
 * flat files and searched in PHP; invoices, previews and calendar events are
 * still database-backed and queried with SQL.
 */
final class SearchService
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * @return list<array{type:string,id:string,title:string,subtitle:string,route:string}>
     */
    public function search(string $query, int $perType = 5): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $needle = mb_strtolower($q);
        $out    = [];

        $store = $this->platform->fileStore();

        // Contacts — skip archived, match name/email.
        foreach ($this->take($this->filter($store->all('contacts'), function (array $r) use ($needle): bool {
            if (($r['status'] ?? null) === 'archived') {
                return false;
            }

            return $this->hit($needle, $r['display_name'] ?? '', $r['first_name'] ?? '', $r['last_name'] ?? '', $r['email'] ?? '');
        }), 'updated_at', $perType) as $r) {
            $name  = trim((string) ($r['display_name'] ?: trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''))));
            $out[] = $this->row('contact', (string) $r['uuid'], $name ?: (string) ($r['email'] ?? ''), (string) ($r['email'] ?? ''), '/crm/contacts/' . $r['uuid']);
        }

        foreach ($this->take($this->filter($store->all('companies'), fn (array $r): bool => $this->hit($needle, $r['name'] ?? '', $r['website'] ?? '')), 'updated_at', $perType) as $r) {
            $out[] = $this->row('company', (string) $r['uuid'], (string) ($r['name'] ?? ''), (string) ($r['website'] ?? ''), '/crm');
        }

        foreach ($this->take($this->filter($store->all('enquiries'), fn (array $r): bool => $this->hit($needle, $r['reference'] ?? '', $r['summary'] ?? '')), 'created_at', $perType) as $r) {
            $out[] = $this->row('lead', (string) $r['uuid'], (string) ($r['reference'] ?: 'Lead'), (string) ($r['summary'] ?? ''), '/leads');
        }

        foreach ($this->take($this->filter($store->all('opportunities'), fn (array $r): bool => $this->hit($needle, $r['title'] ?? '')), 'created_at', $perType) as $r) {
            $out[] = $this->row('opportunity', (string) $r['uuid'], (string) ($r['title'] ?? ''), 'Deal · ' . (string) ($r['stage'] ?? ''), '/pipeline');
        }

        foreach ($this->take($this->filter($store->all('tasks'), fn (array $r): bool => $this->hit($needle, $r['title'] ?? '')), 'created_at', $perType) as $r) {
            $out[] = $this->row('task', (string) $r['uuid'], (string) ($r['title'] ?? ''), 'Task · ' . (string) ($r['status'] ?? ''), '/tasks');
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        foreach ($this->platform->db()->all(
            "SELECT uuid, number, bill_to_name, status FROM invoices WHERE number LIKE :q ESCAPE '\\' OR bill_to_name LIKE :q ESCAPE '\\' ORDER BY created_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('invoice', (string) $r['uuid'], (string) ($r['number'] ?: 'Draft invoice'), (string) ($r['bill_to_name'] ?? ''), '/invoices');
        }

        foreach ($this->platform->db()->all(
            "SELECT uuid, name, public_slug FROM client_previews WHERE name LIKE :q ESCAPE '\\' OR public_slug LIKE :q ESCAPE '\\' ORDER BY created_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('preview', (string) $r['uuid'], (string) $r['name'], (string) $r['public_slug'], '/previews');
        }

        foreach ($this->platform->db()->all(
            "SELECT uuid, title, starts_at FROM calendar_events WHERE title LIKE :q ESCAPE '\\' ORDER BY starts_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('event', (string) $r['uuid'], (string) $r['title'], (string) $r['starts_at'], '/calendar');
        }

        return $out;
    }

    /**
     * Case-insensitive match of the needle against any of the given fields.
     */
    private function hit(string $needle, mixed ...$fields): bool
    {
        foreach ($fields as $field) {
            if (is_string($field) && $field !== '' && mb_stripos($field, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>>          $rows
     * @param callable(array<string,mixed>):bool $predicate
     * @return list<array<string,mixed>>
     */
    private function filter(array $rows, callable $predicate): array
    {
        return array_values(array_filter($rows, $predicate));
    }

    /**
     * Sort by a timestamp field, newest first, and take the first $limit.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function take(array $rows, string $sortField, int $limit): array
    {
        usort($rows, fn (array $a, array $b): int => strcmp((string) ($b[$sortField] ?? ''), (string) ($a[$sortField] ?? '')));

        return array_slice($rows, 0, max(0, $limit));
    }

    /**
     * @return array{type:string,id:string,title:string,subtitle:string,route:string}
     */
    private function row(string $type, string $id, string $title, string $subtitle, string $route): array
    {
        return ['type' => $type, 'id' => $id, 'title' => $title, 'subtitle' => $subtitle, 'route' => $route];
    }
}
