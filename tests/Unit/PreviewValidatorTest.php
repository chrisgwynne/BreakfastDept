<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\ClientPreviews\PreviewArchiveValidator;
use Breakfast\Platform\ClientPreviews\PreviewPathGuard;
use Breakfast\Platform\ClientPreviews\PreviewUrlGenerator;
use PHPUnit\Framework\TestCase;

final class PreviewValidatorTest extends TestCase
{
    private string $dir;

    /** @var array{maxZipMb:int,maxExtractedMb:int,maxFileMb:int,maxFiles:int,maxDepth:int,allowVideo:bool} */
    private array $limits;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pv-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0777, true);
        $this->limits = ['maxZipMb' => 50, 'maxExtractedMb' => 250, 'maxFileMb' => 25, 'maxFiles' => 3000, 'maxDepth' => 20, 'allowVideo' => false];
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $path = $this->dir . '/z-' . bin2hex(random_bytes(3)) . '.zip';
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $body) {
            $z->addFromString($name, $body);
        }
        $z->close();

        return $path;
    }

    private function dest(): string
    {
        $d = $this->dir . '/out-' . bin2hex(random_bytes(3)) . '/files';
        @mkdir($d, 0777, true);

        return $d;
    }

    public function testCleanZipValidates(): void
    {
        $r = (new PreviewArchiveValidator())->validateZip(
            $this->zip(['index.html' => '<title>x</title>', 'a/b/c.css' => 'body{}']),
            $this->dest(),
            $this->limits
        );
        $this->assertTrue($r['ok']);
        $this->assertSame('index.html', $r['entry_file']);
        $this->assertSame(2, $r['file_count']);
    }

    public function testTooManyFiles(): void
    {
        $limits = ['maxFiles' => 1] + $this->limits;
        $r = (new PreviewArchiveValidator())->validateZip(
            $this->zip(['index.html' => 'x', 'b.css' => 'y']),
            $this->dest(),
            $limits
        );
        $this->assertFalse($r['ok']);
        $this->assertContains('too_many_files', $r['errors']);
    }

    public function testDepthLimit(): void
    {
        $limits = ['maxDepth' => 2] + $this->limits;
        $r = (new PreviewArchiveValidator())->validateZip(
            $this->zip(['index.html' => 'x', 'a/b/c/deep.css' => 'y']),
            $this->dest(),
            $limits
        );
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty(array_filter($r['errors'], fn ($e) => str_starts_with($e, 'too_deep')));
    }

    public function testServerConfigRejected(): void
    {
        $r = (new PreviewArchiveValidator())->validateZip(
            $this->zip(['index.html' => 'x', '.htaccess' => 'Options +ExecCGI']),
            $this->dest(),
            $this->limits
        );
        $this->assertFalse($r['ok']);
        $this->assertNotEmpty(array_filter($r['errors'], fn ($e) => str_contains($e, 'forbidden')));
    }

    public function testNoEntryHtmlIsBlocking(): void
    {
        $r = (new PreviewArchiveValidator())->validateZip(
            $this->zip(['styles.css' => 'body{}']),
            $this->dest(),
            $this->limits
        );
        $this->assertFalse($r['ok']);
        $this->assertContains('no_entry_html', $r['errors']);
    }

    public function testWarningsForServiceWorkerTrackerAndSecret(): void
    {
        $r = (new PreviewArchiveValidator())->validateZip(
            $this->zip([
                'index.html' => '<title>x</title><meta name="viewport" content="w"><script src="https://google-analytics.com/ga.js"></script>',
                'sw.js'      => 'self.addEventListener("install",()=>{})',
                'config.js'  => 'const key = "AKIA1234567890ABCDEF";',
            ]),
            $this->dest(),
            $this->limits
        );
        $this->assertTrue($r['ok'], 'warnings do not block');
        $w = implode(' ', $r['warnings']);
        $this->assertStringContainsString('service_worker_present', $w);
        $this->assertStringContainsString('external_script', $w);
        $this->assertStringContainsString('tracker', $w);
        $this->assertStringContainsString('possible_secret', $w);
    }

    public function testPathGuardAllowsAndForbids(): void
    {
        $this->assertTrue(PreviewPathGuard::isAllowedFile('css/app.css'));
        $this->assertTrue(PreviewPathGuard::isAllowedFile('img/logo.png'));
        $this->assertFalse(PreviewPathGuard::isAllowedFile('shell.php'));
        $this->assertFalse(PreviewPathGuard::isAllowedFile('.htaccess'));
        $this->assertFalse(PreviewPathGuard::isAllowedFile('../escape.html'));
        $this->assertFalse(PreviewPathGuard::isAllowedFile('movie.mp4'));
        $this->assertTrue(PreviewPathGuard::isAllowedFile('movie.mp4', true));
        $this->assertSame('text/css; charset=utf-8', PreviewPathGuard::mimeFor('a.css'));
        $this->assertNull(PreviewPathGuard::normaliseRelative('../../etc/passwd'));
        $this->assertNull(PreviewPathGuard::normaliseRelative("a\0b"));
        $this->assertSame('a/b.css', PreviewPathGuard::normaliseRelative('/a/./b.css'));
    }

    public function testUrlGenerator(): void
    {
        $isolated = new PreviewUrlGenerator(['baseUrl' => 'https://preview.example.com', 'host' => 'preview.example.com']);
        $this->assertTrue($isolated->hasIsolatedOrigin());
        $this->assertSame('https://preview.example.com/acme/', $isolated->url('ACME'));
        $this->assertSame('preview.example.com', $isolated->host());

        $dev = new PreviewUrlGenerator(['baseUrl' => '', 'devPrefix' => '_preview']);
        $this->assertFalse($dev->hasIsolatedOrigin());
        $this->assertStringContainsString('/_preview/acme/', $dev->url('acme'));
    }

    private function rrmdir(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
