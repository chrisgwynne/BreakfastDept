<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

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

        $this->auditDuplicateMetadata($pages->toArray(static fn (Page $p): Page => $p));

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
        }

        // 3. Empty primary CTA on the home page.
        if ($page->isHomePage() && $page->content()->get('final_cta_heading')->isEmpty()) {
            $this->warn('empty-cta', $where, 'Home page final call-to-action heading is empty.');
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
