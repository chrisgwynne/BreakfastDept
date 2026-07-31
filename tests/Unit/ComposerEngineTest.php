<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Composer\CreativeDirector;
use Breakfast\Platform\Composer\LayoutIdeas;
use Breakfast\Platform\Composer\RhythmAnalysis;
use Breakfast\Platform\Composer\StarterComposition;
use Breakfast\Platform\Composer\StoryHealth;
use Breakfast\Platform\Content\ArtDirection;
use Breakfast\Platform\Content\ArtDirectionQualityGate;
use Breakfast\Platform\Portfolio\PortfolioSimilarity;
use PHPUnit\Framework\TestCase;

final class ComposerEngineTest extends TestCase
{
    private const APPROVED = [
        'cs-statement', 'cs-text', 'cs-numbered', 'cs-interlude', 'cs-quote', 'cs-facts',
        'cs-shot', 'cs-rotated', 'cs-stack', 'cs-devices', 'cs-fullpage', 'cs-fullbleed',
        'cs-strip', 'cs-beforeafter', 'cs-annotated', 'cs-sticky', 'cs-composition',
        'cs-colourblock', 'cs-specimen', 'cs-palette',
    ];

    // ---- Layout ideas ----

    public function testGeneratesAtLeastThreeDistinctIdeas(): void
    {
        $ideas = LayoutIdeas::generate(['imageCount' => 5], 4);
        $this->assertGreaterThanOrEqual(3, count($ideas));

        // Every idea uses only approved blocks and a valid art direction.
        foreach ($ideas as $idea) {
            foreach ($idea['blocks'] as $t) {
                $this->assertContains($t, self::APPROVED, "unexpected block $t");
            }
            $this->assertTrue(LayoutIdeas::validDirection($idea['preset'], $idea['hero'], $idea['ending']));
            $this->assertGreaterThanOrEqual(2, $idea['visualTypes']);
        }

        // The ideas are genuinely different from one another (not one template).
        for ($i = 0; $i < count($ideas); $i++) {
            for ($j = $i + 1; $j < count($ideas); $j++) {
                $a = ['hero' => $ideas[$i]['hero'], 'ending' => $ideas[$i]['ending'], 'preset' => $ideas[$i]['preset'], 'blocks' => $ideas[$i]['blocks']];
                $b = ['hero' => $ideas[$j]['hero'], 'ending' => $ideas[$j]['ending'], 'preset' => $ideas[$j]['preset'], 'blocks' => $ideas[$j]['blocks']];
                $this->assertLessThan(PortfolioSimilarity::THRESHOLD, PortfolioSimilarity::compare($a, $b)['score'], "ideas {$ideas[$i]['key']} and {$ideas[$j]['key']} are too alike");
            }
        }
    }

    public function testIdeasDegradeGracefullyWithFewImages(): void
    {
        // With no images, no idea may demand a multi-image block it can't fill.
        $ideas = LayoutIdeas::generate(['imageCount' => 0], 5);
        $this->assertGreaterThanOrEqual(3, count($ideas));
        foreach ($ideas as $idea) {
            $this->assertNotContains('cs-strip', $idea['blocks']);
            $this->assertNotContains('cs-stack', $idea['blocks']);
            $this->assertNotContains('cs-devices', $idea['blocks']);
            $this->assertNotContains('cs-composition', $idea['blocks']);
        }
    }

    public function testEveryIdeaWouldClearTheStructuralGate(): void
    {
        $ideas = LayoutIdeas::generate(['imageCount' => 6], 6);
        foreach ($ideas as $idea) {
            $gate = ArtDirectionQualityGate::evaluate([
                'caseMode' => 'art-directed', 'blocks' => $idea['blocks'],
                'imageCount' => 6, 'presetCustomised' => true, 'hasScreenshots' => true, 'ineffectiveBlocks' => [],
            ]);
            $this->assertTrue($gate['passed'], "idea {$idea['key']} would not pass the gate: " . implode('; ', $gate['blockers']));
        }
    }

    public function testInspirationsMapToRealConcepts(): void
    {
        $keys = array_column(LayoutIdeas::inspirations(), 'concept');
        foreach ($keys as $c) {
            $this->assertNotEmpty(LayoutIdeas::generate(['imageCount' => 5]), "inspiration concept $c produced no ideas");
        }
    }

    // ---- Rhythm ----

    public function testRhythmFlagsConsecutiveTextAndImageWall(): void
    {
        $r = RhythmAnalysis::analyse(['cs-text', 'cs-text', 'cs-numbered']);
        $codes = array_column($r['flags'], 'code');
        $this->assertContains('consecutive-text', $codes);
        $this->assertContains('text-heavy', $codes);

        $r2 = RhythmAnalysis::analyse(['cs-shot', 'cs-fullbleed', 'cs-strip', 'cs-composition']);
        $this->assertContains('image-wall', array_column($r2['flags'], 'code'));
    }

    public function testBalancedPageScoresWellAndHasNoPacingFlags(): void
    {
        $r = RhythmAnalysis::analyse(['cs-statement', 'cs-shot', 'cs-quote', 'cs-strip', 'cs-facts', 'cs-colourblock']);
        $this->assertGreaterThan(60, $r['score']);
        $this->assertSame([], $r['flags']);
    }

