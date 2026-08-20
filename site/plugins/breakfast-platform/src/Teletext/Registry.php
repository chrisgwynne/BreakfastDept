<?php

declare(strict_types=1);

namespace Breakfast\Platform\Teletext;

use Kirby\Cms\Page;
use Kirby\Cms\Site;

/**
 * The ONE authoritative map between a "Teletext" page number and its real
 * Kirby destination. Nothing else in the codebase should invent page numbers.
 *
 * - System pages (home, start a project, work index, …) are fixed here.
 * - Listing children (work/services/journal) get sequential numbers unless a
 *   page opts into an explicit override via its `teletext_number` field.
 * - Easter eggs are real, unlisted Kirby pages under /text/{number} — they are
 *   NOT included in the public JSON handed to the browser (see toPublicJson());
 *   the client falls back to requesting /text/{number} for anything it does
 *   not recognise, so unpublished numbers 404 for real instead of leaking a
 *   list of secrets in page source.
 */
final class Registry
{
    /** @var array<int,array{url:string,title:string,category:string}> */
    private const SYSTEM_PAGES = [
        100 => ['url' => '/',                  'title' => 'Home',                     'category' => 'index'],
        101 => ['url' => '/start-a-project',    'title' => 'Start a Project',          'category' => 'convert'],
        102 => ['url' => '/thank-you',          'title' => 'Message Received',         'category' => 'system'],
        110 => ['url' => '/website-review',     'title' => 'Website Check',            'category' => 'convert'],
        200 => ['url' => '/work',               'title' => 'Our Work',                 'category' => 'work'],
        300 => ['url' => '/services',           'title' => 'Services',                 'category' => 'services'],
        400 => ['url' => '/about#how',          'title' => 'How Breakfast Works',      'category' => 'about'],
        500 => ['url' => '/about',              'title' => 'About Breakfast',          'category' => 'about'],
        502 => ['url' => '/privacy',            'title' => 'Privacy',                  'category' => 'legal'],
        503 => ['url' => '/accessibility',      'title' => 'Accessibility',            'category' => 'legal'],
        504 => ['url' => '/terms',              'title' => 'Terms',                    'category' => 'legal'],
        505 => ['url' => '/cookies',            'title' => 'Cookies',                  'category' => 'legal'],
        600 => ['url' => '/journal',            'title' => 'Journal',                  'category' => 'journal'],
        700 => ['url' => '/contact',            'title' => 'Contact',                  'category' => 'convert'],
    ];

    /**
     * Listing pages whose visible children are numbered sequentially. The
     * array key is the slug of the site() lookup; `start` is the first
     * child number. A child can also claim a specific number instead via
     * its own `teletext_number` content field (see all()).
     *
     * @var array<string,array{start:int}>
     */
    private const SEQUENCES = [
        'work'     => ['start' => 201],
        'services' => ['start' => 301],
        'journal'  => ['start' => 601],
    ];

    /**
     * Full merged registry: system pages + sequential children. Does NOT
     * include easter eggs — those are resolved on demand via /text/{n} and
     * never enumerated. Numbers are cached per-request (page trees don't
     * change mid-request).
     *
     * @return array<int,array{url:string,title:string,category:string}>
     */
    public static function all(Site $site): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $entries = self::SYSTEM_PAGES;

        foreach (self::SEQUENCES as $slug => $seq) {
            $listing = $site->find($slug);
            if ($listing === null) {
                continue;
            }

            $claimed = [];
            $unclaimed = [];
            foreach ($listing->children()->listed() as $child) {
                $override = (int) $child->content()->get('teletext_number')->value();
                if ($override > 0 && isset($entries[$override]) === false && isset($claimed[$override]) === false) {
                    $claimed[$override] = $child;
                } else {
                    $unclaimed[] = $child;
                }
            }

            foreach ($claimed as $number => $child) {
                $entries[$number] = self::entryFor($child, $slug);
            }

            $next = $seq['start'];
            foreach ($unclaimed as $child) {
                while (isset($entries[$next]) || isset($claimed[$next])) {
                    $next++;
                }
                $entries[$next] = self::entryFor($child, $slug);
                $next++;
            }
        }

        ksort($entries);

        return $cache = $entries;
    }

    /**
     * The number for a given page, if it has one (system or dynamic). Used to
     * show "P301" in a page's own masthead.
     */
    public static function numberFor(Page $page, Site $site): ?int
    {
        foreach (self::all($site) as $number => $entry) {
            if (self::sameDestination($entry['url'], $page)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * Same as numberFor(), but also resolves the number for an easter egg
     * page (/text/{number}) by reading it straight off the slug — those
     * pages are deliberately excluded from numberFor()/the public registry,
     * but the page itself still needs to show its own number in the
     * masthead/footer. The single place this fallback is defined.
     */
    public static function displayNumberFor(Page $page, Site $site): ?int
    {
        $direct = self::numberFor($page, $site);
        if ($direct !== null) {
            return $direct;
        }

        $textParent = $site->find('text');
        if ($textParent !== null && $page->parent() !== null && $page->parent()->is($textParent) && ctype_digit($page->slug())) {
            return (int) $page->slug();
        }

        return null;
    }

    /**
     * Resolve a typed number against the public registry only. Returns null
     * for anything not in it (including easter eggs) — the caller should then
     * try /text/{number} and let Kirby's own routing 404 correctly.
     *
     * @return array{url:string,title:string,category:string}|null
     */
    public static function resolve(int $number, Site $site): ?array
    {
        return self::all($site)[$number] ?? null;
    }

    /**
     * The JSON handed to the browser for client-side typed navigation. Public
     * commercial pages only — never easter eggs.
     */
    public static function toPublicJson(Site $site): string
    {
        $out = [];
        foreach (self::all($site) as $number => $entry) {
            $out[(string) $number] = ['url' => $entry['url'], 'title' => $entry['title']];
        }

        return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{url:string,title:string,category:string}
     */
    private static function entryFor(Page $child, string $category): array
    {
        return [
            'url'      => $child->url(),
            'title'    => $child->content()->get('short_name')->or($child->title())->value(),
            'category' => $category,
        ];
    }

    private static function sameDestination(string $registryUrl, Page $page): bool
    {
        // Registry URLs are a mix of relative system paths ('/work') and
        // absolute page URLs from Page::url() ('https://host/work/foo') —
        // normalise both sides to a bare path before comparing.
        $registryPath = (string) parse_url(strtok($registryUrl, '#'), PHP_URL_PATH);
        $pagePath     = (string) parse_url($page->url(), PHP_URL_PATH);

        if ($registryPath === '' || $registryPath === '/') {
            return $page->isHomePage();
        }

        return rtrim($pagePath, '/') === rtrim($registryPath, '/');
    }
}
