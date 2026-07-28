<?php

declare(strict_types=1);

namespace Breakfast\Platform\Retainers;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Retainers & recurring billing (Phase 4 — Operations).
 *
 * A retainer is a recurring agreement on a project: a fixed periodic fee plus an
 * included allowance of hours. Each period is billed in arrears — running the
 * scheduler generates a DRAFT invoice for the fee plus any time overage (billable
 * time logged in the window beyond the allowance, at the overage rate) and locks
 * the covered time entries (billed) so they can never be billed twice. Generation
 * is idempotent per (retainer, period start): re-running never double-bills.
 * Money is integer pence; allowances are seconds.
 */
final class Retainers
{
    /** @var list<string> */
    public const CADENCES = ['monthly', 'quarterly'];

    public function __construct(private readonly Platform $platform)
    {
    }

    private function db(): Database
    {
        return $this->platform->db();
    }

    // ==================================================================
    // Read
    // ==================================================================

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['project_uuid'])) {
            $where[] = 'project_uuid = :p';
            $params['p'] = (string) $filters['project_uuid'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :s';
            $params['s'] = (string) $filters['status'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 200);

        return $this->db()->all('SELECT * FROM retainers WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :l', $params);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $r = $this->db()->one('SELECT * FROM retainers WHERE uuid = :u', ['u' => $uuid]);
        if ($r === null) {
            return null;
        }
        $r['periods'] = $this->db()->all('SELECT * FROM retainer_periods WHERE retainer_uuid = :u ORDER BY period_start DESC LIMIT 60', ['u' => $uuid]);
        $r['events'] = $this->db()->all('SELECT type, detail, actor, created_at FROM retainer_events WHERE retainer_uuid = :u ORDER BY created_at DESC LIMIT 50', ['u' => $uuid]);

        return $r;
    }

    // ==================================================================
    // Create / edit / lifecycle
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(string $projectUuid, array $data, string $actor): array
    {
        $project = $this->db()->one('SELECT company_uuid, contact_uuid, currency FROM projects WHERE uuid = :u', ['u' => $projectUuid]);
        if ($project === null) {
            throw new RetainerException(404, 'Project not found.');
        }
        $cadence = (string) ($data['cadence'] ?? 'monthly');
        if (!in_array($cadence, self::CADENCES, true)) {
            $cadence = 'monthly';
        }
        $start = $this->normaliseDate((string) ($data['start_date'] ?? '')) ?? substr(Clock::nowIso(), 0, 10);
        $fee = (int) round(((float) ($data['fee'] ?? 0)) * 100);
        if ($fee <= 0 && (float) ($data['included_hours'] ?? 0) <= 0) {
            throw new RetainerException(422, 'A retainer needs a fee or an included-hours allowance.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->db()->run(
            'INSERT INTO retainers (uuid, project_uuid, company_uuid, contact_uuid, title, cadence, fee_pence, included_seconds, overage_rate_pence, currency, status, start_date, next_period_start, created_by, created_at, updated_at)
             VALUES (:uuid, :p, :company, :contact, :title, :cadence, :fee, :inc, :over, :currency, \'active\', :start, :start, :actor, :now, :now)',
            [
                'uuid' => $uuid, 'p' => $projectUuid, 'company' => $this->nullable($project['company_uuid'] ?? null), 'contact' => $this->nullable($project['contact_uuid'] ?? null),
                'title' => (string) ($data['title'] ?? 'Retainer'), 'cadence' => $cadence, 'fee' => $fee,
                'inc' => (int) round(((float) ($data['included_hours'] ?? 0)) * 3600), 'over' => (int) round(((float) ($data['overage_rate'] ?? 0)) * 100),
                'currency' => (string) ($project['currency'] ?? 'GBP'), 'start' => $start, 'actor' => $actor, 'now' => $now,
            ]
        );
        $this->event($uuid, 'created', 'Retainer created', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $r = $this->db()->one('SELECT revision FROM retainers WHERE uuid = :u', ['u' => $uuid]);
        if ($r === null) {
            throw new RetainerException(404, 'Retainer not found.');
        }
        if ($expectedRevision !== null && (int) $r['revision'] !== $expectedRevision) {
            throw new RetainerException(409, 'This retainer was changed by someone else. Reload and try again.');
        }
        $sets = ['updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        if (array_key_exists('title', $data)) {
            $sets[] = 'title = :title';
            $params['title'] = (string) $data['title'];
        }
        if (array_key_exists('fee', $data)) {
            $sets[] = 'fee_pence = :fee';
            $params['fee'] = (int) round(((float) $data['fee']) * 100);
        }
        if (array_key_exists('included_hours', $data)) {
            $sets[] = 'included_seconds = :inc';
            $params['inc'] = (int) round(((float) $data['included_hours']) * 3600);
        }
        if (array_key_exists('overage_rate', $data)) {
            $sets[] = 'overage_rate_pence = :over';
            $params['over'] = (int) round(((float) $data['overage_rate']) * 100);
        }
        $this->db()->run('UPDATE retainers SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);
        $this->event($uuid, 'updated', 'Retainer updated', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function setStatus(string $uuid, string $status, string $actor): array
    {
        if (!in_array($status, ['active', 'paused', 'ended'], true)) {
            throw new RetainerException(422, 'Unknown status.');
        }
        $r = $this->db()->one('SELECT status FROM retainers WHERE uuid = :u', ['u' => $uuid]);
        if ($r === null) {
            throw new RetainerException(404, 'Retainer not found.');
        }
        if ((string) $r['status'] === 'ended') {
            throw new RetainerException(409, 'An ended retainer cannot change status.');
        }
        $this->db()->run('UPDATE retainers SET status = :s, updated_at = :now, revision = revision + 1 WHERE uuid = :u', ['s' => $status, 'now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($uuid, 'status', $status, $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Recurring billing run
    // ==================================================================

    /**
     * Generate draft invoices for every active retainer whose next period has
     * fully elapsed on or before $asOf (Y-m-d). Idempotent per period. A retainer
     * that is several periods behind is caught up one period at a time.
     *
     * @return array{invoices:int,periods:int}
     */
    public function runDue(string $asOf, string $actor): array
    {
        $asOf = $this->normaliseDate($asOf) ?? substr(Clock::nowIso(), 0, 10);
        $invoices = 0;
        $periods = 0;
        foreach ($this->db()->all("SELECT uuid FROM retainers WHERE status = 'active'") as $row) {
            [$i, $p] = $this->runRetainer((string) $row['uuid'], $asOf, $actor);
            $invoices += $i;
            $periods += $p;
        }

        return ['invoices' => $invoices, 'periods' => $periods];
    }

    /**
     * Run one retainer up to $asOf. Public so a single retainer can be billed on
     * demand from the console.
     *
     * @return array{0:int,1:int} invoices, periods
     */
    public function runRetainer(string $uuid, string $asOf, string $actor): array
    {
        $invoices = 0;
        $periods = 0;
        // Cap the catch-up so a mis-set start date can't spin forever.
        for ($guard = 0; $guard < 60; $guard++) {
            $r = $this->db()->one("SELECT * FROM retainers WHERE uuid = :u AND status = 'active'", ['u' => $uuid]);
            if ($r === null) {
                break;
            }
            $periodStart = (string) $r['next_period_start'];
            $periodEnd = $this->addCadence($periodStart, (string) $r['cadence']);
            if (strcmp($periodEnd, $asOf) > 0) {
                break; // the current period has not fully elapsed yet
            }
            $existing = $this->db()->one('SELECT uuid FROM retainer_periods WHERE retainer_uuid = :r AND period_start = :s', ['r' => $uuid, 's' => $periodStart]);
            if ($existing === null) {
                $this->billPeriod($r, $periodStart, $periodEnd, $actor);
                $invoices++;
                $periods++;
            }
            $this->db()->run('UPDATE retainers SET next_period_start = :n, updated_at = :now WHERE uuid = :u', ['n' => $periodEnd, 'now' => Clock::nowIso(), 'u' => $uuid]);
        }

        return [$invoices, $periods];
    }

    /**
     * @param array<string,mixed> $r
     */
    private function billPeriod(array $r, string $periodStart, string $periodEnd, string $actor): void
    {
        $uuid = (string) $r['uuid'];
        $projectUuid = (string) $r['project_uuid'];
        $included = (int) $r['included_seconds'];
        $overageRate = (int) $r['overage_rate_pence'];

        // Billable, not-yet-billed time logged in the window.
        $entries = $this->db()->all(
            "SELECT uuid, duration_seconds FROM time_entries
             WHERE project_uuid = :p AND billable = 1 AND billed = 0 AND running = 0
               AND started_at >= :start AND started_at < :end",
            ['p' => $projectUuid, 'start' => $periodStart, 'end' => $periodEnd]
        );
        $used = 0;
        $entryUuids = [];
        foreach ($entries as $e) {
            $used += (int) $e['duration_seconds'];
            $entryUuids[] = (string) $e['uuid'];
        }
        $overageSeconds = max(0, $used - $included);
        $overagePence = (int) round($overageSeconds / 3600 * $overageRate);

        $this->db()->transaction(function () use ($r, $uuid, $projectUuid, $periodStart, $periodEnd, $included, $used, $overagePence, $entryUuids, $actor): void {
            $items = [];
            if ((int) $r['fee_pence'] > 0) {
                $items[] = ['description' => (string) $r['title'] . ' — ' . $periodStart . ' to ' . $periodEnd, 'quantity' => 1, 'unit_price' => (int) $r['fee_pence'] / 100];
            }
            if ($overagePence > 0) {
                $overageHours = round(max(0, $used - $included) / 3600, 2);
                $items[] = ['description' => 'Additional time (' . $overageHours . ' h over allowance)', 'quantity' => 1, 'unit_price' => $overagePence / 100];
            }
            $invoiceUuid = null;
            if ($items !== []) {
                $projectName = (string) ($this->platform->projects()->find($projectUuid)['name'] ?? 'Client');
                $inv = $this->platform->invoices()->create([
                    'bill_to_name' => $projectName, 'contact_uuid' => $this->nullable($r['contact_uuid'] ?? null), 'company_uuid' => $this->nullable($r['company_uuid'] ?? null),
                    'project' => (string) $r['title'] . ' ' . $periodStart, 'items' => $items,
                ], $actor);
                $invoiceUuid = (string) ($inv['uuid'] ?? '');
                $this->platform->invoices()->assignToProject($invoiceUuid, $projectUuid);
                // Lock the covered time so it can never be billed again.
                if ($entryUuids !== []) {
                    $this->platform->time()->markBilled($entryUuids, $invoiceUuid, $actor);
                }
            }
            $this->db()->run(
                'INSERT INTO retainer_periods (uuid, retainer_uuid, period_start, period_end, included_seconds, used_seconds, overage_pence, fee_pence, invoice_uuid, created_at)
                 VALUES (:uuid, :r, :ps, :pe, :inc, :used, :over, :fee, :inv, :now)',
                ['uuid' => Uuid::v4(), 'r' => $uuid, 'ps' => $periodStart, 'pe' => $periodEnd, 'inc' => $included, 'used' => $used, 'over' => $overagePence, 'fee' => (int) $r['fee_pence'], 'inv' => $invoiceUuid, 'now' => Clock::nowIso()]
            );
            $this->event($uuid, 'billed', 'Period ' . $periodStart . ' → ' . $periodEnd . ($invoiceUuid ? ' invoiced' : ' (nothing to bill)'), $actor);
            $this->platform->audit()->event('retainer.billed', 'project', $projectUuid, $actor, ['retainer' => $uuid, 'period_start' => $periodStart, 'invoice' => $invoiceUuid, 'overage' => $overagePence]);
        });
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function addCadence(string $date, string $cadence): string
    {
        $months = $cadence === 'quarterly' ? 3 : 1;
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }

        return date('Y-m-d', strtotime('+' . $months . ' month', $ts));
    }

    private function normaliseDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        $ts = strtotime($date);

        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->db()->run('INSERT INTO retainer_events (uuid, retainer_uuid, type, detail, actor, created_at) VALUES (:uuid, :r, :type, :detail, :actor, :now)', ['uuid' => Uuid::v4(), 'r' => $uuid, 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'now' => Clock::nowIso()]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
