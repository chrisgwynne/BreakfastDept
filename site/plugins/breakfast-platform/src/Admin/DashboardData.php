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
     * Client-previews summary (counts by status). Returns zeros when no previews
     * exist yet (empty flat-file store).
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

        // Previews are flat files: tally active (non-archived) records by status.
        foreach ($this->platform->fileStore()->all('client_previews') as $row) {
            if (($row['archived_at'] ?? null) !== null) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if (in_array($status, $statuses, true) === false) {
                continue;
            }
            $summary[$status]++;
            $summary['total']++;
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

        // The provider NAME comes from config — never instantiate the provider just
        // to read it. In production a selected-but-unconfigured provider (e.g. Brevo
        // without an API key) throws on construction; a health readout must degrade
        // to "not ready", not take the whole dashboard down with a 500.
        $mailProvider = (string) ($p->mailConfig()['provider'] ?? 'smtp');
        $mailReady    = true;
        try {
            $p->mailProvider();
        } catch (\Throwable) {
            $mailReady = false;
        }

        return [
            'queue_depth'     => $p->queue()->pendingCount(),
            'failed_jobs'     => $p->queue()->failedCount(),
            'mail_provider'   => $mailProvider,
            'mail_ready'      => $mailReady,
            'production'      => $p->isProduction(),
            'version'         => (string) $p->config('version', 'dev'),
        ];
    }

    private function previewCount(string $status): int
    {
        return $this->platform->previews()->count($status);
    }
}
