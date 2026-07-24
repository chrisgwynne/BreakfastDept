<?php

declare(strict_types=1);

namespace Breakfast\Platform\Calendar;

use Breakfast\Platform\Crm\ActivityRepository;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;
use DateInterval;
use DateTimeImmutable;

/**
 * A genuinely functional calendar for the studio: real events with CRM
 * relationships, recurrence and reminders.
 *
 * Recurrence is stored as a rule on a master row and expanded on read within
 * the queried range (never materialised as thousands of rows). A cancelled
 * occurrence of a series is recorded in the master's `exceptions` list; editing
 * a single occurrence detaches it into a standalone event. Every event that is
 * linked to a client also writes an entry on that client's activity timeline,
 * so scheduled work is part of the same lifecycle as everything else.
 */
final class CalendarService
{
    /** @var list<string> */
    public const TYPES = ['call', 'meeting', 'review', 'deadline', 'follow_up', 'reminder', 'task', 'milestone', 'other'];

    /** @var list<string> */
    public const STATUSES = ['tentative', 'confirmed', 'cancelled', 'completed'];

    /** @var list<string> */
    public const RECURRENCE = ['', 'daily', 'weekly', 'monthly'];

    /** Linkable CRM relationships: field => whether it lands on the activity timeline. */
    private const LINKS = [
        'contact_uuid'     => 'contact',
        'company_uuid'     => 'company',
        'opportunity_uuid' => 'opportunity',
        'enquiry_uuid'     => 'enquiry',
        'preview_uuid'     => 'preview',
        'invoice_uuid'     => 'invoice',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ActivityRepository $activities,
    ) {
    }

    // ==================================================================
    // Read
    // ==================================================================

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $row = $this->db->one('SELECT * FROM calendar_events WHERE uuid = :u', ['u' => $uuid]);

