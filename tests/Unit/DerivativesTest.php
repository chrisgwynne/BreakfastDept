<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Portfolio\Derivatives;
use Breakfast\Platform\Support\FileStore;
use PHPUnit\Framework\TestCase;

final class DerivativesTest extends TestCase
{
    private string $tmp;
    private Derivatives $svc;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }
        $this->tmp = sys_get_temp_dir() . '/bf-deriv-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp, 0777, true);
        $this->svc = new Derivatives(new FileStore($this->tmp), $this->tmp);
        // A 800×600 source image.
        $im = imagecreatetruecolor(800, 600);
        imagefilledrectangle($im, 0, 0, 800, 600, imagecolorallocate($im, 120, 60, 200));
        $this->source = $this->tmp . '/source.png';
        imagepng($im, $this->source);
        imagedestroy($im);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/**/*') ?: [] as $f) {
            @is_file($f) && @unlink($f);
        }
        parent::tearDown();
    }

    public function testCreateProducesDerivativeFileAndMetadata(): void
    {
        $rec = $this->svc->create([
            'project_uuid' => 'p1',
            'source_path'  => $this->source,
            'kind'         => 'detail',
            'crop'         => ['x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5],
            'creator'      => 'chris',
        ]);

        $this->assertSame('p1', $rec['project_uuid']);
        $this->assertSame('detail', $rec['kind']);
        $this->assertSame(400, $rec['width']);   // 0.5 × 800
        $this->assertSame(300, $rec['height']);  // 0.5 × 600
        $this->assertNotSame('', $rec['source_version']);
        $this->assertFileExists($this->tmp . '/' . $rec['file']);
        // Original untouched.
        $this->assertFileExists($this->source);
    }

    public function testCropIsClampedInsideImage(): void
    {
        $rec = $this->svc->create([
            'project_uuid' => 'p1',
            'source_path'  => $this->source,
            'crop'         => ['x' => 0.8, 'y' => 0.8, 'w' => 0.9, 'h' => 0.9], // spills past edge
        ]);
        // w clamped to ~0.2 of 800 ≈ 160; h to ~0.2 of 600 ≈ 120 (±1 for float).
        $this->assertEqualsWithDelta(160, $rec['width'], 1);
        $this->assertEqualsWithDelta(120, $rec['height'], 1);
    }

    public function testRotate90SwapsDimensions(): void
    {
        $rec = $this->svc->create([
            'project_uuid' => 'p1',
            'source_path'  => $this->source,
            'crop'         => ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 0.5], // 800×300
            'rotate90'     => 90,
        ]);
        $this->assertSame(300, $rec['width']);
        $this->assertSame(800, $rec['height']);
    }

    public function testInvalidCropRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_crop');
        $this->svc->create(['project_uuid' => 'p1', 'source_path' => $this->source, 'crop' => ['x' => 1.0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]);
    }

    public function testSourceChangeMarksDerivativeStaleNotSilentlyInvalid(): void
    {
        $rec = $this->svc->create(['project_uuid' => 'p1', 'source_path' => $this->source, 'crop' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]]);
        $this->assertFalse($this->svc->stale($rec['uuid'], $this->source));

        // Change the source content.
        $im = imagecreatetruecolor(800, 600);
        imagefilledrectangle($im, 0, 0, 800, 600, imagecolorallocate($im, 10, 200, 10));
        imagepng($im, $this->source);
        imagedestroy($im);

        $this->assertTrue($this->svc->stale($rec['uuid'], $this->source), 'changed source flags derivative for review');
        $this->assertTrue($this->svc->get($rec['uuid'])['needs_review']);

        // Reviewing clears the flag and re-anchors the version.
        $this->svc->markReviewed($rec['uuid'], $this->source, 'chris');
        $this->assertFalse($this->svc->stale($rec['uuid'], $this->source));
    }

    public function testUsageTrackingIsIdempotent(): void
    {
        $rec = $this->svc->create(['project_uuid' => 'p1', 'source_path' => $this->source]);
        $this->svc->addUsage($rec['uuid'], 'block-42');
        $after = $this->svc->addUsage($rec['uuid'], 'block-42');
        $this->assertSame(['block-42'], $after['usage']);
    }
}
