<?php

declare(strict_types=1);

namespace Breakfast\Platform\Retainers;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Retainers & recurring billing (Phase 4 — Operations), stored as flat files.
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

    private function store(): FileStore
    {
        return $this->platform->fileStore();
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
        $rows = $this->store()->all('retainers');
        if (!empty($filters['project_uuid'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === (string) $filters['project_uuid']);
        }
        if (!empty($filters['status'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === (string) $filters['status']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, (int) ($filters['limit'] ?? 200));
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $r = $this->store()->find('retainers', $uuid);
        if ($r === null) {
            return null;
        }
        $periods = array_values(array_filter($this->store()->all('retainer_periods'), static fn (array $p): bool => (string) ($p['retainer_uuid'] ?? '') === $uuid));
        usort($periods, static fn ($a, $b) => strcmp((string) ($b['period_start'] ?? ''), (string) ($a['period_start'] ?? '')));

        $events = array_values(array_filter($this->store()->all('retainer_events'), static fn (array $e): bool => (string) ($e['retainer_uuid'] ?? '') === $uuid));
        usort($events, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $r['periods'] = array_slice($periods, 0, 60);
        $r['events']  = array_slice(array_map(static fn (array $e): array => [
            'type' => $e['type'] ?? '', 'detail' => $e['detail'] ?? '', 'actor' => $e['actor'] ?? '', 'created_at' => $e['created_at'] ?? '',
        ], $events), 0, 50);

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
        $project = $this->platform->projects()->raw($projectUuid);
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
        $this->store()->put('retainers', [
            'uuid'               => $uuid,
            'project_uuid'       => $projectUuid,
            'company_uuid'       => $this->nullable($project['company_uuid'] ?? null),
            'contact_uuid'       => $this->nullable($project['contact_uuid'] ?? null),
            'title'              => (string) ($data['title'] ?? 'Retainer'),
            'cadence'            => $cadence,
            'fee_pence'          => $fee,
            'included_seconds'   => (int) round(((float) ($data['included_hours'] ?? 0)) * 3600),
            'overage_rate_pence' => (int) round(((float) ($data['overage_rate'] ?? 0)) * 100),
            'currency'           => (string) ($project['currency'] ?? 'GBP'),
            'status'             => 'active',
            'start_date'         => $start,
            'next_period_start'  => $start,
            'revision'           => 0,
            'created_by'         => $actor,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $this->event($uuid, 'created', 'Retainer created', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $r = $this->store()->find('retainers', $uuid);
        if ($r === null) {
            throw new RetainerException(404, 'Retainer not found.');
        }
        if ($expectedRevision !== null && (int) ($r['revision'] ?? 0) !== $expectedRevision) {
            throw new RetainerException(409, 'This retainer was changed by someone else. Reload and try again.');
        }
        $this->store()->update('retainers', $uuid, static function (array $row) use ($data): array {
            if (array_key_exists('title', $data)) {
                $row['title'] = (string) $data['title'];
            }
            if (array_key_exists('fee', $data)) {
                $row['fee_pence'] = (int) round(((float) $data['fee']) * 100);
            }
            if (array_key_exists('included_hours', $data)) {
                $row['included_seconds'] = (int) round(((float) $data['included_hours']) * 3600);
            }
            if (array_key_exists('overage_rate', $data)) {
                $row['overage_rate_pence'] = (int) round(((float) $data['overage_rate']) * 100);
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, 'updated', 'Retainer updated', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function setStatus(string $uuid, string $status, string $actor): array
    {
        if (!in_array($status, ['active', 'paused', 'ended'], true)) {
            throw new RetainerException(422, 'Unknown status.');
        }
        $r = $this->store()->find('retainers', $uuid);
        if ($r === null) {
            throw new RetainerException(404, 'Retainer not found.');
        }
        if ((string) $r['status'] === 'ended') {
            throw new RetainerException(409, 'An ended retainer cannot change status.');
        }
        $this->store()->update('retainers', $uuid, static function (array $row) use ($status): array {
            $row['status']     = $status;
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, 'status', $status, $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Recurring billing run
    // ==================================================================

    /**
     * Generate draft invoices for every active retainer whose next period has
     * fully elapsed on or before $asOf (Y-m-d). Idempotent per period.
     *
     * @return array{invoices:int,periods:int}
     */
    public function runDue(string $asOf, string $actor): array
    {
        $asOf = $this->normaliseDate($asOf) ?? substr(Clock::nowIso(), 0, 10);
        $invoices = 0;
        $periods = 0;
        $active = array_filter($this->store()->all('retainers'), static fn (array $r): bool => (string) ($r['status'] ?? '') === 'active');
        foreach ($active as $row) {
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
            $r = $this->store()->find('retainers', $uuid);
            if ($r === null || (string) ($r['status'] ?? '') !== 'active') {
                break;
            }
            $periodStart = (string) $r['next_period_start'];
            $periodEnd = $this->addCadence($periodStart, (string) $r['cadence']);
            if (strcmp($periodEnd, $asOf) > 0) {
                break; // the current period has not fully elapsed yet
            }
            $existing = array_filter($this->store()->all('retainer_periods'), static fn (array $p): bool => (string) ($p['retainer_uuid'] ?? '') === $uuid && (string) ($p['period_start'] ?? '') === $periodStart);
            if ($existing === []) {
                $this->billPeriod($r, $periodStart, $periodEnd, $actor);
                $invoices++;
                $periods++;
            }
            $this->store()->update('retainers', $uuid, static function (array $row) use ($periodEnd): array {
                $row['next_period_start'] = $periodEnd;
                $row['updated_at']        = Clock::nowIso();

                return $row;
            });
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

        // Billable, not-yet-billed time logged in the window (flat-file).
        $entries = array_values(array_filter($this->store()->all('time_entries'), static fn (array $e): bool => (string) ($e['project_uuid'] ?? '') === $projectUuid
            && (int) ($e['billable'] ?? 0) === 1
            && (int) ($e['billed'] ?? 0) === 0
            && (int) ($e['running'] ?? 0) === 0
            && (string) ($e['started_at'] ?? '') >= $periodStart
            && (string) ($e['started_at'] ?? '') < $periodEnd));
        $used = 0;
        $entryUuids = [];
        foreach ($entries as $e) {
            $used += (int) $e['duration_seconds'];
            $entryUuids[] = (string) $e['uuid'];
        }
        $overageSeconds = max(0, $used - $included);
        $overagePence = (int) round($overageSeconds / 3600 * $overageRate);

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
            $projectName = (string) ($this->platform->projects()->raw($projectUuid)['name'] ?? 'Client');
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
        $this->store()->put('retainer_periods', [
            'uuid'             => Uuid::v4(),
            'retainer_uuid'    => $uuid,
            'period_start'     => $periodStart,
            'period_end'       => $periodEnd,
            'included_seconds' => $included,
            'used_seconds'     => $used,
            'overage_pence'    => $overagePence,
            'fee_pence'        => (int) $r['fee_pence'],
            'invoice_uuid'     => $invoiceUuid,
            'created_at'       => Clock::nowIso(),
        ]);
        $this->event($uuid, 'billed', 'Period ' . $periodStart . ' → ' . $periodEnd . ($invoiceUuid ? ' invoiced' : ' (nothing to bill)'), $actor);
        $this->platform->audit()->event('retainer.billed', 'project', $projectUuid, $actor, ['retainer' => $uuid, 'period_start' => $periodStart, 'invoice' => $invoiceUuid, 'overage' => $overagePence]);
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
        $this->store()->put('retainer_events', [
            'uuid'          => Uuid::v4(),
            'retainer_uuid' => $uuid,
            'type'          => $type,
            'detail'        => $detail,
            'actor'         => $actor,
            'created_at'    => Clock::nowIso(),
        ]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
