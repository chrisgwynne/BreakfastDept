<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Content\CaseStudyWarnings;
use PHPUnit\Framework\TestCase;

final class CaseStudyWarningsTest extends TestCase
{
    public function testCleanPageProducesNoWarnings(): void
    {
        $images = [
            ['label' => 'shot-home.jpg', 'bytes' => 200_000, 'hasAlt' => true],
            ['label' => 'shot-grid.jpg', 'bytes' => 180_000, 'hasAlt' => true],
        ];
        $this->assertSame([], CaseStudyWarnings::inspect($images, ['cs-shot' => 2]));
    }

    public function testFlagsOversizedImageAndMissingAlt(): void
    {
        $images = [
            ['label' => 'huge.jpg', 'bytes' => 2_000_000, 'hasAlt' => false],
        ];
        $cats = array_column(CaseStudyWarnings::inspect($images, []), 'category');
        $this->assertContains('oversized-image', $cats);
        $this->assertContains('missing-alt', $cats);
    }

    public function testFlagsPerformanceBudget(): void
    {
        $images = array_map(
            static fn (int $i): array => ['label' => "s$i.jpg", 'bytes' => 900_000, 'hasAlt' => true],
            range(1, 8) // 7.2 MB
        );
        $cats = array_column(CaseStudyWarnings::inspect($images, []), 'category');
        $this->assertContains('performance-budget', $cats);
    }

    public function testFlagsTooManyHeavyBlocksAndFullpage(): void
    {
        $counts = ['cs-shot' => 10, 'cs-fullbleed' => 5, 'cs-fullpage' => 4];
        $cats = array_column(CaseStudyWarnings::inspect([], $counts), 'category');
        $this->assertContains('too-many-image-blocks', $cats); // 19 > 16
        $this->assertContains('too-many-fullpage', $cats);     // 4 > 3
    }

    public function testBudgetIsConfigurable(): void
    {
        $images = [['label' => 'a.jpg', 'bytes' => 500_000, 'hasAlt' => true]];
        $this->assertSame([], CaseStudyWarnings::inspect($images, [], 1_000_000));
        $cats = array_column(CaseStudyWarnings::inspect($images, [], 400_000), 'category');
        $this->assertContains('performance-budget', $cats);
    }
}
