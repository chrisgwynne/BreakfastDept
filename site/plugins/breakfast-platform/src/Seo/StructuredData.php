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
            'name'     => $content->get('legal_name')->value() ?: 'Breakfast',
            'url'      => $this->site->url(),
        ];

        if ($email = $content->get('email')->value()) {
            $data['email'] = $email;
        }

        if ($phone = $content->get('phone')->value()) {
            $data['telephone'] = $phone;
        }

        $sameAs = $this->socialProfiles();
        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }

        return $data;
    }

    /**
     * The primary business entity — a ProfessionalService (a LocalBusiness
     * subtype). Only honest data is emitted: no postal address, telephone or
     * coordinates are output unless real values are configured in Kirby.
     *
     * @return array<string,mixed>
     */
    public function business(): array
    {
        $content = $this->site->content();

        // Public brand name is the site title ("Breakfast"), matching og:site_name
        // and the footer. The registered entity name goes in legalName so the two
        // never contradict each other.
        $brand = 'Breakfast';
        $legal = $content->get('legal_name')->value();

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'ProfessionalService',
            '@id'      => $this->site->url() . '#business',
            'name'     => $brand,
            'url'      => $this->site->url(),
        ];
        if ($legal && $legal !== $brand) {
            $data['legalName'] = $legal;
        }

        // Logo + representative image (the default social card doubles as both).
        $social = $content->get('default_social_image')->toFile();
        if ($social !== null) {
            $data['image'] = $social->url();
            $data['logo']  = $social->url();
        }

        if ($price = $content->get('price_range')->value()) {
            $data['priceRange'] = $price;
        }

        if ($desc = $content->get('meta_description')->value()) {
            $data['description'] = $desc;
        }

        if ($email = $content->get('email')->value()) {
            $data['email'] = $email;
        }

        if ($phone = $content->get('phone')->value()) {
            $data['telephone'] = $phone;
        }

        if ($owner = $content->get('owner_name')->value()) {
            $data['founder'] = ['@type' => 'Person', 'name' => $owner];
        }

        $areas = $this->areasServed();
        if ($areas !== []) {
            $data['areaServed'] = array_map(
                static fn (string $name): array => ['@type' => 'AdministrativeArea', 'name' => $name],
                $areas
            );
        }

        $address = $this->postalAddress();
        if ($address !== null) {
            $data['address'] = $address;
        }

        $lat = $content->get('latitude')->value();
        $lng = $content->get('longitude')->value();
        if (is_numeric($lat) && is_numeric($lng)) {
            $data['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
            ];
        }

        $hours = $this->openingHours();
        if ($hours !== []) {
            $data['openingHours'] = $hours;
        }

        $sameAs = $this->socialProfiles();
        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }

        return $data;
    }

    /**
     * The WebSite entity, linked to the business as its publisher.
     *
     * @return array<string,mixed>
     */
    public function website(): array
    {
        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'WebSite',
            '@id'        => $this->site->url() . '#website',
            'url'        => $this->site->url(),
            'name'       => 'Breakfast',
            'inLanguage' => 'en-GB',
            'publisher'  => ['@id' => $this->site->url() . '#business'],
        ];
    }

    /**
     * Real profile URLs only (http/https) — never a mailto: or tel:, which are
     * invalid targets for schema.org sameAs.
     *
     * @return list<string>
     */
    private function socialProfiles(): array
    {
        $content = $this->site->content();
        $out = [];

        foreach ($content->get('social')->toStructure() as $item) {
            $url = (string) $item->url()->value();
            if (preg_match('#^https?://#i', $url) === 1) {
                $out[] = $url;
            }
        }

        $gbp = (string) $content->get('google_business_url')->value();
        if (preg_match('#^https?://#i', $gbp) === 1) {
            $out[] = $gbp;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function areasServed(): array
    {
        $content = $this->site->content();
        $areas = [];

        if ($country = $content->get('country')->value()) {
            $areas[] = (string) $country;
        }

        foreach ($content->get('areas_served')->split() as $area) {
            $area = trim((string) $area);
            if ($area !== '' && ! in_array($area, $areas, true)) {
                $areas[] = $area;
            }
        }

        return $areas;
    }

    /**
     * A PostalAddress only when a real locality/region is configured. A fake
     * street address is never emitted.
     *
     * @return array<string,string>|null
     */
    private function postalAddress(): ?array
    {
        $content = $this->site->content();
        $locality = (string) $content->get('base_town')->value();
        $region = (string) $content->get('county')->value();
        $country = (string) $content->get('country')->value();

        if ($locality === '' && $region === '') {
            return null;
        }

        $address = ['@type' => 'PostalAddress'];
        if ($locality !== '') {
            $address['addressLocality'] = $locality;
        }
        if ($region !== '') {
            $address['addressRegion'] = $region;
        }
        // schema.org addressCountry expects an ISO 3166-1 code, not a nation
        // name. "Wales"/"England"/etc. are all GB; pass a real 2-letter code
        // straight through.
        if ($country !== '') {
            $address['addressCountry'] = strlen($country) === 2 ? strtoupper($country) : 'GB';
        }

        return $address;
    }

    /**
     * @return list<string>
     */
    private function openingHours(): array
    {
        $out = [];

        foreach ($this->site->content()->get('business_hours')->toStructure() as $row) {
            $days = trim((string) $row->days()->value());
            $hours = trim((string) $row->hours()->value());
            if ($days !== '' && $hours !== '') {
                $out[] = $days . ' ' . $hours;
            }
        }

        return $out;
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

        $data['publisher'] = ['@id' => $this->site->url() . '#business'];

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