    // ---- Creative director ----

    public function testDirectorGivesSpecificNotesNotGenericAdvice(): void
    {
        $notes = CreativeDirector::review([
            'blocks' => ['cs-text', 'cs-text', 'cs-shot'],
            'imageCount' => 1, 'ending' => 'visit', 'hasScreenshots' => false,
        ]);
        $codes = array_column($notes, 'code');
        $this->assertContains('consecutive-text', $codes);
        $this->assertContains('one-image', $codes);
        $this->assertContains('weak-ending', $codes);
        $this->assertContains('no-quote', $codes);
        // Every note references an action.
        foreach ($notes as $note) {
            $this->assertNotSame('', $note['action']);
        }
    }

    public function testDirectorDetectsSimilarityToPublishedWork(): void
    {
        $rec = ['blocks' => ['cs-statement', 'cs-shot', 'cs-quote'], 'imageCount' => 3, 'ending' => 'motif',
            'hero' => 'editorial', 'preset' => 'bold-editorial', 'accent' => '#fdc800', 'secondary' => '#432dd7', 'bg' => '#f5efe3', 'hasScreenshots' => true];
        $others = ['work/twin' => $rec];
        $notes = CreativeDirector::review($rec, $others);
        $codes = array_column($notes, 'code');
        $this->assertContains('similar-to-published', $codes);
        $msg = implode(' ', array_column($notes, 'message'));
        $this->assertStringContainsString('work/twin', $msg);
        $this->assertMatchesRegularExpression('/\d+%/', $msg);
    }

    public function testWellBuiltPageDrawsNoHighSeverityNotes(): void
    {
        $rec = ['blocks' => ['cs-statement', 'cs-shot', 'cs-annotated', 'cs-strip', 'cs-quote', 'cs-facts', 'cs-colourblock'],
            'imageCount' => 6, 'ending' => 'results', 'hero' => 'editorial', 'preset' => 'typography-led',
            'accent' => '#2f9fd6', 'secondary' => '#1f3d2b', 'bg' => '#fbfbf9', 'hasScreenshots' => true];
        $high = array_filter(CreativeDirector::review($rec, []), static fn ($n) => $n['severity'] === 'high');
        $this->assertSame([], array_values($high));
    }

    // ---- Story health + uniqueness ----

    public function testStoryHealthScoresWithReasons(): void
    {
        $h = StoryHealth::score([
            'blocks' => ['cs-statement', 'cs-shot', 'cs-annotated', 'cs-quote', 'cs-facts'],
            'imageCount' => 5, 'ending' => 'results',
        ]);
        $this->assertGreaterThan(0, $h['overall']);
        $this->assertLessThanOrEqual(100, $h['overall']);
        foreach ($h['metrics'] as $m) {
            $this->assertNotSame('', $m['reason']);
            $this->assertGreaterThanOrEqual(0, $m['score']);
        }
    }

    public function testUniquenessIsHundredAloneAndLowVersusClone(): void
    {
        $rec = ['blocks' => ['cs-statement', 'cs-shot', 'cs-quote'], 'ending' => 'motif', 'hero' => 'editorial',
            'preset' => 'bold-editorial', 'accent' => '#fdc800', 'secondary' => '#432dd7', 'bg' => '#f5efe3'];
        $this->assertSame(100, StoryHealth::uniqueness($rec, [])['overall']);

        $u = StoryHealth::uniqueness($rec, ['work/clone' => $rec]);
        $this->assertLessThan(40, $u['overall']);
        $this->assertSame('work/clone', $u['nearest']);
    }

    // ---- Starter composition (make beautiful the default) ----

    public function testStarterCompositionIsRichAndArtDirected(): void
    {
        $starter = StarterComposition::build();
        $types = array_column($starter['blocks'], 'type');

        $this->assertGreaterThanOrEqual(5, count($types));
        // Structural richness: >=2 visual types, a crafted treatment, a voice block.
        $visual = array_unique(array_intersect($types, \Breakfast\Platform\Composer\BlockTaxonomy::VISUAL));
        $this->assertGreaterThanOrEqual(2, count($visual));
        $this->assertNotEmpty(array_intersect($types, \Breakfast\Platform\Composer\BlockTaxonomy::RICH));
        $this->assertNotEmpty(array_intersect($types, \Breakfast\Platform\Composer\BlockTaxonomy::VOICE));

        // Valid, non-default-timid art direction.
        $ad = ArtDirection::resolve([
            'preset' => str_replace('ad_', '', $starter['ad']['ad_preset'] ?? ''),
        ]);
        $this->assertContains($starter['ad']['ad_preset'], ArtDirection::PRESETS);
        $this->assertContains($starter['ad']['ad_hero'], ArtDirection::HEROES);
        $this->assertContains($starter['ad']['ad_ending'], ArtDirection::ENDINGS);

        // No forbidden placeholder words that content:audit would reject.
        $json = json_encode($starter['blocks']);
        foreach (['placeholder', 'lorem ipsum', 'illustrative'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, (string) $json);
        }
    }
}
