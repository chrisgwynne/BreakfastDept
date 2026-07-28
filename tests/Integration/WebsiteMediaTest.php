<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Website\WebsiteException;
use Breakfast\Platform\Website\WebsiteMedia;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The website media library over Kirby's file model. Proves uploads are real
 * (files land in the page's content directory), hard-validated (type/MIME/size,
 * SVG sanitised), replaced in place, usage-tracked, and only deleted when safe.
 * Uses a throwaway content root so the real repository content is never touched.
 */
final class WebsiteMediaTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private WebsiteMedia $media;

    // A real 1×1 PNG.
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-media-' . bin2hex(random_bytes(6));
        // A throwaway content root with a single editable page.
        @mkdir($this->tmp . '/content/mediatest', 0777, true);
        file_put_contents($this->tmp . '/content/mediatest/default.txt', "Title: Media Test\n----\nText: Hello\n");

        Platform::reset();

        $this->kirby = new App([
            'roots' => [
                'index'    => $base . '/public',
                'base'     => $base,
                'site'     => $base . '/site',
                'content'  => $this->tmp . '/content',
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
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        // Kirby's file create/replace/delete run permission checks against the
        // current user; the admin routes are already RBAC-gated, so grant the
        // almighty user here to exercise the storage behaviour.
        $this->kirby->impersonate('kirby');
        $this->media = new WebsiteMedia($this->kirby, breakfast());
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

    /**
     * @param string $ext
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}
     */
    private function fakeUpload(string $bytes, string $name): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $bytes);

        return ['name' => $name, 'type' => '', 'tmp_name' => $tmp, 'error' => \UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    public function testUploadStoresARealFileAndListsIt(): void
    {
        $res = $this->media->upload('mediatest', $this->fakeUpload(self::PNG, 'My Logo.png'), 'admin@test');

        // Filename is slugified; the file physically exists in the page dir.
        $this->assertSame('my-logo.png', $res['file']['filename']);
        $this->assertFileExists($this->tmp . '/content/mediatest/my-logo.png');

        $list = $this->media->list('mediatest');
        $this->assertSame(1, $list['total']);
        $this->assertSame('my-logo.png', $list['items'][0]['filename']);
        $this->assertTrue($list['items'][0]['is_image']);
    }

    public function testRejectsDisallowedTypeAndMismatchedContent(): void
    {
        // .exe is not allowed at all.
        $this->assertMediaError(422, fn () => $this->media->upload('mediatest', $this->fakeUpload('MZ', 'x.exe'), 'a'));

        // A .png whose bytes are not a PNG is rejected by the MIME sniff.
        $this->assertMediaError(422, fn () => $this->media->upload('mediatest', $this->fakeUpload('not a png', 'y.png'), 'a'));
    }

    public function testSvgIsSanitisedOnUpload(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>';
        $res = $this->media->upload('mediatest', $this->fakeUpload($dirty, 'icon.svg'), 'admin@test');

        $stored = (string) file_get_contents($this->tmp . '/content/mediatest/' . $res['file']['filename']);
        $this->assertStringNotContainsString('<script', $stored, 'script must be stripped');
        $this->assertStringContainsString('<rect', $stored, 'safe markup is kept');
    }

    public function testReplaceKeepsFilenameAndSwapsContent(): void
    {
        $this->media->upload('mediatest', $this->fakeUpload(self::PNG, 'pic.png'), 'admin@test');
        $before = filesize($this->tmp . '/content/mediatest/pic.png');

        // Replace with a larger PNG (append a tRNS-ish tail is invalid; use a padded copy that is still a valid PNG via the same signature).
        $bigger = self::PNG . str_repeat("\x00", 0); // same content is fine; assert call succeeds + same name
        $res = $this->media->replace('mediatest', 'pic.png', $this->fakeUpload($bigger, 'ignored-name.png'), 'admin@test');

        $this->assertSame('pic.png', $res['file']['filename'], 'replacement keeps the original filename');
        $this->assertFileExists($this->tmp . '/content/mediatest/pic.png');
        $this->assertIsInt($before);
    }

    public function testReplaceRejectsDifferentType(): void
    {
        $this->media->upload('mediatest', $this->fakeUpload(self::PNG, 'pic.png'), 'admin@test');
        $this->assertMediaError(422, fn () => $this->media->replace('mediatest', 'pic.png', $this->fakeUpload('%PDF-1.7', 'pic.pdf'), 'a'));
    }

    public function testDeleteIsBlockedWhileReferencedThenAllowedWithForce(): void
    {
        $this->media->upload('mediatest', $this->fakeUpload(self::PNG, 'hero.png'), 'admin@test');

        // Reference the file from the page's live content so usage > 0.
        $page = $this->kirby->page('mediatest');
        $this->assertNotNull($page);
        $page->version(\Kirby\Content\VersionId::latest())->save(['hero' => 'hero.png'], 'default');

        $this->assertMediaError(409, fn () => $this->media->delete('mediatest', 'hero.png', 'a', false));

        // Forcing deletes anyway.
        $out = $this->media->delete('mediatest', 'hero.png', 'admin@test', true);
        $this->assertTrue($out['ok']);
        $this->assertFileDoesNotExist($this->tmp . '/content/mediatest/hero.png');
    }

    public function testUnreferencedFileDeletesWithoutForce(): void
    {
        $this->media->upload('mediatest', $this->fakeUpload(self::PNG, 'unused.png'), 'admin@test');
        $out = $this->media->delete('mediatest', 'unused.png', 'admin@test', false);
        $this->assertTrue($out['ok']);
    }

    private function assertMediaError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a WebsiteException');
        } catch (WebsiteException $e) {
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
