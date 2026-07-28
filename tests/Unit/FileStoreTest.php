<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Support\FileStore;
use PHPUnit\Framework\TestCase;

/**
 * The flat-file record store that the admin runs on instead of a database:
 * atomic writes, per-collection locking, and plain-JSON records on disk.
 */
final class FileStoreTest extends TestCase
{
    private string $tmp;
    private FileStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/bf-filestore-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp, 0777, true);
        $this->store = new FileStore($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->tmp . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    public function testPutFindRoundTrips(): void
    {
        $this->store->put('leads', ['uuid' => 'a1', 'name' => 'Jamie', 'status' => 'new']);
        $rec = $this->store->find('leads', 'a1');
        $this->assertNotNull($rec);
        $this->assertSame('Jamie', $rec['name']);
        // Stored as a real JSON file named by uuid.
        $this->assertFileExists($this->tmp . '/leads/a1.json');
    }

    public function testUpdateMutatesInPlace(): void
    {
        $this->store->put('leads', ['uuid' => 'a1', 'status' => 'new']);
        $updated = $this->store->update('leads', 'a1', function (array $r): array {
            $r['status'] = 'qualified';

            return $r;
        });
        $this->assertSame('qualified', $updated['status']);
        $this->assertSame('qualified', $this->store->find('leads', 'a1')['status']);
    }

    public function testUpdateMissingReturnsNull(): void
    {
        $this->assertNull($this->store->update('leads', 'nope', fn (array $r) => $r));
    }

    public function testAllAndCountAndDelete(): void
    {
        $this->store->put('leads', ['uuid' => 'a1']);
        $this->store->put('leads', ['uuid' => 'a2']);
        $this->assertSame(2, $this->store->count('leads'));
        $this->assertCount(2, $this->store->all('leads'));
        $this->assertTrue($this->store->delete('leads', 'a1'));
        $this->assertSame(1, $this->store->count('leads'));
        $this->assertNull($this->store->find('leads', 'a1'));
    }

    public function testRejectsRecordWithoutUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->put('leads', ['name' => 'no id']);
    }

    public function testCollectionsAreIsolated(): void
    {
        $this->store->put('leads', ['uuid' => 'x']);
        $this->store->put('contacts', ['uuid' => 'x', 'name' => 'different']);
        $this->assertSame('different', $this->store->find('contacts', 'x')['name']);
        $this->assertArrayNotHasKey('name', $this->store->find('leads', 'x'));
    }
}
