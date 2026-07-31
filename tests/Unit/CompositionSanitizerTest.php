<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Composition\CompositionSanitizer as C;
use PHPUnit\Framework\TestCase;

final class CompositionSanitizerTest extends TestCase
{
    public function testDropsUnknownLayerTypesAndFields(): void
    {
        $out = C::sanitize([
            'layers' => [
                ['id' => 'a', 'type' => 'desktop-shot', 'image' => 'shot.jpg'],
                ['id' => 'b', 'type' => 'evil', 'onclick' => 'alert(1)'],   // bad type → dropped
                ['type' => 'crop'],                                         // no id → dropped
            ],
        ]);
        $this->assertCount(1, $out['layers']);
        $this->assertSame('a', $out['layers'][0]['id']);
        $this->assertArrayNotHasKey('onclick', $out['layers'][0]);
    }

    public function testClampsGridScaleRotationOpacity(): void
    {
        $l = C::sanitize([
            'layers' => [[
                'id' => 'x', 'type' => 'desktop-shot',
                'col' => 99, 'colSpan' => 99, 'row' => -5, 'rowSpan' => 999,
                'scale' => 9.9, 'rotation' => 45, 'opacity' => 5,
            ]],
        ])['layers'][0];

        $this->assertSame(12, $l['col']);                 // clamped to grid
        $this->assertSame(1, $l['colSpan']);              // col 12 → span max 1
        $this->assertSame(1, $l['row']);
        $this->assertLessThanOrEqual(8, $l['row'] + $l['rowSpan'] - 1);
        $this->assertSame(1.4, $l['scale']);              // clamp max
        $this->assertContains($l['rotation'], C::ROTATIONS); // snapped to allowed
        $this->assertSame(1.0, $l['opacity']);            // clamp max
    }

    public function testRotationSnapsToNearestAllowed(): void
    {
        $rot = fn (int $r) => C::sanitize(['layers' => [['id' => 'x', 'type' => 'shape', 'rotation' => $r]]])['layers'][0]['rotation'];
        $this->assertSame(3, $rot(2));
        $this->assertSame(5, $rot(6));
        $this->assertSame(8, $rot(100));
        $this->assertSame(-5, $rot(-4));
        $this->assertSame(0, $rot(1));
    }

    public function testLayerCap(): void
    {
        $layers = [];
        for ($i = 0; $i < 30; $i++) {
            $layers[] = ['id' => 'l' . $i, 'type' => 'desktop-shot'];
        }
        $this->assertCount(C::MAX_LAYERS, C::sanitize(['layers' => $layers])['layers']);
    }

    public function testTextAndAssetAreSanitised(): void
    {
        $l = C::sanitize(['layers' => [[
            'id' => 'x', 'type' => 'caption',
            'text' => "<script>alert(1)</script>  hello   world",
            'image' => '../../etc/passwd;rm -rf',
        ]]])['layers'][0];

        $this->assertStringNotContainsString('<script>', $l['text']);
        $this->assertSame('alert(1) hello world', $l['text']);
        $this->assertSame('....etcpasswdrm-rf', $l['image']); // path/space/; stripped
    }

    public function testResponsiveOverridesOnlyReferenceExistingLayers(): void
    {
        $out = C::sanitize([
            'layers' => [['id' => 'a', 'type' => 'desktop-shot']],
            'responsive' => [
                'mobile' => [
                    'a' => ['scale' => 0.6, 'hidden' => true, 'evil' => 'x'],
                    'ghost' => ['scale' => 1.0],   // no such layer → dropped
                ],
                'weird' => ['a' => ['scale' => 1]], // unknown breakpoint → dropped
            ],
        ]);
        $this->assertArrayHasKey('a', $out['responsive']['mobile']);
        $this->assertArrayNotHasKey('ghost', $out['responsive']['mobile']);
        $this->assertArrayNotHasKey('weird', $out['responsive']);
        $this->assertSame(0.6, $out['responsive']['mobile']['a']['scale']);
        $this->assertTrue($out['responsive']['mobile']['a']['hidden']);
        $this->assertArrayNotHasKey('evil', $out['responsive']['mobile']['a']);
    }

    public function testMobileOrderKeepsOnlyKnownLayers(): void
    {
        $out = C::sanitize([
            'layers' => [['id' => 'a', 'type' => 'desktop-shot'], ['id' => 'b', 'type' => 'mobile-shot']],
            'mobileOrder' => ['b', 'a', 'ghost', 'b'],
        ]);
        $this->assertSame(['b', 'a'], $out['mobileOrder']);
    }

    public function testLayerStyleEmitsOnlySafeProperties(): void
    {
        $l = C::sanitize(['layers' => [['id' => 'x', 'type' => 'desktop-shot', 'col' => 2, 'colSpan' => 4, 'row' => 1, 'rowSpan' => 3, 'scale' => 1.1, 'rotation' => 5, 'opacity' => 0.8]]])['layers'][0];
        $style = C::layerStyle($l);
        $this->assertStringContainsString('grid-column:2 / span 4', $style);
        $this->assertStringContainsString('--l-rot:5deg', $style);
        $this->assertStringContainsString('--l-scale:1.10', $style);
        $this->assertStringNotContainsString('script', $style);
        // No markup-breaking chars and no CSS escape hatches.
        $this->assertDoesNotMatchRegularExpression('/[<>"]/', $style);
        $this->assertStringNotContainsString('url(', $style);
        $this->assertStringNotContainsString('expression', $style);
    }

    public function testDuplicateLayerIdsAreDropped(): void
    {
        $out = C::sanitize(['layers' => [
            ['id' => 'a', 'type' => 'desktop-shot'],
            ['id' => 'a', 'type' => 'mobile-shot'],
        ]]);
        $this->assertCount(1, $out['layers']);
    }
}
