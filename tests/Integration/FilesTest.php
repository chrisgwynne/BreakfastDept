<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Files\FileException;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Client file library — upload security, immutable versioning, usage-reference
 * protection, download integrity and duplicate detection. Proves the classic
 * upload bypasses are rejected (executable, double-extension, MIME mismatch,
 * unsafe SVG, archive traversal) and that protected legal/financial documents
 * cannot be replaced or deleted through the library.
 */
final class FilesTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-files-' . bin2hex(random_bytes(6));
        $this->scratch = $this->tmp . '/scratch';
        @mkdir($this->scratch, 0777, true);
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'mail' => ['provider' => 'fake']]],
        ]);
    }

    protected function tearDown(): void
    {
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    /** Write a temp file and return its path. */
    private function tmpFile(string $name, string $content): string
    {
        $path = $this->scratch . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function pngBytes(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function testValidUploadStoresVersionOutsideWebrootWithHash(): void
    {
        $path = $this->tmpFile('logo.png', $this->pngBytes());
        $file = breakfast()->files()->upload($path, 'logo.png', 'image/png', ['category' => 'logo', 'display_name' => 'Client logo'], 'staff@breakfast');
        $this->assertSame(1, (int) $file['current_version']);
        $this->assertSame('logo', (string) $file['category']);
        $this->assertNotSame('', (string) $file['current']['sha256']);
        // Bytes readable with an integrity check + it records an access event.
        $dl = breakfast()->files()->download((string) $file['uuid'], null, 'staff@breakfast');
        $this->assertSame($this->pngBytes(), $dl['bytes']);
        $this->assertSame(1, count(breakfast()->fileStore()->find('client_files', (string) $file['uuid'])['access_events'] ?? []));
        // A thumbnail was generated for the image.
        $this->assertSame('ready', (string) $file['current']['thumb_state']);
    }

    public function testRejectsExecutableAndDoubleExtensionAndMimeMismatch(): void
    {
        $php = $this->tmpFile('shell.php', '<?php echo 1;');
        $this->assertError(422, fn () => breakfast()->files()->upload($php, 'shell.php', 'application/octet-stream', [], 'a'));
        // Double extension: logo.php.png — the inner .php token is forbidden.
        $dbl = $this->tmpFile('logo.php.png', $this->pngBytes());
        $this->assertError(422, fn () => breakfast()->files()->upload($dbl, 'logo.php.png', 'image/png', [], 'a'));
        // A PHP script renamed .png — signature/MIME mismatch.
        $fake = $this->tmpFile('evil.png', '<?php system($_GET["c"]); ?>');
        $this->assertError(422, fn () => breakfast()->files()->upload($fake, 'evil.png', 'image/png', [], 'a'));
    }

    public function testRejectsUnsafeSvg(): void
    {
        $svg = $this->tmpFile('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertError(422, fn () => breakfast()->files()->upload($svg, 'x.svg', 'image/svg+xml', [], 'a'));
        $handler = $this->tmpFile('y.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        $this->assertError(422, fn () => breakfast()->files()->upload($handler, 'y.svg', 'image/svg+xml', [], 'a'));
        $ext = $this->tmpFile('z.svg', '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.test/x.png"/></svg>');
        $this->assertError(422, fn () => breakfast()->files()->upload($ext, 'z.svg', 'image/svg+xml', [], 'a'));
        // A clean SVG is accepted.
        $ok = $this->tmpFile('ok.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>');
        $file = breakfast()->files()->upload($ok, 'ok.svg', 'image/svg+xml', [], 'a');
        $this->assertSame('svg', (string) $file['current']['extension']);
    }

    public function testRejectsArchiveTraversal(): void
    {
        $zipPath = $this->scratch . '/evil.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('../../etc/passwd', 'x');
        $zip->close();
        $this->assertError(422, fn () => breakfast()->files()->upload($zipPath, 'evil.zip', 'application/zip', [], 'a'));
    }

    public function testReplaceCreatesNewImmutableVersionAndPreservesOld(): void
    {
        $v1 = breakfast()->files()->upload($this->tmpFile('a.txt', 'version one'), 'a.txt', 'text/plain', [], 'staff@breakfast');
        $uuid = (string) $v1['uuid'];
        $v2 = breakfast()->files()->replace($uuid, $this->tmpFile('a.txt', 'version two'), 'a.txt', 'text/plain', 'Fixed copy', 'staff@breakfast');
        $this->assertSame(2, (int) $v2['current_version']);
        $this->assertCount(2, $v2['versions']);
        // Both versions' bytes are preserved + downloadable.
        $this->assertSame('version one', breakfast()->files()->download($uuid, 1, 'staff@breakfast')['bytes']);
        $this->assertSame('version two', breakfast()->files()->download($uuid, 2, 'staff@breakfast')['bytes']);
        // Rollback restores the current pointer without losing v2.
        $rolled = breakfast()->files()->restoreVersion($uuid, 1, 'staff@breakfast');
        $this->assertSame(1, (int) $rolled['current_version']);
        $this->assertCount(2, $rolled['versions']);
    }

    public function testImmutableDocumentCannotBeReplacedOrDeleted(): void
    {
        $doc = breakfast()->files()->registerImmutable(['display_name' => 'Invoice INV-1', 'category' => 'invoice', 'source' => 'invoice'], 'system');
        $uuid = (string) $doc['uuid'];
        $this->assertError(409, fn () => breakfast()->files()->replace($uuid, $this->tmpFile('x.pdf', '%PDF-1.4'), 'x.pdf', 'application/pdf', '', 'a'));
        $this->assertError(409, fn () => breakfast()->files()->delete($uuid, 'because', 'admin'));
    }

    public function testUsageReferencesBlockDeletionUntilUnlinked(): void
    {
        $file = breakfast()->files()->upload($this->tmpFile('brief.txt', 'brief'), 'brief.txt', 'text/plain', [], 'staff@breakfast');
        $uuid = (string) $file['uuid'];
        breakfast()->files()->link($uuid, 'project', 'proj-123', 'staff@breakfast');
        $this->assertCount(1, breakfast()->files()->usage($uuid));
        // A referenced file cannot be permanently deleted.
        $this->assertError(409, fn () => breakfast()->files()->delete($uuid, 'cleanup', 'admin'));
        // After unlinking, deletion (with a reason) succeeds.
        breakfast()->files()->unlink($uuid, 'project', 'proj-123');
        $this->assertTrue(breakfast()->files()->delete($uuid, 'cleanup', 'admin')['ok']);
    }

    public function testDuplicateDetectionByHash(): void
    {
        $bytes = $this->pngBytes();
        $file = breakfast()->files()->upload($this->tmpFile('one.png', $bytes), 'one.png', 'image/png', [], 'staff@breakfast');
        $hash = (string) $file['current']['sha256'];
        $dup = breakfast()->files()->findDuplicate($hash);
        $this->assertNotNull($dup);
        $this->assertSame((string) $file['uuid'], $dup['file_uuid']);
    }

    public function testDownloadDetectsTampering(): void
    {
        $file = breakfast()->files()->upload($this->tmpFile('t.txt', 'trusted'), 't.txt', 'text/plain', [], 'staff@breakfast');
        $uuid = (string) $file['uuid'];
        $versions = breakfast()->fileStore()->find('client_files', $uuid)['versions'] ?? [];
        $key = (string) (array_values(array_filter($versions, static fn (array $v): bool => (int) ($v['version'] ?? 0) === 1))[0]['storage_key'] ?? '');
        file_put_contents($this->tmp . '/client-files/' . $key, 'tampered');
        $this->assertError(409, fn () => breakfast()->files()->download($uuid, 1, 'staff@breakfast'));
    }

    private function assertError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a FileException with status ' . $status);
        } catch (FileException $e) {
            $this->assertSame($status, $e->status);
        }
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
