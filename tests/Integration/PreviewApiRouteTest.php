<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the REAL Client Previews Panel API route handlers (the closures in
 * api/previews.php), with an impersonated admin passing the permission gates.
 * This is the coverage that was missing — services were tested, the route
 * handlers were not — and it proves create → publish → detail → delete actually
 * run (audit `event()` calls included) and that password_hash never leaves the
 * server.
 */
final class PreviewApiRouteTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    /** @var list<array<string,mixed>> */
    private array $routes;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-api-' . bin2hex(random_bytes(6));
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
                    'previews'   => ['storageDir' => $this->tmp . '/client-previews', 'limits' => []],
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
        $this->kirby->impersonate('kirby'); // almighty admin — passes PreviewGate

        $this->routes = require $base . '/site/plugins/breakfast-platform/api/previews.php';
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = [];
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        // Kirby installs a Whoops error/exception handler; remove it so PHPUnit
        // does not flag the test as risky.
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    /**
     * Invoke a registered route handler by pattern + method. Inputs for the
     * `get()` helper are provided via request globals; URL params are passed
     * positionally.
     *
     * @param array<string,mixed> $input
     * @param list<string> $params
     * @return array<string,mixed>
     */
    private function call(string $pattern, string $method, array $input = [], array $params = []): array
    {
        // Kirby memoises request(); reset it (without rebuilding the App, which
        // would re-install error handlers) so the handlers' get() helper reads
        // THIS call's inputs.
        $request = new \Kirby\Http\Request(['query' => $input, 'body' => $input, 'method' => $method]);
        $prop = new \ReflectionProperty(App::class, 'request');
        $prop->setAccessible(true);
        $prop->setValue($this->kirby, $request);

        foreach ($this->routes as $route) {
            if ($route['pattern'] === $pattern && $route['method'] === $method) {
                /** @var callable $action */
                $action = $route['action'];
                $result = $action(...$params);

                return is_array($result) ? $result : ['data' => $result];
            }
        }
        $this->fail("route not found: {$method} {$pattern}");
    }

    public function testCreatePublishDetailDeleteFlow(): void
    {
        // Create — invokes audit()->event(); the earlier signature bug 500'd here.
        $created = $this->call('breakfast/previews', 'POST', ['name' => 'Acme', 'slug' => 'acme-api']);
        $this->assertArrayHasKey('data', $created, 'create errored: ' . json_encode($created));
        $uuid = (string) $created['data']['uuid'];

        // Seed a ready version via the service, then publish through the route.
        $r = breakfast()->previewUpload()->uploadZip($uuid, $this->zip(), 'x.zip', 'admin');
        $this->assertTrue($r['ok']);
        $published = $this->call('breakfast/previews/(:any)/publish', 'POST', ['version_uuid' => $r['version']['uuid']], [$uuid]);
        $this->assertArrayHasKey('data', $published, 'publish errored: ' . json_encode($published));
        $this->assertSame('active', $published['data']['status']);

        // The publish actually wrote an audit row (proves event() signature).
        $this->assertSame(1, (int) breakfast()->db()->scalar("SELECT COUNT(*) FROM hermes_audit WHERE endpoint = 'preview.publish'"));

        // Password set, then detail + list must NOT expose the hash.
        breakfast()->previewPasswords()->setPassword($uuid, 'secret-pw', 'admin');
        $detail = $this->call('breakfast/previews/(:any)', 'GET', [], [$uuid]);
        $this->assertArrayNotHasKey('password_hash', $detail['data'], 'password_hash leaked in detail');
        $this->assertTrue($detail['data']['password_protected']);

        $list = $this->call('breakfast/previews', 'GET');
        $this->assertNotEmpty($list['data']);
        foreach ($list['data'] as $row) {
            $this->assertArrayNotHasKey('password_hash', $row, 'password_hash leaked in list');
        }

        // Delete removes the row + cascades.
        $this->call('breakfast/previews/(:any)', 'DELETE', [], [$uuid]);
        $this->assertNull(breakfast()->previews()->find($uuid));
    }

    private function zip(): string
    {
        $path = $this->tmp . '/u-' . bin2hex(random_bytes(3)) . '.zip';
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE);
        $z->addFromString('index.html', '<title>x</title>');
        $z->close();

        return $path;
    }

    private function rrmdir(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }
        foreach (scandir($dir) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir . '/' . $i;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
