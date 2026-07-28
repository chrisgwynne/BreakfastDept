<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

use Breakfast\Platform\Forms\SubmissionGuard;

/**
 * Data-protection operations: subject access export, anonymisation, and
 * retention cleanup. Anonymisation and deletion are DIFFERENT: anonymisation
 * scrubs personal data while preserving referential integrity, audit and
 * consent history; deletion removes rows. Every destructive action is audited.
 */
final class PrivacyService
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * Structured export of everything held about one contact (SAR).
     *
     * @return array<string,mixed>
     */
    public function exportContact(string $uuid): array
    {
        $p       = $this->platform;
        $contact = $p->contacts()->find($uuid);

        if ($contact === null) {
            return ['error' => 'not_found'];
        }

        $company = !empty($contact['company_uuid']) ? $p->companies()->find((string) $contact['company_uuid']) : null;
        $enquiries = array_values(array_filter(
            $p->enquiries()->search(['limit' => 500]),
            static fn (array $e): bool => ($e['contact_uuid'] ?? null) === $uuid
        ));

        return [
            'exported_at' => Clock::nowIso(),
            'contact'     => $contact,
            'company'     => $company,
            'enquiries'   => $enquiries,
            'activities'  => $p->activities()->forEntity('contact', $uuid),
            'emails'      => array_values(array_filter(
                $p->outbound()->search(['limit' => 500]),
                static fn (array $m): bool => ($m['contact_uuid'] ?? null) === $uuid
            )),
        ];
    }

    /**
     * Irreversibly anonymise a contact's personal data. Delivery history is
     * retained for audit but its recipient address is scrubbed (the keyed hash
     * remains for correlation). Records an immutable activity.
     */
    public function anonymiseContact(string $uuid): bool
    {
        $p = $this->platform;
        if ($p->contacts()->find($uuid) === null) {
            return false;
        }

        $p->contacts()->anonymise($uuid);

        // Scrub the recipient address on flat-file delivery history (keep the hash).
        $store = $p->fileStore();
        foreach ($store->all('outbound_messages') as $message) {
            if (($message['contact_uuid'] ?? null) === $uuid) {
                $store->update('outbound_messages', (string) ($message['uuid'] ?? ''), static function (array $r): array {
                    $r['recipient_email'] = 'anonymised';
                    $r['subject']         = null;
                    $r['params_snapshot'] = null;

                    return $r;
                });
            }
        }

        $p->activities()->record('contact', $uuid, 'anonymisation', 'Contact anonymised (PII scrubbed; audit + consent history retained)', 'system');

        return true;
    }

    /**
     * Retention cleanup. Returns the counts that were (or would be) removed.
     *
     * @return array<string,int>
     */
    public function cleanup(bool $apply, int $eventDays = 90, int $jobDays = 30): array
    {
        $p   = $this->platform;
        $now = Clock::nowIso();

        $jobCutoff     = $this->cutoff($jobDays);
        $deliveryCutoff = $this->cutoff($eventDays);
        $oldJob      = static fn (array $j): bool => (string) ($j['status'] ?? '') === 'done' && (string) ($j['completed_at'] ?? '') !== '' && (string) $j['completed_at'] < $jobCutoff;
        $oldDelivery = static fn (array $d): bool => (string) ($d['status'] ?? '') === 'delivered' && (string) ($d['created_at'] ?? '') !== '' && (string) $d['created_at'] < $deliveryCutoff;

        $counts = [
            'email_events'      => $this->countOlder('email_events', 'received_at', $eventDays),
            'completed_jobs'    => count(array_filter($p->fileStore()->all('jobs'), $oldJob)),
            'webhook_deliveries' => count(array_filter($p->fileStore()->all('webhook_deliveries'), $oldDelivery)),
            'ip_hashes'         => $this->countEnquiryIpHashes(30),
        ];

        if ($apply) {
            $p->outbound()->pruneEvents($eventDays);
            foreach (array_filter($p->fileStore()->all('jobs'), $oldJob) as $j) {
                $p->fileStore()->delete('jobs', (string) ($j['uuid'] ?? ''));
            }
            foreach (array_filter($p->fileStore()->all('webhook_deliveries'), $oldDelivery) as $d) {
                $p->fileStore()->delete('webhook_deliveries', (string) ($d['uuid'] ?? ''));
            }
            $p->enquiries()->pruneIpHashes(30);
            $p->rateLimiter()->prune();
            (new SubmissionGuard($p->fileStore(), $p->rateLimiter()))->pruneFingerprints();
            foreach ($p->fileStore()->all('hermes_nonces') as $n) {
                if ((string) ($n['expires_at'] ?? '') < $now) {
                    $p->fileStore()->delete('hermes_nonces', (string) ($n['uuid'] ?? ''));
                }
            }
        }

        return $counts;
    }

    private function cutoff(int $days): string
    {
        return Clock::now()->modify('-' . $days . ' days')->format('c');
    }

    private function countOlder(string $collection, string $column, int $days): int
    {
        $cutoff = $this->cutoff($days);

        return count(array_filter(
            $this->platform->fileStore()->all($collection),
            static fn (array $r): bool => (string) ($r[$column] ?? '') !== '' && (string) $r[$column] < $cutoff
        ));
    }

    /**
     * Flat-file enquiries still carrying an IP hash older than the cutoff.
     */
    private function countEnquiryIpHashes(int $days): int
    {
        $cutoff = $this->cutoff($days);

        return count(array_filter(
            $this->platform->fileStore()->all('enquiries'),
            static fn (array $e): bool => ($e['ip_hash'] ?? null) !== null && (string) ($e['created_at'] ?? '') < $cutoff
        ));
    }
}
