<?php

declare(strict_types=1);

namespace Breakfast\Platform\Seo;

use Kirby\Cms\Page;
use Kirby\Cms\Site;

/**
 * Builds JSON-LD structured data graphs. Everything is escaped by json_encode;
 * output is emitted inside a <script type="application/ld+json"> tag.
 */
final class StructuredData
{
    public function __construct(private readonly Site $site)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function organisation(): array
    {
        $content = $this->site->content();

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $content->get('legal_name')->value() ?: $this->site->title()->value(),
            'url'      => $this->site->url(),
        ];

        if ($email = $content->get('email')->value()) {
            $data['email'] = $email;
        }

        if ($phone = $content->get('phone')->value()) {
            $data['telephone'] = $phone;
        }

        $sameAs = [];
        foreach ($content->get('social')->toStructure() as $item) {
            if ($url = $item->url()->value()) {
                $sameAs[] = $url;
            }
        }
        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }

        return $data;
    }

    /**
     * @param list<array{name:string,url:string}> $crumbs
     * @return array<string,mixed>
     */
    public function breadcrumbs(array $crumbs): array
    {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['name'],
                'item'     => $crumb['url'],
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function article(Page $page): array
    {
        $content = $page->content();
        $data = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => $content->get('title')->value(),
            'url'           => $page->url(),
            'datePublished' => $this->date($page, 'date'),
            'dateModified'  => $this->date($page, 'updated') ?? $this->date($page, 'date'),
        ];

        if ($excerpt = $content->get('excerpt')->value()) {
            $data['description'] = $excerpt;
        }

        if ($author = $content->get('author')->value()) {
            $data['author'] = ['@type' => 'Person', 'name' => $author];
        }

        if ($cover = $content->get('cover')->toFile()) {
            $data['image'] = $cover->url();
        }

        $data['publisher'] = [
            '@type' => 'Organization',
            'name'  => $this->site->title()->value(),
        ];

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    public function creativeWork(Page $page): array
    {
        $content = $page->content();
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'CreativeWork',
            'name'     => $content->get('title')->value(),
            'url'      => $page->url(),
        ];

        if ($summary = $content->get('summary')->value()) {
            $data['abstract'] = $summary;
        }

        if ($client = $content->get('client')->value()) {
            $data['creator'] = ['@type' => 'Organization', 'name' => $client];
        }

        return $data;
    }

    private function date(Page $page, string $field): ?string
    {
        $value = $page->content()->get($field)->value();

        if ($value === null || $value === '') {
            return null;
        }

        $ts = strtotime((string) $value);

        return $ts === false ? null : date('c', $ts);
    }

    /** @param array<string,mixed> $data */
    public static function toScript(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return '';
        }

        // Prevent </script> breakout.
        $json = str_replace('<', '<', $json);

        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
