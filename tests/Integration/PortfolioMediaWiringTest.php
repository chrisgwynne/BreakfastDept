<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use PHPUnit\Framework\TestCase;

/**
 * Guards the portfolio media wiring: the generated public variant URLs
 * (/media/portfolio/...) must resolve to files that actually exist under the
 * public document root. A regression here (writing to public/portfolio while
 * advertising /media/portfolio) publishes case studies with broken cover
 * images, so it's worth pinning.
 */
final class PortfolioMediaWiringTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD with WebP is required.');
        }
        $this->tmp = sys_get_temp_dir() . '/bf-pfwire-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        @mkdir($this->tmp . '/public', 0777, true);
        Database::reset();
        Platform::reset();
    }

    protected function tearDown(): void
    {
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    public function testGeneratedVariantUrlResolvesToARealPublicFile(): void
    {
        $platform = Platform::boot($this->tmp, [
            'storageDir' => $this->tmp,
            'publicDir'  => $this->tmp . '/public',
            'dbPath'     => $this->tmp . '/database/crm.sqlite',
            'production' => false,
        ]);

        // A small source image on disk.
        $im = imagecreatetruecolor(1400, 875);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 60, 90));
        $src = $this->tmp . '/src.png';
        imagepng($im, $src);
        imagedestroy($im);

        $res = $platform->portfolioMedia()->process($src, 'src.png', 'wire-1', 'image/png');
        $url = $res['variants']['card'] ?? '';
        $this->assertStringStartsWith('/media/portfolio/', $url);

        // The URL, resolved under the public root, must be a real file.
        $file = $this->tmp . '/public' . $url;
        $this->assertFileExists($file, 'variant URL must map to a file under the public root');
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
