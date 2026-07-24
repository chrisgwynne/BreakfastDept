<?php

declare(strict_types=1);

namespace Breakfast\Platform\Operations;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;

/**
 * The staff operational inbox (Phase 4 — Operations).
 *
 * A LIVE to-do derived from source-of-truth state rather than a separate
 * notifications store that can drift: each item is present exactly while the
 * underlying thing genuinely needs action, and disappears the moment it is
 * handled at source (a message read, feedback resolved, onboarding completed, a
 * change applied, a retainer billed). Read-only — actions happen on the relevant
 * project tab the item links to.
 */
final class Inbox
{
    public function __construct(private readonly Platform $platform)
    {
    }

    private function db(): Database
    {
        return $this->platform->db();
    }

    /**
     * Counts per category plus the grand total — cheap enough for a nav badge.
     *
     * @return array{total:int,messages:int,feedback:int,onboarding:int,change_requests:int,retainers:int}
     */
    public function summary(): array
    {
        $messages = (int) $this->db()->scalar("SELECT COUNT(*) FROM portal_messages WHERE sender = 'client' AND read_by_staff = 0");
        $feedback = (int) $this->db()->scalar("SELECT COUNT(*) FROM portal_feedback WHERE status = 'open'");
        $onboarding = (int) $this->db()->scalar("SELECT COUNT(*) FROM onboarding_instances WHERE status IN ('submitted','under_review')");
        $changeRequests = (int) $this->db()->scalar("SELECT COUNT(*) FROM change_requests WHERE status = 'approved' AND applied = 0");
        $retainers = count($this->dueRetainers());

        return [
            'total' => $messages + $feedback + $onboarding + $changeRequests + $retainers,
            'messages' => $messages, 'feedback' => $feedback, 'onboarding' => $onboarding,
            'change_requests' => $changeRequests, 'retainers' => $retainers,
        ];
    }

    /**
     * The actionable items themselves, grouped by category. Each carries a label,
     * an optional project reference for navigation, and a timestamp.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function items(): array
    {
        $messages = $this->db()->all(
            "SELECT m.uuid, m.project_uuid, m.author_name, m.body, m.created_at, p.name AS project_name
             FROM portal_messages m JOIN projects p ON p.uuid = m.project_uuid
             WHERE m.sender = 'client' AND m.read_by_staff = 0
             ORDER BY m.created_at DESC LIMIT 50"
        );
        $feedback = $this->db()->all(
            "SELECT f.uuid, f.project_uuid, f.author_name, f.kind, f.body, f.created_at, p.name AS project_name
             FROM portal_feedback f JOIN projects p ON p.uuid = f.project_uuid
             WHERE f.status = 'open' ORDER BY f.created_at DESC LIMIT 50"
        );
        $onboarding = $this->db()->all(
            "SELECT o.uuid, o.project_uuid, o.status, o.submitted_at, p.name AS project_name
             FROM onboarding_instances o JOIN projects p ON p.uuid = o.project_uuid
             WHERE o.status IN ('submitted','under_review') ORDER BY o.submitted_at DESC LIMIT 50"
        );
        $changeRequests = $this->db()->all(
            "SELECT c.uuid, c.project_uuid, c.number, c.title, c.total, c.decided_at, p.name AS project_name
             FROM change_requests c JOIN projects p ON p.uuid = c.project_uuid
             WHERE c.status = 'approved' AND c.applied = 0 ORDER BY c.decided_at DESC LIMIT 50"
        );

        return [
            'messages' => array_map(static fn (array $m): array => [
                'project_uuid' => (string) $m['project_uuid'], 'project_name' => (string) $m['project_name'],
                'label' => (string) $m['author_name'] . ': ' . mb_substr((string) $m['body'], 0, 90), 'at' => (string) $m['created_at'],
            ], $messages),
            'feedback' => array_map(static fn (array $f): array => [
                'project_uuid' => (string) $f['project_uuid'], 'project_name' => (string) $f['project_name'],
                'label' => (string) ($f['kind'] === 'approval' ? $f['author_name'] . ' signed off' : $f['author_name'] . ': ' . mb_substr((string) $f['body'], 0, 90)),
                'kind' => (string) $f['kind'], 'at' => (string) $f['created_at'],
            ], $feedback),
            'onboarding' => array_map(static fn (array $o): array => [
                'project_uuid' => (string) $o['project_uuid'], 'project_name' => (string) $o['project_name'],
                'label' => 'Onboarding ' . str_replace('_', ' ', (string) $o['status']), 'at' => (string) ($o['submitted_at'] ?? ''),
            ], $onboarding),
            'change_requests' => array_map(static fn (array $c): array => [
                'project_uuid' => (string) $c['project_uuid'], 'project_name' => (string) $c['project_name'],
                'label' => (string) $c['number'] . ' “' . (string) $c['title'] . '” approved — ready to apply', 'total' => (int) $c['total'], 'at' => (string) ($c['decided_at'] ?? ''),
            ], $changeRequests),
            'retainers' => array_map(fn (array $r): array => [
                'project_uuid' => (string) $r['project_uuid'], 'project_name' => (string) ($this->platform->projects()->find((string) $r['project_uuid'])['name'] ?? ''),
                'label' => (string) $r['title'] . ' — period due to bill', 'at' => (string) $r['next_period_start'],
            ], $this->dueRetainers()),
        ];
    }

    /**
     * Active retainers whose current period has fully elapsed as of today.
     *
     * @return list<array<string,mixed>>
     */
    private function dueRetainers(): array
    {
        $today = substr(Clock::nowIso(), 0, 10);
        $due = [];
        foreach ($this->db()->all("SELECT uuid, project_uuid, title, cadence, next_period_start FROM retainers WHERE status = 'active'") as $r) {
            $months = (string) $r['cadence'] === 'quarterly' ? 3 : 1;
            $ts = strtotime((string) $r['next_period_start']);
            if ($ts === false) {
                continue;
            }
            $periodEnd = date('Y-m-d', strtotime('+' . $months . ' month', $ts));
            if (strcmp($periodEnd, $today) <= 0) {
                $due[] = $r;
            }
        }

        return $due;
    }
}
