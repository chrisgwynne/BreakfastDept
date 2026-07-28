<?php

declare(strict_types=1);

namespace Breakfast\Platform\Operations;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;

/**
 * Operational reporting (Phase 4 — Operations).
 *
 * Read-only rollups over the delivery + commercial data: per-project financial
 * position (contract value = quote + approved variations; invoiced; paid;
 * outstanding), logged-time and still-unbilled exposure, a portfolio summary,
 * and team utilisation. Every figure is derived from source-of-truth tables so
 * it can never drift from what invoicing, time tracking and retainers recorded.
 * Money is integer pence; time is seconds.
 */
final class Reporting
{
    public function __construct(private readonly Platform $platform)
    {
    }

    private function db(): Database
    {
        return $this->platform->db();
    }

    /**
     * Per-project financial + effort position for the active portfolio (newest
     * first). `limit` caps the rows returned.
     *
     * @return list<array<string,mixed>>
     */
    public function projects(int $limit = 100): array
    {
        $rows = $this->platform->projects()->list(['limit' => $limit]);

        return array_map(fn (array $p): array => $this->projectRow($p), $rows);
    }

    /**
     * Portfolio totals across every non-archived project.
     *
     * @return array<string,int>
     */
    public function portfolio(): array
    {
        $totals = [
            'projects' => 0, 'contract_value' => 0, 'quoted_value' => 0, 'variations' => 0,
            'invoiced' => 0, 'paid' => 0, 'outstanding' => 0, 'unbilled_time_value' => 0,
            'billable_seconds' => 0, 'nonbillable_seconds' => 0,
        ];
        foreach ($this->projects(1000) as $row) {
            $totals['projects']++;
            $totals['contract_value'] += (int) $row['contract_value'];
            $totals['quoted_value'] += (int) $row['quoted_value'];
            $totals['variations'] += (int) $row['approved_variations'];
            $totals['invoiced'] += (int) $row['invoiced'];
            $totals['paid'] += (int) $row['paid'];
            $totals['outstanding'] += (int) $row['outstanding'];
            $totals['unbilled_time_value'] += (int) $row['unbilled_time_value'];
            $totals['billable_seconds'] += (int) $row['billable_seconds'];
            $totals['nonbillable_seconds'] += (int) $row['nonbillable_seconds'];
        }

        return $totals;
    }

    /**
     * Team utilisation over a trailing window (default 30 days): logged seconds
     * per author, split billable / non-billable, with a billable percentage.
     *
     * @return list<array<string,mixed>>
     */
    public function utilisation(int $days = 30): array
    {
        $since = date('Y-m-d', strtotime('-' . max(1, $days) . ' day', (int) strtotime(Clock::nowIso())));
        $rows = $this->db()->all(
            "SELECT author,
                    SUM(CASE WHEN billable = 1 THEN duration_seconds ELSE 0 END) AS billable_seconds,
                    SUM(CASE WHEN billable = 0 THEN duration_seconds ELSE 0 END) AS nonbillable_seconds
             FROM time_entries
             WHERE running = 0 AND COALESCE(started_at, created_at) >= :since
             GROUP BY author ORDER BY billable_seconds DESC",
            ['since' => $since]
        );

        return array_map(static function (array $r): array {
            $billable = (int) $r['billable_seconds'];
            $total = $billable + (int) $r['nonbillable_seconds'];

            return [
                'author' => (string) $r['author'],
                'billable_seconds' => $billable,
                'nonbillable_seconds' => (int) $r['nonbillable_seconds'],
                'total_seconds' => $total,
                'billable_percent' => $total > 0 ? (int) round($billable / $total * 100) : 0,
            ];
        }, $rows);
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function projectRow(array $p): array
    {
        $uuid = (string) $p['uuid'];
        $quoted = (int) $p['quoted_value'];
        $variations = (int) $p['approved_variations'];
        $invoiced = 0;
        $paid     = 0;
        foreach ($this->platform->invoices()->forProject($uuid) as $inv) {
            if (!in_array((string) ($inv['status'] ?? ''), ['draft', 'void'], true)) {
                $invoiced += (int) ($inv['total'] ?? 0);
                $paid     += (int) ($inv['amount_paid'] ?? 0);
            }
        }
        $time = $this->platform->time()->rollup($uuid);

        return [
            'uuid' => $uuid, 'name' => (string) $p['name'], 'status' => (string) $p['status'],
            'quoted_value' => $quoted, 'approved_variations' => $variations, 'contract_value' => $quoted + $variations,
            'invoiced' => $invoiced, 'paid' => $paid, 'outstanding' => max(0, $invoiced - $paid),
            'billable_seconds' => (int) $time['billable_seconds'], 'nonbillable_seconds' => (int) $time['nonbillable_seconds'],
            'billable_value' => (int) $time['billable_value'], 'unbilled_time_value' => (int) $time['unbilled_value'],
        ];
    }
}
