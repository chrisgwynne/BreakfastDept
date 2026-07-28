<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Operational reporting. Proves per-project financial position (contract value =
 * quote + approved variations; invoiced; paid; outstanding), logged-time and
 * unbilled exposure, portfolio totals, and utilisation — all derived from the
 * source-of-truth tables.
 */
final class ReportingTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-reporting-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
        $this->project = (string) breakfast()->projects()->create(['name' => 'Reporting project', 'quoted_value' => 3000], 'staff@breakfast')['uuid'];
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

    public function testProjectRowRollsUpFinancialsAndTime(): void
    {
        $p = breakfast();
        // A £600 approved variation (added straight to the project).
        $p->fileStore()->update('projects', $this->project, static function (array $row): array {
            $row['approved_variations'] = 60000;
            return $row;
        });
        // 4 billable hours at £75/h = £300 unbilled.
        $p->time()->create($this->project, ['hours' => 4, 'billable' => true, 'rate' => 75], 'chris@breakfast');
        // An issued invoice of £1,200 with £500 paid.
        $inv = $p->invoices()->create(['bill_to_name' => 'Client', 'items' => [['description' => 'Deposit', 'quantity' => 1, 'unit_price' => 1200]]], 'staff@breakfast');
        $projectUuid = $this->project;
        $p->fileStore()->update('invoices', (string) $inv['uuid'], static function (array $row) use ($projectUuid): array {
            $row['project_uuid'] = $projectUuid;
            $row['status']       = 'issued';
            $row['total']        = 120000;
            $row['amount_paid']  = 50000;

            return $row;
        });

        $rows = $p->reporting()->projects();
        $row = null;
        foreach ($rows as $r) {
            if ((string) $r['uuid'] === $this->project) {
                $row = $r;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame(300000, (int) $row['quoted_value']);
        $this->assertSame(60000, (int) $row['approved_variations']);
        $this->assertSame(360000, (int) $row['contract_value']);   // quote + variation
        $this->assertSame(120000, (int) $row['invoiced']);
        $this->assertSame(50000, (int) $row['paid']);
        $this->assertSame(70000, (int) $row['outstanding']);       // 1200 - 500
        $this->assertSame(14400, (int) $row['billable_seconds']);  // 4h
        $this->assertSame(30000, (int) $row['unbilled_time_value']); // 4h × £75 = £300
    }

    public function testPortfolioSumsAcrossProjects(): void
    {
        $p = breakfast();
        $p->projects()->create(['name' => 'Second', 'quoted_value' => 1000], 'staff@breakfast');
        $portfolio = $p->reporting()->portfolio();
        $this->assertSame(2, $portfolio['projects']);
        $this->assertSame(400000, $portfolio['quoted_value']);      // £3000 + £1000
        $this->assertSame(400000, $portfolio['contract_value']);
    }

    public function testUtilisationSplitsBillableAndNonBillablePerAuthor(): void
    {
        $p = breakfast();
        $p->time()->create($this->project, ['hours' => 6, 'billable' => true, 'rate' => 80, 'author' => 'chris@breakfast'], 'chris@breakfast');
        $p->time()->create($this->project, ['hours' => 2, 'billable' => false, 'author' => 'chris@breakfast'], 'chris@breakfast');

        $util = $p->reporting()->utilisation(30);
        $mine = null;
        foreach ($util as $u) {
            if ((string) $u['author'] === 'chris@breakfast') {
                $mine = $u;
            }
        }
        $this->assertNotNull($mine);
        $this->assertSame(21600, (int) $mine['billable_seconds']);   // 6h
        $this->assertSame(7200, (int) $mine['nonbillable_seconds']); // 2h
        $this->assertSame(75, (int) $mine['billable_percent']);      // 6 / 8 = 75%
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
