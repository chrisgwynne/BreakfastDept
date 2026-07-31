<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Content\ArtDirection;
use PHPUnit\Framework\TestCase;

final class ArtDirectionTest extends TestCase
{
    public function testEmptyFieldsResolveToSafeDefaults(): void
    {
        $r = ArtDirection::resolve([]);

        $this->assertSame('story-driven', $r['preset']);
        $this->assertSame('#f5efe3', $r['vars']['--ad-bg']);       // cream
        $this->assertSame('#1c293c', $r['vars']['--ad-text']);     // ink
        $this->assertSame('minimal', $r['data']['hero']);          // story-driven preset seeds a minimal hero
        $this->assertContains($r['data']['pullquote'], ArtDirection::PULLQUOTES);
    }

    public function testPresetSeedsCoherentLook(): void
    {
        $r = ArtDirection::resolve(['preset' => 'playful-collage']);

        $this->assertSame('playful-collage', $r['preset']);
        $this->assertSame('collage', $r['data']['hero']);
        $this->assertSame('rotated', $r['data']['pullquote']);
        $this->assertSame('playful-stagger', $r['data']['anim']);
        $this->assertSame('#f2545b', $r['vars']['--ad-accent']);   // coral
    }

    public function testExplicitFieldsOverridePreset(): void
    {
        $r = ArtDirection::resolve([
            'preset' => 'quiet-premium',   // seeds hero=minimal
            'hero'   => 'cinematic',       // explicit override wins
            'accent' => 'terracotta',
        ]);

        $this->assertSame('cinematic', $r['data']['hero']);
        $this->assertSame('#c1584b', $r['vars']['--ad-accent']);
    }

    public function testUnknownValuesFallBackToDefaultsNeverLeak(): void
    {
        $r = ArtDirection::resolve([
            'accent'    => 'red; background:url(javascript:alert(1))',
            'hero'      => '../../etc/passwd',
            'rotation'  => '999deg',
            'preset'    => 'not-a-preset',
        ]);

        // Nothing untrusted survives: colour falls back to a palette hex, hero to
        // a whitelisted variant, rotation to a bounded degree.
        $this->assertSame('#fdc800', $r['vars']['--ad-accent']);   // yellow default
        $this->assertContains($r['data']['hero'], ArtDirection::HEROES);
        $this->assertSame('3deg', $r['vars']['--ad-rot']);          // 'subtle' default
        $this->assertSame('story-driven', $r['preset']);
    }

    public function testRotationIsBoundedToEightDegrees(): void
    {
        foreach (['none' => '0deg', 'subtle' => '3deg', 'playful' => '6deg', 'wild' => '8deg'] as $key => $deg) {
            $r = ArtDirection::resolve(['rotation' => $key]);
            $this->assertSame($deg, $r['vars']['--ad-rot']);
        }
    }

    public function testOnAccentPicksReadableForeground(): void
    {
        // Dark accent → white text; light accent → ink text.
        $dark = ArtDirection::resolve(['accent' => 'midnight']);
        $light = ArtDirection::resolve(['accent' => 'yellow']);

        $this->assertSame('#ffffff', $dark['vars']['--ad-on-accent']);
        $this->assertSame('#1c293c', $light['vars']['--ad-on-accent']);
    }

    public function testStyleAttrEmitsOnlyWhitelistedCustomProperties(): void
    {
        $css = ArtDirection::styleAttr([
            '--ad-accent'   => '#fdc800',
            '--ad-bad;evil' => 'red',                 // bad property name → dropped
            '--ad-x'        => 'url("x") ; danger',   // illegal chars → dropped
            '--ad-rot'      => '6deg',
        ]);

        $this->assertStringContainsString('--ad-accent:#fdc800', $css);
        $this->assertStringContainsString('--ad-rot:6deg', $css);
        $this->assertStringNotContainsString('evil', $css);
        $this->assertStringNotContainsString('danger', $css);
        $this->assertStringNotContainsString('url(', $css);
    }

    public function testDataAttrsAreSlugSafe(): void
    {
        $attrs = ArtDirection::dataAttrs([
            'hero'   => 'layered-devices',
            'bad key' => 'x',           // invalid key → dropped
            'anim'   => 'mask-reveal',
            'evil'   => 'a" onload="x', // invalid value → dropped
        ]);

        $this->assertStringContainsString('data-ad-hero="layered-devices"', $attrs);
        $this->assertStringContainsString('data-ad-anim="mask-reveal"', $attrs);
        $this->assertStringNotContainsString('onload', $attrs);
        $this->assertStringNotContainsString('bad key', $attrs);
    }

    public function testEveryPresetResolvesToWhitelistedTokens(): void
    {
        foreach (ArtDirection::PRESETS as $preset) {
            $r = ArtDirection::resolve(['preset' => $preset]);
            $this->assertContains($r['data']['hero'], ArtDirection::HEROES, $preset);
            $this->assertContains($r['data']['anim'], ArtDirection::ANIMATIONS, $preset);
            $this->assertContains($r['data']['transition'], ArtDirection::TRANSITIONS, $preset);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $r['vars']['--ad-accent'], $preset);
        }
    }
}
