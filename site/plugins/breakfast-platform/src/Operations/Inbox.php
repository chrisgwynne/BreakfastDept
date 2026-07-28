<?php

declare(strict_types=1);

namespace Breakfast\Platform\Operations;

use Breakfast\Platform\Support\Clock;
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

    /**
     * Counts per category plus the grand total — cheap enough for a nav badge.
     *
     * @return array{total:int,messages:int,feedback:int,onboarding:int,change_requests:int,retainers:int}
     */
    public function summary(): array
    {
        $messages = count($this->unreadClientMessages());
        $feedback = count($this->openFeedback());
        $onboarding = count($this->submittedOnboarding());
        $changeRequests = count($this->unappliedChanges());
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
        // Everything the inbox derives from is flat-file; the project name is
        // resolved from the file store per row (no cross-file JOIN).
        $messages = $this->unreadClientMessages();
        usort($messages, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $messages = array_slice($messages, 0, 50);
        $feedback = $this->openFeedback();
        usort($feedback, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $feedback = array_slice($feedback, 0, 50);
        $onboarding = $this->submittedOnboarding();
        usort($onboarding, static fn ($a, $b) => strcmp((string) ($b['submitted_at'] ?? ''), (string) ($a['submitted_at'] ?? '')));
        $onboarding = array_slice($onboarding, 0, 50);
        $changeRequests = $this->unappliedChanges();
        usort($changeRequests, static fn ($a, $b) => strcmp((string) ($b['decided_at'] ?? ''), (string) ($a['decided_at'] ?? '')));
        $changeRequests = array_slice($changeRequests, 0, 50);

        return [
            'messages' => array_map(fn (array $m): array => [
                'project_uuid' => (string) $m['project_uuid'], 'project_name' => $this->projectName((string) $m['project_uuid']),
                'label' => (string) $m['author_name'] . ': ' . mb_substr((string) $m['body'], 0, 90), 'at' => (string) $m['created_at'],
            ], $messages),
            'feedback' => array_map(fn (array $f): array => [
                'project_uuid' => (string) $f['project_uuid'], 'project_name' => $this->projectName((string) $f['project_uuid']),
                'label' => (string) ($f['kind'] === 'approval' ? $f['author_name'] . ' signed off' : $f['author_name'] . ': ' . mb_substr((string) $f['body'], 0, 90)),
                'kind' => (string) $f['kind'], 'at' => (string) $f['created_at'],
            ], $feedback),
            'onboarding' => array_map(fn (array $o): array => [
                'project_uuid' => (string) $o['project_uuid'], 'project_name' => $this->projectName((string) $o['project_uuid']),
                'label' => 'Onboarding ' . str_replace('_', ' ', (string) $o['status']), 'at' => (string) ($o['submitted_at'] ?? ''),
            ], $onboarding),
            'change_requests' => array_map(fn (array $c): array => [
                'project_uuid' => (string) $c['project_uuid'], 'project_name' => $this->projectName((string) $c['project_uuid']),
                'label' => (string) $c['number'] . ' “' . (string) $c['title'] . '” approved — ready to apply', 'total' => (int) $c['total'], 'at' => (string) ($c['decided_at'] ?? ''),
            ], $changeRequests),
            'retainers' => array_map(fn (array $r): array => [
                'project_uuid' => (string) $r['project_uuid'], 'project_name' => (string) ($this->platform->projects()->find((string) $r['project_uuid'])['name'] ?? ''),
                'label' => (string) $r['title'] . ' — period due to bill', 'at' => (string) $r['next_period_start'],
            ], $this->dueRetainers()),
        ];
    }

    /** Resolve a flat-file project's name (empty string if it's gone). */
    private function projectName(string $projectUuid): string
    {
        return (string) ($this->platform->projects()->raw($projectUuid)['name'] ?? '');
    }

    /**
     * Approved change requests that have not yet been applied (flat-file).
     *
     * @return list<array<string,mixed>>
     */
    private function unappliedChanges(): array
    {
        return array_values(array_filter(
            $this->platform->fileStore()->all('change_requests'),
            static fn (array $r): bool => (string) ($r['status'] ?? '') === 'approved' && (int) ($r['applied'] ?? 0) === 0
        ));
    }

    /**
     * Unread client messages across all projects (flat-file).
     *
     * @return list<array<string,mixed>>
     */
    private function unreadClientMessages(): array
    {
        return array_values(array_filter(
            $this->platform->fileStore()->all('portal_messages'),
            static fn (array $r): bool => (string) ($r['sender'] ?? '') === 'client' && (int) ($r['read_by_staff'] ?? 0) === 0
        ));
    }

    /**
     * Open portal feedback across all projects (flat-file).
     *
     * @return list<array<string,mixed>>
     */
    private function openFeedback(): array
    {
        return array_values(array_filter(
            $this->platform->fileStore()->all('portal_feedback'),
            static fn (array $r): bool => (string) ($r['status'] ?? '') === 'open'
        ));
    }

    /**
     * Onboarding instances awaiting staff review (flat-file).
     *
     * @return list<array<string,mixed>>
     */
    private function submittedOnboarding(): array
    {
        return array_values(array_filter(
            $this->platform->fileStore()->all('onboarding_instances'),
            static fn (array $r): bool => in_array((string) ($r['status'] ?? ''), ['submitted', 'under_review'], true)
        ));
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
        $active = array_filter($this->platform->fileStore()->all('retainers'), static fn (array $r): bool => (string) ($r['status'] ?? '') === 'active');
        foreach ($active as $r) {
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
