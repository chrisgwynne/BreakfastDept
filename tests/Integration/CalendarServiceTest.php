<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Calendar\CalendarException;
use Breakfast\Platform\Calendar\CalendarService;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The calendar that ties scheduled work into the same client lifecycle as
 * everything else. Proves real CRUD, recurrence expansion within a range,
 * per-occurrence exceptions and single-occurrence detach, reminder detection,
 * validation, and that a client-linked event lands on that client's activity
 * timeline.
 */
final class CalendarServiceTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private CalendarService $cal;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-cal-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);

        Database::reset();
        Platform::reset();

        $this->kirby = new App([
            'roots' => [
                'index'    => $base . '/public',
                'base'     => $base,
                'site'     => $base . '/site',
                'content'  => $base . '/content',
                'storage'  => $this->tmp,
                'sessions' => $this->tmp . '/sessions',
                'accounts' => $this->tmp . '/accounts',
            ],
            'options' => [
                'debug'  => false,
                'whoops' => false,
                'breakfast' => [
                    'production' => false,
                    'storageDir' => $this->tmp,
                    'dbPath'     => $this->tmp . '/database/crm.sqlite',
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
        $this->cal = breakfast()->calendar();
    }

    protected function tearDown(): void
    {
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testCreateAndFindSingleEvent(): void
    {
        $e = $this->cal->create([
            'title' => 'Discovery call', 'starts_at' => '2026-08-03T10:00:00Z', 'ends_at' => '2026-08-03T10:30:00Z', 'event_type' => 'call',
        ], 'me@test');

        $this->assertNotEmpty($e['uuid']);
        $this->assertSame('call', $e['event_type']);
        $this->assertSame('confirmed', $e['status']);
        $this->assertNotNull($this->cal->find((string) $e['uuid']));
    }

    public function testValidationRejectsBadInput(): void
    {
        try {
            $this->cal->create(['title' => '', 'starts_at' => '2026-08-03T10:00:00Z', 'ends_at' => '2026-08-03T09:00:00Z'], 'me@test');
            $this->fail('Expected validation failure');
        } catch (CalendarException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('title', $e->fields);
            $this->assertArrayHasKey('ends_at', $e->fields);
        }
    }

    public function testClientLinkedEventLandsOnTheContactTimeline(): void
    {
        $write = new \Breakfast\Platform\Admin\CrmWrite(breakfast());
        $lead  = $write->createLead(['name' => 'Sian', 'email' => 's@ex.co'], 'me@test');
        $cid   = (string) $lead['contact']['uuid'];

        $this->cal->create([
            'title' => 'Kickoff', 'starts_at' => '2026-08-05T09:00:00Z', 'ends_at' => '2026-08-05T10:00:00Z', 'contact_uuid' => $cid,
        ], 'me@test');

        $types = array_column(breakfast()->activities()->forEntity('contact', $cid), 'type');
        $this->assertContains('event.scheduled', $types);
        $this->assertNotEmpty($this->cal->forEntity('contact_uuid', $cid));
    }

    public function testWeeklyRecurrenceExpandsWithinRange(): void
    {
        $this->cal->create([
            'title' => 'Standup', 'starts_at' => '2026-08-04T09:00:00Z', 'ends_at' => '2026-08-04T09:15:00Z',
            'recurrence' => 'weekly', 'recurrence_count' => 4,
        ], 'me@test');

        $occ = $this->cal->range('2026-08-01T00:00:00Z', '2026-08-31T23:59:59Z');
        $standups = array_filter($occ, fn ($o) => $o['title'] === 'Standup');
        $this->assertCount(4, $standups);
    }

    public function testCancellingOneOccurrenceLeavesAnException(): void
    {
        $e = $this->cal->create([
            'title' => 'Standup', 'starts_at' => '2026-08-04T09:00:00Z', 'ends_at' => '2026-08-04T09:15:00Z',
            'recurrence' => 'weekly', 'recurrence_count' => 4,
        ], 'me@test');

        $this->cal->delete((string) $e['uuid'], 'me@test', 'occurrence', '2026-08-11');
        $occ = $this->cal->range('2026-08-01T00:00:00Z', '2026-08-31T23:59:59Z');
        $this->assertCount(3, array_filter($occ, fn ($o) => $o['title'] === 'Standup'));
    }

    public function testEditingOneOccurrenceDetachesIt(): void
    {
        $e = $this->cal->create([
            'title' => 'Standup', 'starts_at' => '2026-08-04T09:00:00Z', 'ends_at' => '2026-08-04T09:15:00Z',
            'recurrence' => 'weekly', 'recurrence_count' => 4,
        ], 'me@test');

        $this->cal->update((string) $e['uuid'], ['title' => 'Standup (moved)', 'starts_at' => '2026-08-18T11:00:00Z', 'ends_at' => '2026-08-18T11:15:00Z'], 'me@test', 'occurrence', '2026-08-18');
        $titles = array_column($this->cal->range('2026-08-01T00:00:00Z', '2026-08-31T23:59:59Z'), 'title');
        $this->assertContains('Standup (moved)', $titles);
    }

    public function testReminderDueDetection(): void
    {
        $this->cal->create([
            'title' => 'Call', 'starts_at' => '2026-08-03T10:00:00Z', 'ends_at' => '2026-08-03T10:30:00Z', 'reminder_minutes' => 15,
        ], 'me@test');

        $this->assertCount(1, $this->cal->dueReminders('2026-08-03T09:50:00Z'));
        $this->assertCount(0, $this->cal->dueReminders('2026-08-03T09:30:00Z'), 'not due 30 min out');
    }

    public function testDeleteRemovesEvent(): void
    {
        $e = $this->cal->create(['title' => 'X', 'starts_at' => '2026-08-03T10:00:00Z', 'ends_at' => '2026-08-03T10:30:00Z'], 'me@test');
        $this->cal->delete((string) $e['uuid'], 'me@test');
        $this->assertNull($this->cal->find((string) $e['uuid']));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
