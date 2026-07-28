<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\AdminApi;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the standalone Breakfast Admin API (AdminApi) end to end against a
 * temp database. It proves the two things that matter most for a private admin:
 *  - authentication is enforced on the server for every non-session endpoint
 *    (no user → 401, never a leak), and
 *  - an authenticated admin gets real, correctly shaped data back.
 * The client is never trusted to hide anything; these gates are the access control.
 */
final class AdminApiTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-admin-' . bin2hex(random_bytes(6));

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
                    'previews'   => ['storageDir' => $this->tmp . '/client-previews', 'limits' => []],
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        // Kirby installs Whoops handlers; remove them so PHPUnit isn't "risky".
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    private function api(): AdminApi
    {
        return new AdminApi($this->kirby, breakfast());
    }

    /**
     * @return array{code:int,data:array<string,mixed>}
     */
    private function call(string $path): array
    {
        $res  = $this->api()->handle($path);
        $this->assertInstanceOf(Response::class, $res);
        $body = json_decode((string) $res->body(), true);
        $this->assertIsArray($body);

        return ['code' => $res->code(), 'data' => is_array($body['data'] ?? null) ? $body['data'] : $body];
    }

    // -- Authentication is enforced server-side ---------------------------

    public function testSessionProbeIsUnauthenticatedByDefault(): void
    {
        $out = $this->call('session');
        $this->assertSame(401, $out['code']);
    }

    public function testProtectedEndpointsRequireAuth(): void
    {
        foreach (['dashboard', 'enquiries', 'contacts', 'opportunities', 'reports', 'operations'] as $path) {
            $out = $this->call($path);
            $this->assertSame(401, $out['code'], "$path must require authentication");
        }
    }

    public function testUnknownEndpointIs404WhenAuthenticated(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('does-not-exist');
        $this->assertSame(404, $out['code']);
    }

    // -- Authenticated admin gets real, shaped data -----------------------

    public function testDashboardReturnsShapedData(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('dashboard');

        $this->assertSame(200, $out['code']);
        foreach (['greeting', 'attention', 'metrics', 'pipeline', 'recent', 'health'] as $key) {
            $this->assertArrayHasKey($key, $out['data']);
        }
        $this->assertIsArray($out['data']['pipeline']);
    }

    public function testCrmListEndpointsReturnItems(): void
    {
        $this->kirby->impersonate('kirby');

        foreach (['enquiries', 'contacts', 'companies', 'opportunities', 'tasks'] as $path) {
            $out = $this->call($path);
            $this->assertSame(200, $out['code'], "$path should be readable by an admin");
            $this->assertArrayHasKey('items', $out['data'], "$path should return an items array");
            $this->assertIsArray($out['data']['items']);
        }
    }

    public function testOpportunitiesExposeLabelledStages(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('opportunities');

        $this->assertSame(200, $out['code']);
        $this->assertArrayHasKey('stages', $out['data']);
        $this->assertNotEmpty($out['data']['stages']);
        $first = $out['data']['stages'][0];
        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('label', $first);
    }

    public function testOperationsRequiresAdminAndReports(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('operations');

        $this->assertSame(200, $out['code']);
        $this->assertArrayHasKey('queue', $out['data']);
        $this->assertArrayHasKey('mail', $out['data']);
    }

    public function testWebsiteOverviewListsPublicPages(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('website');

        $this->assertSame(200, $out['code']);
        $this->assertArrayHasKey('items', $out['data']);
        $this->assertIsArray($out['data']['items']);
    }

    public function testInvoiceEndpointsRequireAuth(): void
    {
        foreach (['invoices', 'invoices/settings'] as $path) {
            $out = $this->call($path);
            $this->assertSame(401, $out['code'], "$path must require authentication");
        }
    }

    public function testInvoiceListAndSettingsAreShapedForAnAdmin(): void
    {
        $this->kirby->impersonate('kirby');

        $list = $this->call('invoices');
        $this->assertSame(200, $list['code']);
        $this->assertArrayHasKey('items', $list['data']);
        $this->assertIsArray($list['data']['items']);

        $settings = $this->call('invoices/settings');
        $this->assertSame(200, $settings['code']);
        foreach (['currency', 'invoice_prefix', 'vat_registered', 'default_terms_days'] as $key) {
            $this->assertArrayHasKey($key, $settings['data']['settings']);
        }
    }

    public function testInvoiceDetailFor404Id(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('invoices/does-not-exist');
        $this->assertSame(404, $out['code']);
    }

    public function testInvoicePdfVersionsEndpointIsRoutedForAnAdmin(): void
    {
        $this->kirby->impersonate('kirby');
        // GET version listing routes and returns an items array (empty until a
        // document is generated). Write flows (issue → generate, draft,
        // regenerate) are proven at the service layer in InvoiceDocumentsTest
        // and end to end in the Playwright invoice journeys.
        $inv = breakfast()->invoices()->create([
            'bill_to_name' => 'Roberts Cafe',
            'items' => [['description' => 'Website', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 20]],
        ], 'kirby@test');
        $out = $this->call('invoices/' . (string) $inv['uuid'] . '/pdf/versions');
        $this->assertSame(200, $out['code']);
        $this->assertArrayHasKey('items', $out['data']);
        $this->assertIsArray($out['data']['items']);
    }

    public function testWebsiteEndpointsRequireAuth(): void
    {
        foreach (['website', 'website/page/home'] as $path) {
            $out = $this->call($path);
            $this->assertSame(401, $out['code'], "$path must require authentication");
        }
    }

    public function testWebsiteIndexAndPageLoadAreShaped(): void
    {
        $this->kirby->impersonate('kirby');

        $index = $this->call('website');
        $this->assertSame(200, $index['code']);
        $ids = array_column($index['data']['items'], 'id');
        $this->assertContains('site', $ids);
        $this->assertContains('home', $ids);

        $home = $this->call('website/page/home');
        $this->assertSame(200, $home['code']);
        $this->assertArrayHasKey('fields', $home['data']);
        $this->assertArrayHasKey('readonly', $home['data']);
        $this->assertIsArray($home['data']['fields']);
    }

    public function testWebsiteUnknownPageIs404(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('website/page/does-not-exist');
        $this->assertSame(404, $out['code']);
    }

    public function testBrevoSettingsRequireAuth(): void
    {
        $out = $this->call('settings/brevo');
        $this->assertSame(401, $out['code']);
    }

    public function testCalendarRequiresAuth(): void
    {
        $out = $this->call('calendar/events');
        $this->assertSame(401, $out['code']);
    }

    public function testSearchRequiresAuth(): void
    {
        $out = $this->call('search');
        $this->assertSame(401, $out['code']);
    }

    public function testSearchEndpointIsRoutedAndShapedForAnAdmin(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('search');
        $this->assertSame(200, $out['code']);
        $this->assertArrayHasKey('items', $out['data']);
        $this->assertIsArray($out['data']['items']);
    }

    public function testCalendarRangeReturnsItemsForAnAdmin(): void
    {
        $this->kirby->impersonate('kirby');
        $out = $this->call('calendar/events');
        $this->assertSame(200, $out['code']);
        $this->assertArrayHasKey('items', $out['data']);
        $this->assertIsArray($out['data']['items']);
    }

    public function testBrevoOverviewNeverLeaksTheKeyForAnAdmin(): void
    {
        $this->kirby->impersonate('kirby');
        // Seed a key through the service, then read the API overview.
        breakfast()->brevoSettings()->setKey('xkeysib-APILEAKCHECK-0000abcd9999', 'kirby');

        $out = $this->call('settings/brevo');
        $this->assertSame(200, $out['code']);
        $this->assertTrue($out['data']['brevo']['configured']);
        $this->assertSame('••••9999', $out['data']['brevo']['key_hint']);
        $this->assertStringNotContainsString('APILEAKCHECK', json_encode($out['data']) ?: '');
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
