<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\ContentAudit;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The content audit must catch placeholder text and false positioning as
 * errors, flag not-yet-supplied details as warnings only, and pass clean
 * content with zero errors (so `--strict` is a safe release gate).
 */
final class ContentAuditTest extends TestCase
{
    protected function tearDown(): void
    {
        // Kirby registers Whoops error/exception handlers on boot; balance the
        // handler stacks so PHPUnit doesn't flag the test as risky.
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    /**
     * @param array<string,string> $siteContent
     * @param list<array<string,mixed>> $children
     * @return list<array{severity:string,category:string,where:string,message:string}>
     */
    private function audit(array $siteContent, array $children): array
    {
        $index = sys_get_temp_dir() . '/bf-audit-' . bin2hex(random_bytes(5));
        @mkdir($index, 0777, true);

        $app = new App([
            'roots'   => ['index' => $index],
            'options' => ['debug' => false, 'whoops' => false],
            'site'    => [
                'content'  => array_merge(['title' => 'Breakfast', 'country' => 'Wales'], $siteContent),
                'children' => $children,
            ],
        ]);

        return (new ContentAudit($app))->run();
    }

    /**
     * @param list<array{severity:string,category:string,where:string,message:string}> $findings
     */
    private function errorCategories(array $findings): array
    {
        return array_values(array_map(
            static fn (array $f): string => $f['category'],
            array_filter($findings, static fn (array $f): bool => $f['severity'] === ContentAudit::ERROR)
        ));
    }

    public function testCleanContentHasNoErrors(): void
    {
        $findings = $this->audit(
            ['owner_name' => 'Sam', 'base_town' => 'Rhyl', 'phone' => '01745 000000'],
            [[
                'slug'     => 'good',
                'template' => 'project',
                'num'      => 1,
                'content'  => [
                    'title'          => 'A good concept',
                    'project_status' => 'concept',
                    // A deliberately minimal write-up, so the art-direction
                    // quality gate advises rather than blocks.
                    'case_mode'      => 'simple',
                    'summary'        => 'A clean, honest summary of the concept.',
                    'hero_image'     => 'shot.jpg',
                    'seo_title'      => 'A good concept',
                ],
            ]],
        );

        $this->assertSame([], $this->errorCategories($findings));
    }

    public function testPlaceholderTextIsAnError(): void
    {
        $findings = $this->audit([], [[
            'slug'     => 'bad',
            'template' => 'project',
            'num'      => 1,
            'content'  => [
                'title'          => 'Bad',
                'project_status' => 'concept',
                'summary'        => 'Quote from a Placeholder client here.',
                'hero_image'     => 'x.jpg',
            ],
        ]]);

        $this->assertContains('placeholder-text', $this->errorCategories($findings));
    }

    public function testBritishPositioningIsAnError(): void
    {
        $findings = $this->audit(['legal_name' => 'A small British studio'], []);

        $this->assertContains('false-positioning', $this->errorCategories($findings));
    }

    public function testProjectMissingStatusIsAnError(): void
    {
        $findings = $this->audit([], [[
            'slug'     => 'nostatus',
            'template' => 'project',
            'num'      => 1,
            'content'  => ['title' => 'No status', 'summary' => 'Has a summary but no status.', 'hero_image' => 'x.jpg'],
        ]]);

        $this->assertContains('incomplete-project', $this->errorCategories($findings));
    }

    public function testMissingBusinessDetailsAreWarningsNotErrors(): void
    {
        $findings = $this->audit([], []);

        $this->assertSame([], $this->errorCategories($findings), 'unconfigured details must not be errors');
        $warnings = array_filter($findings, static fn (array $f): bool => $f['severity'] === ContentAudit::WARNING);
        $this->assertNotEmpty($warnings);
    }
}