        return $row === null ? null : $this->row($row);
    }

    /**
     * All event occurrences overlapping [from, to], recurring events expanded.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function range(string $fromIso, string $toIso, array $filters = []): array
    {
        $from = $this->parse($fromIso);
        $to   = $this->parse($toIso);
        if ($from === null || $to === null) {
            throw new CalendarException(422, 'Invalid date range.');
        }

        $where  = ['status != \'cancelled\''];
        $params = [];
        foreach (['contact_uuid', 'company_uuid', 'opportunity_uuid'] as $link) {
            if (!empty($filters[$link])) {
                $where[]        = "$link = :$link";
                $params[$link]  = (string) $filters[$link];
            }
        }
        if (!empty($filters['type'])) {
            $where[]        = 'event_type = :type';
            $params['type'] = (string) $filters['type'];
        }

        $rows = $this->db->all('SELECT * FROM calendar_events WHERE ' . implode(' AND ', $where), $params);
        $out  = [];
        foreach ($rows as $row) {
            foreach ($this->expand($this->row($row), $from, $to) as $occurrence) {
                $out[] = $occurrence;
            }
        }
        usort($out, static fn ($a, $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

        return $out;
    }

    /**
     * Events linked to a CRM entity (for the client workspace / timeline).
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $field, string $uuid, int $limit = 50): array
    {
        if (!array_key_exists($field, self::LINKS)) {
            return [];
        }
        $rows = $this->db->all(
            "SELECT * FROM calendar_events WHERE {$field} = :u ORDER BY starts_at DESC LIMIT :l",
            ['u' => $uuid, 'l' => $limit]
        );

        return array_map(fn (array $r): array => $this->row($r), $rows);
    }

    /**
     * Events whose reminder is due at or before $nowIso and not yet sent.
     *
     * @return list<array<string,mixed>>
     */
    public function dueReminders(string $nowIso): array
    {
        $rows = $this->db->all(
            "SELECT * FROM calendar_events
             WHERE reminder_minutes > 0 AND reminder_sent = 0
               AND status IN ('confirmed','tentative') AND recurrence = ''",
            []
        );
        $now = $this->parse($nowIso);
        $due = [];
        foreach ($rows as $row) {
            $start = $this->parse((string) $row['starts_at']);
            if ($start === null || $now === null) {
                continue;
            }
            $remindAt = $start->sub(new DateInterval('PT' . (int) $row['reminder_minutes'] . 'M'));
            if ($remindAt <= $now && $start >= $now) {
                $due[] = $this->row($row);
            }
        }

        return $due;
    }

    public function markReminderSent(string $uuid): void
    {
        $this->db->run('UPDATE calendar_events SET reminder_sent = 1, updated_at = :n WHERE uuid = :u', ['n' => Clock::nowIso(), 'u' => $uuid]);
    }

    // ==================================================================
    // Write
    // ==================================================================

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function create(array $in, string $actor, string $source = 'manual'): array
    {
        $data = $this->validate($in);
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();

        $this->db->run(
            'INSERT INTO calendar_events
                (uuid, title, description, starts_at, ends_at, all_day, timezone, location, meeting_link,
                 event_type, status, contact_uuid, company_uuid, opportunity_uuid, enquiry_uuid, preview_uuid,
                 invoice_uuid, recurrence, recurrence_interval, recurrence_until, recurrence_count, exceptions,
                 reminder_minutes, assigned_to, created_by, source, created_at, updated_at)
             VALUES
                (:uuid, :title, :description, :starts_at, :ends_at, :all_day, :timezone, :location, :meeting_link,
                 :event_type, :status, :contact_uuid, :company_uuid, :opportunity_uuid, :enquiry_uuid, :preview_uuid,
                 :invoice_uuid, :recurrence, :recurrence_interval, :recurrence_until, :recurrence_count, \'\',
                 :reminder_minutes, :assigned_to, :created_by, :source, :now, :now)',
            $data + ['uuid' => $uuid, 'created_by' => $actor, 'source' => $source, 'now' => $now]
        );

        $this->recordActivity($uuid, $data, $actor, 'event.scheduled', 'Scheduled: ' . $data['title']);

        return $this->find($uuid) ?? [];
    }

    /**
     * Update an event. $scope: 'series' (default), 'occurrence' (detach a single
     * instance of a series), or 'following' (split the series at $occurrenceDate).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $in, string $actor, string $scope = 'series', string $occurrenceDate = ''): array
    {
        $existing = $this->db->one('SELECT * FROM calendar_events WHERE uuid = :u', ['u' => $uuid]);
        if ($existing === null) {
            throw new CalendarException(404, 'Event not found.');
        }

        // Editing one occurrence of a recurring series: exclude that date and
        // create a standalone event carrying the edits.
        if ($scope === 'occurrence' && (string) $existing['recurrence'] !== '' && $occurrenceDate !== '') {
            $this->addException($uuid, $occurrenceDate);
            $base = $this->row($existing);
            unset($base['recurrence'], $base['recurrence_interval'], $base['recurrence_until'], $base['recurrence_count']);
            $merged = array_merge($base, $in, ['recurrence' => '']);

            return $this->create($merged, $actor, (string) $existing['source']);
        }

        // Split the series: end the old master before this date, start a new one.
        if ($scope === 'following' && (string) $existing['recurrence'] !== '' && $occurrenceDate !== '') {
            $cut = $this->parse($occurrenceDate);
            if ($cut !== null) {
                $until = $cut->sub(new DateInterval('P1D'))->format('Y-m-d');
                $this->db->run('UPDATE calendar_events SET recurrence_until = :u, updated_at = :n WHERE uuid = :id', ['u' => $until, 'n' => Clock::nowIso(), 'id' => $uuid]);
            }
            $base = $this->row($existing);
            $merged = array_merge($base, $in);

            return $this->create($merged, $actor, (string) $existing['source']);
        }

        $data = $this->validate(array_merge($this->row($existing), $in));
        $sets = [];
        foreach ($data as $k => $v) {
            $sets[] = "$k = :$k";
        }
        $this->db->run(
            'UPDATE calendar_events SET ' . implode(', ', $sets) . ', reminder_sent = 0, updated_at = :now WHERE uuid = :uuid',
            $data + ['uuid' => $uuid, 'now' => Clock::nowIso()]
        );

        return $this->find($uuid) ?? [];
    }

    /**
     * Move/resize helper — updates just the times.
     *
     * @return array<string,mixed>
     */
    public function move(string $uuid, string $startsAt, string $endsAt, string $actor): array
    {
        return $this->update($uuid, ['starts_at' => $startsAt, 'ends_at' => $endsAt], $actor);
    }

    /** @return array<string,mixed> */
    public function complete(string $uuid, string $actor): array
    {
        return $this->update($uuid, ['status' => 'completed'], $actor);
    }

    public function delete(string $uuid, string $actor, string $scope = 'series', string $occurrenceDate = ''): void
    {
        $existing = $this->db->one('SELECT recurrence FROM calendar_events WHERE uuid = :u', ['u' => $uuid]);
        if ($existing === null) {
            throw new CalendarException(404, 'Event not found.');
        }
        if ($scope === 'occurrence' && (string) $existing['recurrence'] !== '' && $occurrenceDate !== '') {
            $this->addException($uuid, $occurrenceDate);

            return;
        }
        $this->db->run('DELETE FROM calendar_events WHERE uuid = :u', ['u' => $uuid]);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function validate(array $in): array
    {
        $errors = [];
        $title  = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'A title is required.';
        }
        $start = $this->parse((string) ($in['starts_at'] ?? ''));
        $end   = $this->parse((string) ($in['ends_at'] ?? ''));
        if ($start === null) {
            $errors['starts_at'] = 'A valid start time is required.';
        }
        if ($end === null) {
            $errors['ends_at'] = 'A valid end time is required.';
        }
        if ($start !== null && $end !== null && $end < $start) {
            $errors['ends_at'] = 'The end must be after the start.';
        }
        if ($errors !== []) {
            throw new CalendarException(422, 'Please fix the highlighted fields.', $errors);
        }

        $type = (string) ($in['event_type'] ?? 'meeting');
        $status = (string) ($in['status'] ?? 'confirmed');
        $recurrence = (string) ($in['recurrence'] ?? '');

        /** @var DateTimeImmutable $start */
        /** @var DateTimeImmutable $end */
        $data = [
            'title'               => $title,
            'description'         => (string) ($in['description'] ?? ''),
            'starts_at'           => $start->format(DATE_ATOM),
            'ends_at'             => $end->format(DATE_ATOM),
            'all_day'             => !empty($in['all_day']) ? 1 : 0,
            'timezone'            => (string) ($in['timezone'] ?? 'Europe/London'),
            'location'            => (string) ($in['location'] ?? ''),
            'meeting_link'        => (string) ($in['meeting_link'] ?? ''),
            'event_type'          => in_array($type, self::TYPES, true) ? $type : 'meeting',
            'status'              => in_array($status, self::STATUSES, true) ? $status : 'confirmed',
            'recurrence'          => in_array($recurrence, self::RECURRENCE, true) ? $recurrence : '',
            'recurrence_interval' => max(1, (int) ($in['recurrence_interval'] ?? 1)),
            'recurrence_until'    => (string) ($in['recurrence_until'] ?? ''),
            'recurrence_count'    => max(0, (int) ($in['recurrence_count'] ?? 0)),
            'reminder_minutes'    => max(0, (int) ($in['reminder_minutes'] ?? 0)),
            'assigned_to'         => (string) ($in['assigned_to'] ?? ''),
        ];
        foreach (self::LINKS as $field => $_) {
            $data[$field] = ($in[$field] ?? null) !== null && (string) $in[$field] !== '' ? (string) $in[$field] : null;
        }

        return $data;
    }

    /**
     * Expand a stored event into its occurrences overlapping [from, to].
     *
     * @param array<string,mixed> $event
     * @return list<array<string,mixed>>
     */
    private function expand(array $event, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $start = $this->parse((string) $event['starts_at']);
        $end   = $this->parse((string) $event['ends_at']);
        if ($start === null || $end === null) {
            return [];
        }
        $duration = $start->diff($end);

        if ((string) $event['recurrence'] === '') {
            return ($end >= $from && $start <= $to) ? [$this->occurrence($event, $start, $end, false)] : [];
        }

        $step = match ((string) $event['recurrence']) {
            'daily'   => 'P' . (int) $event['recurrence_interval'] . 'D',
            'weekly'  => 'P' . ((int) $event['recurrence_interval'] * 7) . 'D',
            'monthly' => 'P' . (int) $event['recurrence_interval'] . 'M',
            default   => 'P1D',
        };
        $interval   = new DateInterval($step);
        $until      = $event['recurrence_until'] !== '' ? $this->parse((string) $event['recurrence_until']) : null;
        $maxCount   = (int) $event['recurrence_count'];
        $exceptions = array_flip(array_filter(explode(',', (string) $event['exceptions'])));

        $out = [];
        $cur = $start;
        for ($i = 0; $i < 1000; $i++) {
            if ($cur > $to) {
                break;
            }
            if ($until !== null && $cur > $until) {
                break;
            }
            if ($maxCount > 0 && $i >= $maxCount) {
                break;
            }
            $occEnd = $cur->add($duration);
            $dateKey = $cur->format('Y-m-d');
            if ($occEnd >= $from && !isset($exceptions[$dateKey])) {
                $out[] = $this->occurrence($event, $cur, $occEnd, true);
            }
            $cur = $cur->add($interval);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function occurrence(array $event, DateTimeImmutable $start, DateTimeImmutable $end, bool $recurring): array
    {
        $event['starts_at']       = $start->format(DATE_ATOM);
        $event['ends_at']         = $end->format(DATE_ATOM);
        $event['occurrence_date'] = $start->format('Y-m-d');
        $event['recurring']       = $recurring;

        return $event;
    }

    private function addException(string $uuid, string $date): void
    {
        $row = $this->db->one('SELECT exceptions FROM calendar_events WHERE uuid = :u', ['u' => $uuid]);
        $existing = array_filter(explode(',', (string) ($row['exceptions'] ?? '')));
        $day = substr($date, 0, 10);
        if (!in_array($day, $existing, true)) {
            $existing[] = $day;
        }
        $this->db->run('UPDATE calendar_events SET exceptions = :e, updated_at = :n WHERE uuid = :u', ['e' => implode(',', $existing), 'n' => Clock::nowIso(), 'u' => $uuid]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function recordActivity(string $uuid, array $data, string $actor, string $type, string $summary): void
    {
        foreach (self::LINKS as $field => $entityType) {
            if (!empty($data[$field])) {
                $this->activities->record($entityType, (string) $data[$field], $type, $summary, 'user', $actor, ['event' => $uuid]);
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function row(array $row): array
    {
        $row['all_day']       = (int) ($row['all_day'] ?? 0) === 1;
        $row['reminder_sent'] = (int) ($row['reminder_sent'] ?? 0) === 1;

        return $row;
    }

    private function parse(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
