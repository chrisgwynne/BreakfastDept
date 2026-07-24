<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Platform;

/**
 * Aggregates everything about one client (a contact, and its company) into a
 * single coherent workspace: related opportunities, previews, invoices, tasks,
 * calendar events and emails, plus a unified activity timeline that merges the
 * contact's and company's activity streams.
 *
 * This is a read-only projection over the existing tables — it does not
 * duplicate client data, it joins to it by the same foreign keys everything
 * else uses, so the workspace always reflects the real records.
 */
final class ClientWorkspace
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * @param array<string,mixed> $contact a contact row
     * @return array<string,mixed>
     */
    public function forContact(array $contact): array
    {
        $db          = $this->platform->db();
        $contactUuid = (string) ($contact['uuid'] ?? '');
        $companyUuid = (string) ($contact['company_uuid'] ?? '');

        $company = $companyUuid !== '' ? $this->platform->companies()->find($companyUuid) : null;

        // Related records — by contact, or by the contact's company.
        $opportunities = $db->all(
            'SELECT uuid, title, stage, estimated_value, probability FROM opportunities
             WHERE contact_uuid = :c OR (:co != \'\' AND company_uuid = :co) ORDER BY created_at DESC LIMIT 50',
            ['c' => $contactUuid, 'co' => $companyUuid]
        );
        $tasks = $db->all(
            'SELECT uuid, title, status, due_date FROM tasks
             WHERE contact_uuid = :c OR (:co != \'\' AND company_uuid = :co) ORDER BY COALESCE(due_date, created_at) ASC LIMIT 50',
            ['c' => $contactUuid, 'co' => $companyUuid]
        );
        $invoices = $db->all(
            'SELECT uuid, number, status, total, amount_paid, issue_date FROM invoices
             WHERE contact_uuid = :c OR (:co != \'\' AND company_uuid = :co) ORDER BY created_at DESC LIMIT 50',
            ['c' => $contactUuid, 'co' => $companyUuid]
        );
        $previews = $db->all(
            'SELECT uuid, name, public_slug AS slug, status, view_count FROM client_previews
             WHERE contact_uuid = :c OR (:co != \'\' AND company_uuid = :co) ORDER BY created_at DESC LIMIT 50',
            ['c' => $contactUuid, 'co' => $companyUuid]
        );
        $emails = $db->all(
            'SELECT uuid, subject, status, recipient_email AS to_email, created_at FROM outbound_messages
             WHERE contact_uuid = :c OR (:co != \'\' AND company_uuid = :co) ORDER BY created_at DESC LIMIT 50',
            ['c' => $contactUuid, 'co' => $companyUuid]
        );
        $events = $this->platform->calendar()->forEntity('contact_uuid', $contactUuid, 50);
        if ($companyUuid !== '') {
            foreach ($this->platform->calendar()->forEntity('company_uuid', $companyUuid, 50) as $e) {
                $events[] = $e;
            }
        }

        // Unified timeline: merge the contact's and company's activity streams.
        $timeline = $this->platform->activities()->forEntity('contact', $contactUuid, 100);
        if ($companyUuid !== '') {
            foreach ($this->platform->activities()->forEntity('company', $companyUuid, 100) as $a) {
                $timeline[] = $a;
            }
        }
        usort($timeline, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $paid        = array_sum(array_map(static fn ($i) => (int) $i['amount_paid'], $invoices));
        $invoicedRaw = array_filter($invoices, static fn ($i) => !in_array((string) $i['status'], ['draft', 'void'], true));
        $invoiced    = array_sum(array_map(static fn ($i) => (int) $i['total'], $invoicedRaw));

        return [
            'company'       => $company,
            'summary'       => [
                'open_opportunities' => count(array_filter($opportunities, static fn ($o) => !in_array((string) $o['stage'], ['won', 'lost'], true))),
                'open_tasks'         => count(array_filter($tasks, static fn ($t) => (string) $t['status'] === 'open')),
                'active_previews'    => count(array_filter($previews, static fn ($p) => (string) $p['status'] === 'active')),
                'total_invoiced'     => $invoiced,
                'total_paid'         => $paid,
                'outstanding'        => $invoiced - $paid,
                'upcoming_event'     => $this->nextEvent($events),
            ],
            'opportunities' => $opportunities,
            'tasks'         => $tasks,
            'invoices'      => $invoices,
            'previews'      => $previews,
            'emails'        => $emails,
            'events'        => $events,
            'timeline'      => $timeline,
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function nextEvent(array $events): ?string
    {
        $now  = date('c');
        $next = null;
        foreach ($events as $e) {
            $start = (string) ($e['starts_at'] ?? '');
            if ($start >= $now && ($next === null || $start < $next)) {
                $next = $start;
            }
        }

        return $next;
    }
}
