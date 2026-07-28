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
        $store       = $this->platform->fileStore();
        $contactUuid = (string) ($contact['uuid'] ?? '');
        $companyUuid = (string) ($contact['company_uuid'] ?? '');

        $company = $companyUuid !== '' ? $this->platform->companies()->find($companyUuid) : null;

        // Related records — by contact, or by the contact's company. The CRM
        // core (opportunities, tasks) is flat-file, so it's filtered in PHP;
        // invoices, previews and emails are still database-backed.
        $related = fn (array $row): bool => ($row['contact_uuid'] ?? null) === $contactUuid
            || ($companyUuid !== '' && ($row['company_uuid'] ?? null) === $companyUuid);

        $opportunities = array_values(array_filter($store->all('opportunities'), $related));
        usort($opportunities, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $opportunities = array_slice(array_map(static fn (array $o): array => [
            'uuid'            => $o['uuid'] ?? '',
            'title'           => $o['title'] ?? '',
            'stage'           => $o['stage'] ?? '',
            'estimated_value' => (int) ($o['estimated_value'] ?? 0),
            'probability'     => (int) ($o['probability'] ?? 0),
        ], $opportunities), 0, 50);

        $tasks = array_values(array_filter($store->all('tasks'), $related));
        usort($tasks, static fn ($a, $b) => strcmp(
            (string) ($a['due_date'] ?? $a['created_at'] ?? ''),
            (string) ($b['due_date'] ?? $b['created_at'] ?? '')
        ));
        $tasks = array_slice(array_map(static fn (array $t): array => [
            'uuid'     => $t['uuid'] ?? '',
            'title'    => $t['title'] ?? '',
            'status'   => $t['status'] ?? '',
            'due_date' => $t['due_date'] ?? null,
        ], $tasks), 0, 50);

        $invoices = array_values(array_filter($store->all('invoices'), $related));
        usort($invoices, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $invoices = array_slice(array_map(static fn (array $i): array => [
            'uuid'        => $i['uuid'] ?? '',
            'number'      => $i['number'] ?? '',
            'status'      => $i['status'] ?? '',
            'total'       => (int) ($i['total'] ?? 0),
            'amount_paid' => (int) ($i['amount_paid'] ?? 0),
            'issue_date'  => $i['issue_date'] ?? '',
        ], $invoices), 0, 50);
        $previews = array_values(array_filter($store->all('client_previews'), $related));
        usort($previews, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $previews = array_slice(array_map(static fn (array $p): array => [
            'uuid'       => $p['uuid'] ?? '',
            'name'       => $p['name'] ?? '',
            'slug'       => $p['public_slug'] ?? '',
            'status'     => $p['status'] ?? '',
            'view_count' => (int) ($p['view_count'] ?? 0),
        ], $previews), 0, 50);
        $emails = array_values(array_filter($store->all('outbound_messages'), $related));
        usort($emails, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $emails = array_slice(array_map(static fn (array $m): array => [
            'uuid'       => $m['uuid'] ?? '',
            'subject'    => $m['subject'] ?? '',
            'status'     => $m['status'] ?? '',
            'to_email'   => $m['recipient_email'] ?? '',
            'created_at' => $m['created_at'] ?? '',
        ], $emails), 0, 50);
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
