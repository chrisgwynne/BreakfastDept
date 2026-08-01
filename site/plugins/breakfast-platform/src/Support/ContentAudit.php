<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

use Breakfast\Platform\Content\ArtDirectionQualityGate;
use Breakfast\Platform\Content\CaseStudyProbe;
use Breakfast\Platform\Content\CaseStudyWarnings;
use Breakfast\Platform\Portfolio\PortfolioSimilarity;
use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * Audits public content for placeholder text, false claims, structural gaps and
 * unconfigured business details.
 *
 * Findings are split into two severities so the audit can act as a release gate
 * without failing on content the owner simply hasn't supplied yet:
 *
 *  - ERROR   — a structural defect or a false/placeholder claim that must NEVER
 *              be published (fabricated proof, "Britain" positioning, a project
 *              missing its status). `--strict` fails on these.
 *  - WARNING — a real but not-yet-supplied detail (no phone, no town, no project
 *              image, duplicate metadata). Surfaced, but never fails CI.
 */
final class ContentAudit
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * Placeholder / false-claim patterns that must never appear in published
     * content. Each maps a regex to a short category label.
     *
     * @var array<string,string>
     */
    private const FORBIDDEN = [
        '/\bplaceholder\b/i'              => 'placeholder-text',
        '/lorem ipsum/i'                  => 'placeholder-text',
        '/\billustrative\b/i'             => 'placeholder-text',
        '/example (client|restaurant|business|shop)/i' => 'placeholder-text',
        '/made by people,? in britain/i' => 'false-positioning',
        '/\bbrit(ain|ish)\b/i'           => 'false-positioning',
    ];

    /** @var list<array{severity:string,category:string,where:string,message:string}> */
    private array $findings = [];

    public function __construct(private readonly App $kirby)
    {
    }

    /**
     * @return list<array{severity:string,category:string,where:string,message:string}>
     */
    public function run(): array
    {
        $this->findings = [];
        $this->auditBusinessSettings();

        $pages = $this->kirby->site()->index()->listed();
        foreach ($pages as $page) {
            $this->auditPage($page);
        }

        $pageList = $pages->toArray(static fn (Page $p): Page => $p);
        $this->auditDuplicateMetadata($pageList);
        $this->auditPortfolioSimilarity($pageList);

        return $this->findings;
    }

    public function hasErrors(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding['severity'] === self::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{errors:int,warnings:int}
     */
    public function counts(): array
    {
        $errors = 0;
        $warnings = 0;
        foreach ($this->findings as $finding) {
            if ($finding['severity'] === self::ERROR) {
                $errors++;
            } else {
                $warnings++;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function auditBusinessSettings(): void
    {
        $site = $this->kirby->site();
        $content = $site->content();

        // False positioning in settings is an error; missing real details warn.
        $this->scanValues('site settings', $content->data());

        if (trim((string) $content->get('owner_name')->value()) === '') {
            $this->warn('config', 'site settings', 'Owner / display name not set — the About page and structured data stay anonymous.');
        }
        if (trim((string) $content->get('base_town')->value()) === '') {
            $this->warn('config', 'site settings', 'Base town not set — the site says "Wales" until a real town is entered.');
        }
        if (trim((string) $content->get('phone')->value()) === '') {
            $this->warn('config', 'site settings', 'Phone not set — no telephone is published or added to structured data.');
        }
    }

    private function auditPage(Page $page): void
    {
        $where = $page->id();

        // 1. Placeholder / false-claim text anywhere in the page's fields.
        $this->scanValues($where, $page->content()->data());

        // 2. Project-specific structural checks.
        if ($page->intendedTemplate()->name() === 'project') {
            $status = trim((string) $page->content()->get('project_status')->value());
            if ($status === '') {
                $this->error('incomplete-project', $where, 'Project has no status — every project must be marked real / concept / internal / draft.');
            }
            if ($page->content()->get('summary')->isEmpty()) {
                $this->error('incomplete-project', $where, 'Project has no summary.');
            }
            $hasImage = $page->content()->get('hero_image')->isNotEmpty()
                || $page->content()->get('card_image')->isNotEmpty();
            if ($hasImage === false) {
                $this->warn('missing-image', $where, 'Project has no hero or card image — add one before presenting it as finished work.');
            }
            $this->auditCaseStudyWarnings($page, $where);
            $this->auditArtDirectionGate($page, $where);
        }

        // 3. Empty primary CTA on the home page.
        if ($page->isHomePage() && $page->content()->get('final_cta_heading')->isEmpty()) {
            $this->warn('empty-cta', $where, 'Home page final call-to-action heading is empty.');
        }
    }

    /**
     * Surface actionable case-study warnings (performance budget, oversized
     * images, missing alt, too many heavy blocks) via {@see CaseStudyWarnings}.
     */
    private function auditCaseStudyWarnings(Page $page, string $where): void
    {
        $probe = CaseStudyProbe::probe($page);
        foreach (CaseStudyWarnings::inspect($probe['images'], $probe['counts']) as $w) {
            $this->warn($w['category'], $where, $w['message']);
        }
    }

    /**
     * Enforce the minimum art-direction quality gate. For a published,
     * art-directed record its composition blockers are ERRORS (a sparse
     * one-image page must never go live as bespoke work); warnings are surfaced.
     * "Simple" case studies only ever warn.
     */
    private function auditArtDirectionGate(Page $page, string $where): void
    {
        $record = CaseStudyProbe::record($page);
        // Draft/unpublished records are still being built — advise, don't block.
        $isLive = $page->isListed() && ($record['status'] ?? '') !== 'draft';
        $gate = ArtDirectionQualityGate::evaluate($record);

        foreach ($gate['blockers'] as $msg) {
            if ($isLive) {
                $this->error('art-direction-gate', $where, $msg);
            } else {
                $this->warn('art-direction-gate', $where, $msg);
            }
        }
        foreach ($gate['warnings'] as $msg) {
            $this->warn('art-direction', $where, $msg);
        }
    }

    /**
     * Editorial guard: flag any two published case studies whose structural
     * fingerprints are too alike (same hero, ending, palette and block
     * sequence) — the failure mode where every portfolio page collapses into
     * one template with different words.
     *
     * @param array<int|string,Page> $pages
     */
    private function auditPortfolioSimilarity(array $pages): void
    {
        $records = [];
        foreach ($pages as $page) {
            if ($page->intendedTemplate()->name() !== 'project') {
                continue;
            }
            $record = CaseStudyProbe::record($page);
            if (($record['caseMode'] ?? '') === 'simple') {
                continue; // simple case studies are exempt from the editorial guard
            }
            $records[$page->id()] = $record;
        }

        $ids = array_keys($records);
        $reported = [];
        foreach ($ids as $id) {
            $others = $records;
            unset($others[$id]);
            $analysis = PortfolioSimilarity::analyse($records[$id], $others);
            if (!$analysis['tooSimilar'] || $analysis['nearest'] === null) {
                continue;
            }
            // Report each pair once.
            $pairKey = implode('|', [min($id, $analysis['nearest']), max($id, $analysis['nearest'])]);
            if (isset($reported[$pairKey])) {
                continue;
            }
            $reported[$pairKey] = true;
            $recs = is_array($analysis['detail'] ?? null) ? ($analysis['detail']['recommendations'] ?? []) : [];
            $this->error('portfolio-similarity', $id, sprintf(
                'Too structurally similar to %s (%.0f%%). %s',
                (string) $analysis['nearest'],
                $analysis['score'] * 100,
                $recs !== [] ? implode(' ', $recs) : 'Vary the hero, ending, palette or block sequence.'
            ));
        }
    }

    /**
     * @param array<int|string,Page> $pages
     */
    private function auditDuplicateMetadata(array $pages): void
    {
        $titles = [];
        foreach ($pages as $page) {
            $title = trim((string) $page->content()->get('seo_title')->value());
            if ($title === '') {
                continue;
            }
            $titles[$title][] = $page->id();
        }

        foreach ($titles as $title => $ids) {
            if (count($ids) > 1) {
                $this->warn('duplicate-metadata', implode(', ', $ids), 'Duplicate SEO title "' . $title . '" — titles should be unique per page.');
            }
        }
    }

    /**
     * @param array<int|string,mixed> $values
     */
    private function scanValues(string $where, array $values): void
    {
        foreach ($values as $field => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            foreach (self::FORBIDDEN as $pattern => $category) {
                if (preg_match($pattern, $value) === 1) {
                    $this->error($category, $where . ' · ' . (string) $field, 'Forbidden text matched ' . $pattern . '.');
                }
            }
        }
    }

    private function error(string $category, string $where, string $message): void
    {
        $this->findings[] = ['severity' => self::ERROR, 'category' => $category, 'where' => $where, 'message' => $message];
    }

    private function warn(string $category, string $where, string $message): void
    {
        $this->findings[] = ['severity' => self::WARNING, 'category' => $category, 'where' => $where, 'message' => $message];
    }
}
