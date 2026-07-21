<?php

declare(strict_types=1);

namespace Breakfast\Platform\Admin;

use Breakfast\Platform\Support\Platform;

/**
 * Aggregates the read-only data shown on the Breakfast Admin dashboard.
 *
 * Deliberately database-backed and Kirby-free so it can be unit tested against a
 * temp database. It exposes ONLY safe, non-secret summaries: counts, statuses and
 * recent activity — never API keys, credentials, raw IPs, environment values or
 * database paths. Anything sensitive stays out of the payload entirely.
 */
final class DashboardData
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * Business-overview metrics. Each entry carries a `view` hint the front-end
     * turns into a link to the matching filtered screen.
     *
     * @return array<string,int|string>
     */
    public function metrics(): array
    {
        $p = $this->platform;

        return [
            'new_leads'          => $p->enquiries()->count('new'),
            'total_enquiries'    => $p->enquiries()->count(),
            'open_opportunities' => $p->opportunities()->countOpen(),
            'pipeline_value'     => $p->opportunities()->openPipelineValue(),
            'overdue_tasks'      => $p->tasks()->countOverdue(),
            'upcoming_tasks'     => $p->tasks()->countUpcoming(),
            'contacts'           => $p->contacts()->count(),
            'active_previews'    => $this->previewCount('active'),
        ];
    }

    /**
     * Newest enquiries awaiting triage (the "lead inbox").
     *
     * @return array<int,mixed>
     */
    public function leadInbox(int $limit = 8): array
    {
        return $this->platform->enquiries()->search([
            'status' => 'new',
            'limit'  => $limit,
        ]);
    }

    /**
     * Open pipeline broken down by stage.
     *
     * @return array<string,mixed>
     */
    public function pipeline(): array
    {
        return [
            'stages'   => $this->platform->crm()->stages(),
            'by_stage' => $this->platform->opportunities()->pipelineByStage(),
        ];
    }

    /**
     * Overdue and upcoming tasks for the "what needs doing" panel.
     *
     * @return array<string,mixed>
     */
    public function tasks(int $limit = 6): array
    {
        return [
            'overdue'  => $this->platform->tasks()->search(['overdue' => true, 'limit' => $limit]),
            'upcoming' => $this->platform->tasks()->search(['status' => 'open', 'limit' => $limit]),
        ];
    }

    /**
     * Client-previews summary (counts by status). Gracefully returns zeros when
     * the previews tables do not exist yet (migration 0004 not applied).
     *
     * @return array<string,int>
     */
    public function previewsSummary(): array
    {
        $statuses = ['draft', 'active', 'disabled', 'expired', 'archived'];
        $summary  = ['total' => 0];
        foreach ($statuses as $status) {
            $summary[$status] = 0;
        }

        if ($this->platform->db()->tableExists('client_previews') === false) {
            return $summary;
        }

        // One grouped query instead of one COUNT per status: this runs on every
        // dashboard (post-login) load, so collapsing the round-trips matters.
        $rows = $this->platform->db()->all(
            'SELECT status, COUNT(*) AS n FROM client_previews WHERE archived_at IS NULL GROUP BY status'
        );
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (in_array($status, $statuses, true) === false) {
                continue;
            }
            $count = (int) ($row['n'] ?? 0);
            $summary[$status] = $count;
            $summary['total'] += $count;
        }

        return $summary;
    }

    /**
     * Restricted operational health — admin dashboards only. No secrets: just
     * queue depth, failed-job count, the active mail provider name and the build
     * version. Never includes API keys, hosts or credentials.
     *
     * @return array<string,int|string|bool>
     */
    public function systemHealth(): array
    {
        $p = $this->platform;

        return [
            'queue_depth'     => $p->queue()->pendingCount(),
            'failed_jobs'     => $p->queue()->failedCount(),
            'mail_provider'   => $p->mailProvider()->name(),
            'production'      => $p->isProduction(),
            'version'         => (string) $p->config('version', 'dev'),
        ];
    }

    private function previewCount(string $status): int
    {
        if ($this->platform->db()->tableExists('client_previews') === false) {
            return 0;
        }

        return (int) $this->platform->db()->scalar(
            'SELECT COUNT(*) FROM client_previews WHERE status = :s AND archived_at IS NULL',
            ['s' => $status]
        );
    }
}
