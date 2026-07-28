<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The staff operational inbox. Proves it is a live to-do derived from
 * source-of-truth state: an unread client message and a due retainer appear,
 * and the message drops out the moment staff read the thread — no separate
 * notification store to drift.
 */
final class InboxTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private string $project;
    private string $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-inbox-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
        $p = breakfast();
        $contact = (string) $p->contacts()->create(['display_name' => 'Sian', 'email' => 's@x.co'])['uuid'];
        $this->project = (string) $p->projects()->create(['name' => 'Inbox project', 'contact_uuid' => $contact], 'staff@breakfast')['uuid'];
        $this->identity = (string) $p->portal()->createIdentity(['email' => 's@x.co', 'display_name' => 'Sian', 'contact_uuid' => $contact], 'staff@breakfast')['uuid'];
        $p->portal()->grantAccess($this->identity, 'project', $this->project, 'viewer', 'staff@breakfast');
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

    public function testInboxIsEmptyWhenNothingNeedsAction(): void
    {
        $summary = breakfast()->inbox()->summary();
        $this->assertSame(0, $summary['total']);
    }

    public function testSurfacesActionableSignalsAndClearsWhenHandled(): void
    {
        $p = breakfast();
        // A client message and a piece of feedback.
        $p->portal()->postClientMessage($this->identity, $this->project, 'Any update?');
        $p->portal()->submitFeedback($this->identity, $this->project, ['kind' => 'comment', 'body' => 'Looks good']);
        // A retainer that is already overdue to bill.
        $p->retainers()->create($this->project, ['title' => 'Care', 'fee' => 100, 'start_date' => '2020-01-01'], 'staff@breakfast');

        $summary = $p->inbox()->summary();
        $this->assertSame(1, $summary['messages']);
        $this->assertSame(1, $summary['feedback']);
        $this->assertSame(1, $summary['retainers']);
        $this->assertSame(3, $summary['total']);

        $items = $p->inbox()->items();
        $this->assertCount(1, $items['messages'] ?? []);
        $this->assertSame('Inbox project', (string) $items['messages'][0]['project_name']);

        // Reading the thread as staff clears the message from the inbox.
        $p->portal()->messageThread($this->project, $this->identity, 'staff');
        $this->assertSame(0, $p->inbox()->summary()['messages']);

        // Resolving the feedback clears it too.
        $open = array_values(array_filter($p->fileStore()->all('portal_feedback'), static fn (array $r): bool => (string) ($r['status'] ?? '') === 'open'))[0];
        $p->portal()->setFeedbackStatus((string) $open['uuid'], 'resolved', 'staff@breakfast');
        $this->assertSame(0, $p->inbox()->summary()['feedback']);
    }

    public function testApprovedUnappliedChangeRequestAppears(): void
    {
        $p = breakfast();
        $cr = $p->changeRequests()->create($this->project, ['title' => 'Extra', 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 100, 'kind' => 'fixed']]], 'staff@breakfast');
        $p->changeRequests()->send((string) $cr['uuid'], 'https://breakfast.test', 'staff@breakfast');
        $p->changeRequests()->decide((string) $cr['uuid'], 'approved', ['name' => 'Sian'], 'client');

        $this->assertSame(1, $p->inbox()->summary()['change_requests']);
        // Applying it removes it from the inbox.
        $p->changeRequests()->apply((string) $cr['uuid'], [], 'staff@breakfast');
        $this->assertSame(0, $p->inbox()->summary()['change_requests']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
