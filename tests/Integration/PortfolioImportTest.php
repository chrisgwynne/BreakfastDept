<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Crm\SearchService;
use Breakfast\Platform\Portfolio\PortfolioImporter;
use Breakfast\Platform\Portfolio\PortfolioStatus;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * Portfolio import (CP7): the one-time, safe migration of the existing Kirby
 * "project" case-study content into portfolio records. Everything imported must
 * land as a DRAFT, concept pieces flagged, narrative fields mapped to story
 * sections, and re-running must never duplicate — and the CRM search must be
 * able to find the imported records without leaking anything private.
 */
final class PortfolioImportTest extends PlatformTestCase
{
    private string $contentDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contentDir = $this->tmpDir . '/content';
        @mkdir($this->contentDir . '/_drafts/work/1_passo-rebuild', 0777, true);
        @mkdir($this->contentDir . '/_drafts/work/2_blank', 0777, true);
    }

    private function writeProject(string $folder, string $body): void
    {
        file_put_contents($this->contentDir . '/_drafts/work/' . $folder . '/project.txt', $body);
    }

    private function importer(): PortfolioImporter
    {
        return new PortfolioImporter($this->platform->portfolio(), $this->contentDir);
    }

    /** @return array<string,mixed>|null */
    private function firstRecord(): ?array
    {
        $rows = $this->platform->portfolio()->list([]);

        return $rows[0] ?? null;
    }

    public function testImportsProjectAsDraftWithStorySections(): void
    {
        $this->writeProject('1_passo-rebuild', <<<TXT
Title: Passo — a café rebuild that lifted bookings

----

Client: Passo Coffee

----

Summary: A faster, clearer site for a busy café.

----

Project_type: Website rebuild

----

Challenge: The old site was slow and hard to update.

----

Strategy: Put bookings and the menu first.

----

Outcome: Bookings became easier to take.
TXT);
        // The 2_blank folder has no project.txt and must be ignored silently.

        $report = $this->importer()->import('cli:test', false);

        $this->assertSame(['passo-rebuild'], $report['imported']);
        $this->assertSame([], $report['duplicate']);

        // A freshly imported draft is never publicly visible.
        $this->assertNull($this->platform->portfolio()->publicBySlug('passo-rebuild'));

        $rows = $this->platform->portfolio()->list([]);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(PortfolioStatus::DRAFT, $row['status']);
        $this->assertSame('passo-rebuild', $row['slug']);
        $this->assertStringContainsString('(imported', (string) $row['internal_name']);

        // Narrative fields were mapped onto structured, escaped story sections.
        $full = $this->platform->portfolio()->find((string) $row['uuid']);
        $this->assertNotNull($full);
        $bodies = [];
        foreach ($full['story'] as $section) {
            $bodies[$section['section_key']] = (string) $section['body'];
        }
        $this->assertArrayHasKey('problem', $bodies);
        $this->assertStringContainsString('slow and hard to update', $bodies['problem']);
        $this->assertStringContainsString('Bookings became easier', $bodies['outcome']);
    }

    public function testConceptWorkIsFlaggedNotPublished(): void
    {
        $this->writeProject('1_passo-rebuild', <<<TXT
Title: Demo concept piece

----

Project_status: concept

----

Summary: A speculative design.
TXT);
        $report = $this->importer()->import('cli:test', false);

        $this->assertSame(['passo-rebuild'], $report['placeholder']);
        $this->assertSame(['passo-rebuild'], $report['missing_media']);

        $row = $this->firstRecord();
        $this->assertNotNull($row);
        $this->assertSame(PortfolioStatus::DRAFT, $row['status']);
        $this->assertSame('concept', $row['project_type']);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->writeProject('1_passo-rebuild', "Title: Passo\n\n----\n\nSummary: x");
        $report = $this->importer()->import('cli:test', true);

        $this->assertNotEmpty($report['imported']);
        $this->assertStringContainsString('(dry-run)', $report['imported'][0]);
        $this->assertSame([], $this->platform->portfolio()->list([]));
    }

    public function testReRunReportsDuplicatesInsteadOfCreating(): void
    {
        $this->writeProject('1_passo-rebuild', "Title: Passo\n\n----\n\nSummary: x");
        $this->importer()->import('cli:test', false);
        $second = $this->importer()->import('cli:test', false);

        $this->assertSame(['passo-rebuild'], $second['duplicate']);
        $this->assertSame([], $second['imported']);
        // Still exactly one record.
        $this->assertCount(1, $this->platform->portfolio()->list([]));
    }

    public function testSearchFindsImportedRecordWithoutLeakingInternals(): void
    {
        $this->writeProject('1_passo-rebuild', <<<TXT
Title: Passo café rebuild

----

Client: Passo Coffee

----

Summary: A café site rebuild.
TXT);
        $this->importer()->import('cli:test', false);

        $results = (new SearchService($this->platform->db()))->search('Passo');
        $portfolio = array_values(array_filter($results, static fn ($r) => $r['type'] === 'portfolio'));
        $this->assertCount(1, $portfolio);
        $this->assertStringStartsWith('/portfolio/', $portfolio[0]['route']);
        // No filesystem path or other internal data leaks into a search row.
        $this->assertStringNotContainsString($this->contentDir, json_encode($portfolio[0]) ?: '');
    }
}
