<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Portfolio\PortfolioSimilarity;
use PHPUnit\Framework\TestCase;

final class PortfolioSimilarityTest extends TestCase
{
    /** @return array<string,mixed> */
    private function localMarkers(): array
    {
        return [
            'hero' => 'editorial', 'ending' => 'motif', 'preset' => 'typography-led',
            'pullquote' => 'marks', 'motif' => 'grid', 'transition' => 'oversized-rule',
            'accent' => '#2f9fd6', 'secondary' => '#1f3d2b', 'bg' => '#fbfbf9',
            'blocks' => ['cs-statement', 'cs-numbered', 'cs-shot', 'cs-numbered', 'cs-annotated',
                'cs-composition', 'cs-strip', 'cs-quote', 'cs-facts', 'cs-colourblock'],
        ];
    }

    /** @return array<string,mixed> */
    private function quirkyGift(): array
    {
        return [
            'hero' => 'collage', 'ending' => 'giant-shot', 'preset' => 'playful-collage',
            'pullquote' => 'rotated', 'motif' => 'sticker', 'transition' => 'overlap',
            'accent' => '#f2545b', 'secondary' => '#432dd7', 'bg' => '#fff2cc',
            'blocks' => ['cs-interlude', 'cs-text', 'cs-stack', 'cs-fullbleed', 'cs-rotated',
                'cs-colourblock', 'cs-quote', 'cs-strip', 'cs-palette', 'cs-specimen'],
        ];
    }

    public function testTheTwoRealCaseStudiesAreStructurallyDistinct(): void
    {
        $cmp = PortfolioSimilarity::compare($this->localMarkers(), $this->quirkyGift());

        // The whole point of the remediation: these must NOT read as the same page.
        $this->assertLessThan(0.2, $cmp['score']);
        $this->assertFalse($cmp['repeatedHero']);
        $this->assertFalse($cmp['repeatedEnding']);
        $this->assertFalse($cmp['repeatedPreset']);
        $this->assertFalse($cmp['repeatedPalette']);
        $this->assertFalse($cmp['repeatedSequence']);
    }

    public function testIdenticalRecordsScorePerfectlySimilar(): void
    {
        $cmp = PortfolioSimilarity::compare($this->localMarkers(), $this->localMarkers());

        $this->assertSame(1.0, $cmp['score']);
        $this->assertTrue($cmp['repeatedHero']);
        $this->assertTrue($cmp['repeatedEnding']);
        $this->assertTrue($cmp['repeatedSequence']);
        $this->assertTrue($cmp['score'] >= PortfolioSimilarity::THRESHOLD);
    }

    public function testSameHeroEndingPaletteAndSequenceTripsTheThreshold(): void
    {
        // The exact failure the site had: one template, different words.
        $a = [
            'hero' => 'editorial', 'ending' => 'visit', 'preset' => 'bold-editorial',
            'pullquote' => 'giant', 'motif' => 'underline', 'transition' => 'oversized-rule',
            'accent' => '#fdc800', 'secondary' => '#432dd7', 'bg' => '#f5efe3',
            'blocks' => ['cs-statement', 'cs-shot', 'cs-quote'],
        ];
        $b = $a;
        $b['blocks'] = ['cs-statement', 'cs-shot', 'cs-quote']; // same sequence, different copy

        $cmp = PortfolioSimilarity::compare($a, $b);
        $this->assertGreaterThanOrEqual(PortfolioSimilarity::THRESHOLD, $cmp['score']);
        $this->assertNotEmpty($cmp['recommendations']);
    }

    public function testAnalyseFindsNearestAndFlagsTooSimilar(): void
    {
        $target = $this->localMarkers();
        $others = [
            'work/quirky' => $this->quirkyGift(),
            'work/clone'  => $this->localMarkers(),
        ];

        $result = PortfolioSimilarity::analyse($target, $others);
        $this->assertSame('work/clone', $result['nearest']);
        $this->assertTrue($result['tooSimilar']);
        $this->assertSame(1.0, $result['score']);
    }

    public function testAnalyseReturnsNoMatchWhenNothingIsClose(): void
    {
        $result = PortfolioSimilarity::analyse($this->localMarkers(), ['work/quirky' => $this->quirkyGift()]);
        $this->assertFalse($result['tooSimilar']);
        $this->assertSame('work/quirky', $result['nearest']); // still the nearest, just not too close
    }

    public function testEmptyRecordsDoNotErrorOrFalselyMatch(): void
    {
        $cmp = PortfolioSimilarity::compare([], []);
        $this->assertSame(0.0, $cmp['score']);
    }
}
