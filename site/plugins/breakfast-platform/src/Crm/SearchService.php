<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Database;

/**
 * Global search across every CRM entity — contacts, companies, leads,
 * opportunities, tasks, invoices, previews and calendar events. Returns typed,
 * link-carrying results so the command palette can jump straight to a record or
 * offer a quick action. Read-only; each row includes the client route.
 */
final class SearchService
{
    public function __construct(private readonly Database $db)
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
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $out  = [];

        foreach ($this->db->all(
            "SELECT uuid, display_name, first_name, last_name, email FROM contacts
             WHERE status != 'archived' AND (display_name LIKE :q ESCAPE '\\' OR first_name LIKE :q ESCAPE '\\' OR last_name LIKE :q ESCAPE '\\' OR email LIKE :q ESCAPE '\\')
             ORDER BY updated_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $name = trim((string) ($r['display_name'] ?: trim((string) $r['first_name'] . ' ' . (string) $r['last_name'])));
            $out[] = $this->row('contact', (string) $r['uuid'], $name ?: (string) $r['email'], (string) $r['email'], '/crm/contacts/' . $r['uuid']);
        }

        foreach ($this->db->all(
            "SELECT uuid, name, website FROM companies WHERE name LIKE :q ESCAPE '\\' OR website LIKE :q ESCAPE '\\' ORDER BY updated_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('company', (string) $r['uuid'], (string) $r['name'], (string) ($r['website'] ?? ''), '/crm');
        }

        foreach ($this->db->all(
            "SELECT uuid, reference, summary, status FROM enquiries WHERE reference LIKE :q ESCAPE '\\' OR summary LIKE :q ESCAPE '\\' ORDER BY created_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('lead', (string) $r['uuid'], (string) ($r['reference'] ?: 'Lead'), (string) ($r['summary'] ?? ''), '/leads');
        }

        foreach ($this->db->all(
            "SELECT uuid, title, stage FROM opportunities WHERE title LIKE :q ESCAPE '\\' ORDER BY created_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('opportunity', (string) $r['uuid'], (string) $r['title'], 'Deal · ' . (string) $r['stage'], '/pipeline');
        }

        foreach ($this->db->all(
            "SELECT uuid, title, status FROM tasks WHERE title LIKE :q ESCAPE '\\' ORDER BY created_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('task', (string) $r['uuid'], (string) $r['title'], 'Task · ' . (string) $r['status'], '/tasks');
        }

        foreach ($this->db->all(
            "SELECT uuid, number, bill_to_name, status FROM invoices WHERE number LIKE :q ESCAPE '\\' OR bill_to_name LIKE :q ESCAPE '\\' ORDER BY created_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('invoice', (string) $r['uuid'], (string) ($r['number'] ?: 'Draft invoice'), (string) ($r['bill_to_name'] ?? ''), '/invoices');
        }

        foreach ($this->db->all(
            "SELECT uuid, name, public_slug FROM client_previews WHERE name LIKE :q ESCAPE '\\' OR public_slug LIKE :q ESCAPE '\\' ORDER BY created_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('preview', (string) $r['uuid'], (string) $r['name'], (string) $r['public_slug'], '/previews');
        }

        foreach ($this->db->all(
            "SELECT uuid, title, starts_at FROM calendar_events WHERE title LIKE :q ESCAPE '\\' ORDER BY starts_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $out[] = $this->row('event', (string) $r['uuid'], (string) $r['title'], (string) $r['starts_at'], '/calendar');
        }

        // Portfolio records. Public + internal titles, client, service, industry
        // and summary are searchable; private notes, approval notes, file paths
        // and unpublished testimonial text are NOT indexed here.
        foreach ($this->db->all(
            "SELECT uuid, internal_name, display_title, client_display_name, status, published_at FROM portfolio_records
             WHERE internal_name LIKE :q ESCAPE '\\' OR display_title LIKE :q ESCAPE '\\' OR client_display_name LIKE :q ESCAPE '\\'
                OR summary LIKE :q ESCAPE '\\' OR project_type LIKE :q ESCAPE '\\' OR industry LIKE :q ESCAPE '\\'
             ORDER BY updated_at DESC LIMIT :l",
            ['q' => $like, 'l' => $perType]
        ) as $r) {
            $title = trim((string) ($r['display_title'] ?: $r['internal_name'] ?: 'Portfolio record'));
            $sub   = trim((string) ($r['client_display_name'] . ' · ' . $r['status']), ' ·');
            $out[] = $this->row('portfolio', (string) $r['uuid'], $title, $sub, '/portfolio/' . $r['uuid']);
        }

        return $out;
    }

    /**
     * @return array{type:string,id:string,title:string,subtitle:string,route:string}
     */
    private function row(string $type, string $id, string $title, string $subtitle, string $route): array
    {
        return ['type' => $type, 'id' => $id, 'title' => $title, 'subtitle' => $subtitle, 'route' => $route];
    }
}
