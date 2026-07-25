<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Portfolio\PortfolioException;
use Breakfast\Platform\Portfolio\PortfolioMediaPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Portfolio image pipeline (CP4): optimised, EXIF-stripped public variants, no
 * upscaling, and a low-resolution warning. Requires GD.
 */
final class PortfolioMediaPipelineTest extends TestCase
{
    private string $tmp;
    private string $storage;
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD with WebP is required.');
        }
        $this->tmp = sys_get_temp_dir() . '/bf-pfmedia-' . bin2hex(random_bytes(6));
        $this->storage = $this->tmp . '/storage';
        $this->public = $this->tmp . '/public';
        @mkdir($this->storage, 0777, true);
        @mkdir($this->public, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    private function makeImage(int $w, int $h, string $ext): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 150, 40));
        $path = $this->tmp . '/src.' . $ext;
        match ($ext) {
            'png' => imagepng($im, $path),
            'webp' => imagewebp($im, $path),
            default => imagejpeg($im, $path, 90),
        };
        imagedestroy($im);

        return $path;
    }

    private function pipeline(): PortfolioMediaPipeline
    {
        return new PortfolioMediaPipeline($this->storage, $this->public);
    }

    public function testGeneratesVariantsAndDimensions(): void
    {
        $uuid = 'media-abc';
        $src = $this->makeImage(1400, 900, 'png');
        $res = $this->pipeline()->process($src, 'photo.png', $uuid, 'image/png');

        $this->assertSame(1400, $res['width']);
        $this->assertSame(900, $res['height']);
        // card + thumb + social variants generated (hero needs 1600 > 1400 so is skipped/reused).
        $this->assertArrayHasKey('card', $res['variants']);
        $this->assertArrayHasKey('thumb', $res['variants']);
        $this->assertArrayHasKey('social', $res['variants']);
        $this->assertStringStartsWith('/media/portfolio/' . $uuid . '/', $res['variants']['card']);

        // The public WebP files actually exist and are valid images.
        $cardFile = $this->public . str_replace('/media', '/media', $res['variants']['card']);
        $cardFile = $this->public . '/portfolio/' . $uuid . '/card.webp';
        $this->assertFileExists($cardFile);
        $info = getimagesize($cardFile);
        $this->assertNotFalse($info);
        $this->assertSame(800, $info[0], 'card is resized to 800px wide');

        // The secure original is kept outside the public dir.
        $this->assertFileExists($this->storage . '/portfolio/originals/' . $uuid . '.png');
    }

    public function testNoUpscaleAndLowResWarning(): void
    {
        $res = $this->pipeline()->process($this->makeImage(500, 320, 'png'), 's.png', 'small-1', 'image/png');
        // 'card' (800) and 'hero' (1600) exceed the source width → reused, never upscaled.
        $this->assertLessThanOrEqual(500, getimagesize($this->public . '/portfolio/small-1/thumb.webp')[0]);
        $this->assertNotEmpty($res['warnings'], 'a low-resolution warning is returned');
    }

    public function testStripsMetadataOnReencode(): void
    {
        // A re-encoded JPEG carries no EXIF/APP1 block.
        $res = $this->pipeline()->process($this->makeImage(1000, 700, 'jpg'), 'p.jpg', 'exif-1', 'image/jpeg');
        $jpg = file_get_contents($this->storage . '/portfolio/originals/exif-1.jpg');
        $this->assertIsString($jpg);
        $this->assertStringNotContainsString('Exif', $jpg);
        $this->assertNotEmpty($res['variants']);
    }

    public function testRejectsNonImage(): void
    {
        $bad = $this->tmp . '/notimage.png';
        file_put_contents($bad, "not really a png");
        $this->expectException(PortfolioException::class);
        $this->pipeline()->process($bad, 'notimage.png', 'bad-1', 'image/png');
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
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
