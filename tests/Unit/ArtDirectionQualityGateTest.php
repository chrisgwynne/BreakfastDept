<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Content\ArtDirectionQualityGate;
use PHPUnit\Framework\TestCase;

final class ArtDirectionQualityGateTest extends TestCase
{
    /** @return array<string,mixed> */
    private function richRecord(): array
    {
        return [
            'caseMode' => 'art-directed',
            'blocks' => ['cs-statement', 'cs-shot', 'cs-annotated', 'cs-strip', 'cs-quote', 'cs-composition'],
            'imageCount' => 6,
            'presetCustomised' => true,
            'hasScreenshots' => true,
            'ineffectiveBlocks' => [],
        ];
    }

    public function testRichArtDirectedRecordPasses(): void
    {
        $gate = ArtDirectionQualityGate::evaluate($this->richRecord());
        $this->assertTrue($gate['passed']);
        $this->assertSame([], $gate['blockers']);
        $this->assertSame('art-directed', $gate['mode']);
    }

    public function testSparseOneImageRecordIsBlocked(): void
    {
        // This is exactly the state the two live pages were in.
        $gate = ArtDirectionQualityGate::evaluate([
            'caseMode' => 'art-directed',
            'blocks' => ['cs-shot'],
            'imageCount' => 1,
            'presetCustomised' => false,
            'hasScreenshots' => false,
            'ineffectiveBlocks' => [],
        ]);

        $this->assertFalse($gate['passed']);
        // Too few blocks, too few visual types, too few images, no crafted
        // treatment, no statement.
        $this->assertGreaterThanOrEqual(5, count($gate['blockers']));
    }

    public function testRecordWithNoBodyIsBlocked(): void
    {
        $gate = ArtDirectionQualityGate::evaluate([
            'caseMode' => 'art-directed', 'blocks' => [], 'imageCount' => 1,
        ]);
        $this->assertFalse($gate['passed']);
    }

    public function testPlainImagesOnlyIsBlockedForLackingCraftedTreatment(): void
    {
        // Three blocks, two images, but only plain full-width pictures + text.
        $gate = ArtDirectionQualityGate::evaluate([
            'caseMode' => 'art-directed',
            'blocks' => ['cs-statement', 'cs-shot', 'cs-fullbleed'],
            'imageCount' => 2,
            'presetCustomised' => true,
            'hasScreenshots' => true,
            'ineffectiveBlocks' => [],
        ]);
        $this->assertFalse($gate['passed']);
        $blockerText = implode(' ', $gate['blockers']);
        $this->assertStringContainsString('crafted treatment', $blockerText);
    }

    public function testSimpleModeDowngradesBlockersToWarnings(): void
    {
        $gate = ArtDirectionQualityGate::evaluate([
            'caseMode' => 'simple',
            'blocks' => ['cs-shot'],
            'imageCount' => 1,
            'presetCustomised' => false,
            'hasScreenshots' => false,
            'ineffectiveBlocks' => [],
        ]);

        // A deliberately simple case study is never blocked, only advised.
        $this->assertTrue($gate['passed']);
        $this->assertSame([], $gate['blockers']);
        $this->assertNotEmpty($gate['warnings']);
        $this->assertSame('simple', $gate['mode']);
    }

    public function testIneffectiveBlocksBecomeBlockers(): void
    {
        $gate = ArtDirectionQualityGate::evaluate([
            'caseMode' => 'art-directed',
            'blocks' => ['cs-statement', 'cs-shot', 'cs-annotated', 'cs-strip'],
            'imageCount' => 3,
            'presetCustomised' => true,
            'hasScreenshots' => true,
            'ineffectiveBlocks' => [
                ['index' => 2, 'type' => 'cs-annotated', 'reason' => 'has no image'],
            ],
        ]);
        $this->assertFalse($gate['passed']);
        $this->assertStringContainsString('cs-annotated', implode(' ', $gate['blockers']));
    }

    public function testIneffectiveBlockDetection(): void
    {
        $flagged = ArtDirectionQualityGate::ineffectiveBlocks([
            ['type' => 'cs-shot', 'hasImage' => false, 'imageCount' => 0, 'hasText' => false, 'hiddenEverywhere' => false],
            ['type' => 'cs-statement', 'hasImage' => false, 'imageCount' => 0, 'hasText' => true, 'hiddenEverywhere' => false],
            ['type' => 'cs-stack', 'hasImage' => true, 'imageCount' => 1, 'hasText' => false, 'hiddenEverywhere' => false],
            ['type' => 'cs-quote', 'hasImage' => false, 'imageCount' => 0, 'hasText' => false, 'hiddenEverywhere' => false],
            ['type' => 'cs-shot', 'hasImage' => true, 'imageCount' => 1, 'hasText' => false, 'hiddenEverywhere' => true],
        ]);

        $reasons = array_column($flagged, 'reason', 'index');
        $this->assertSame('has no image', $reasons[0]);            // cs-shot, no image
        $this->assertArrayNotHasKey(1, $reasons);                  // cs-statement with text is fine
        $this->assertSame('needs at least two images', $reasons[2]); // cs-stack with 1
        $this->assertSame('has no text', $reasons[3]);             // cs-quote empty
        $this->assertSame('is hidden at every breakpoint', $reasons[4]); // hidden everywhere
    }
}
