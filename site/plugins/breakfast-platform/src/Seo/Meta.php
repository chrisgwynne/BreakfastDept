<?php

declare(strict_types=1);

namespace Breakfast\Platform\Seo;

use Kirby\Cms\Page;
use Kirby\Cms\Site;

/**
 * Resolves the effective SEO metadata for a page, applying sensible fallbacks
 * from page content → site defaults → derived values. Used by the head snippet
 * and by the "missing SEO" Panel indicators.
 */
final class Meta
{
    public function __construct(
        private readonly Page $page,
        private readonly Site $site
    ) {
    }

    public function title(): string
    {
        $seo = $this->page->content()->get('seo_title')->value();
        $base = $seo !== null && $seo !== ''
            ? $seo
            : ($this->page->content()->get('title')->value() ?? $this->page->title()->value());

        $siteName = $this->site->title()->value() ?: 'Breakfast';

        // Home page shows the site title as-is; others append it.
        if ($this->page->isHomePage()) {
            return (string) ($base ?: $siteName);
        }

        return trim((string) $base) . ' — ' . $siteName;
    }

    public function description(): string
    {
        foreach (['meta_description', 'excerpt', 'summary', 'standfirst'] as $field) {
            $value = trim((string) $this->page->content()->get($field)->value());
            if ($value !== '') {
                return $this->truncate($value, 160);
            }
        }

        return $this->truncate((string) $this->site->content()->get('meta_description')->value(), 160);
    }

    public function canonical(): string
    {
        $custom = trim((string) $this->page->content()->get('canonical_url')->value());

        return $custom !== '' ? $custom : $this->page->url();
    }

    public function robots(): string
    {
        // Drafts and unlisted pages are never indexable.
        if ($this->page->isDraft() || $this->page->isUnlisted()) {
            return 'noindex, nofollow';
        }

        $noindex = $this->page->content()->get('no_index')->toBool(false);

        return $noindex ? 'noindex, follow' : 'index, follow';
    }

    public function ogImage(): ?string
    {
        foreach (['og_image', 'social_image', 'cover', 'hero_image', 'card_image'] as $field) {
            $file = $this->page->content()->get($field)->toFile();
            if ($file !== null) {
                return $file->url();
            }
        }

        $default = $this->site->content()->get('default_social_image')->toFile();

        return $default?->url();
    }

    public function ogType(): string
    {
        return match ($this->page->intendedTemplate()->name()) {
            'article' => 'article',
            'project' => 'article',
            default   => 'website',
        };
    }

    /**
     * @return array{title:bool,description:bool} which required fields are present
     */
    public function completeness(): array
    {
        return [
            'title'       => trim((string) $this->page->content()->get('seo_title')->value()) !== '',
            'description' => trim((string) $this->page->content()->get('meta_description')->value()) !== '',
        ];
    }

    private function truncate(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $length - 1)) . '…';
    }
}
