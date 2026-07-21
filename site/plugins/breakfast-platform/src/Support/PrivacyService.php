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

        return $p->db()->transaction(function (Database $db) use ($p, $uuid): bool {
            $p->contacts()->anonymise($uuid);
            // Scrub the recipient address on delivery history (keep the hash).
            $db->run(
                "UPDATE outbound_messages SET recipient_email = 'anonymised', subject = NULL, params_snapshot = NULL WHERE contact_uuid = :u",
                ['u' => $uuid]
            );
            $p->activities()->record('contact', $uuid, 'anonymisation', 'Contact anonymised (PII scrubbed; audit + consent history retained)', 'system');

            return true;
        });
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

        $counts = [
            'email_events'      => $this->countOlder('email_events', 'received_at', $eventDays),
            'completed_jobs'    => (int) $p->db()->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'done' AND completed_at < :c", ['c' => $this->cutoff($jobDays)]),
            'webhook_deliveries' => (int) $p->db()->scalar("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'delivered' AND created_at < :c", ['c' => $this->cutoff($eventDays)]),
            'ip_hashes'         => (int) $p->db()->scalar('SELECT COUNT(*) FROM enquiries WHERE ip_hash IS NOT NULL AND created_at < :c', ['c' => $this->cutoff(30)]),
        ];

        if ($apply) {
            $p->outbound()->pruneEvents($eventDays);
            $p->db()->run("DELETE FROM jobs WHERE status = 'done' AND completed_at < :c", ['c' => $this->cutoff($jobDays)]);
            $p->db()->run("DELETE FROM webhook_deliveries WHERE status = 'delivered' AND created_at < :c", ['c' => $this->cutoff($eventDays)]);
            $p->enquiries()->pruneIpHashes(30);
            $p->rateLimiter()->prune();
            (new SubmissionGuard($p->db(), $p->rateLimiter()))->pruneFingerprints();
            $p->db()->run('DELETE FROM hermes_nonces WHERE expires_at < :now', ['now' => $now]);
        }

        return $counts;
    }

    private function cutoff(int $days): string
    {
        return Clock::now()->modify('-' . $days . ' days')->format('c');
    }

    private function countOlder(string $table, string $column, int $days): int
    {
        return (int) $this->platform->db()->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE {$column} < :c",
            ['c' => $this->cutoff($days)]
        );
    }
}
