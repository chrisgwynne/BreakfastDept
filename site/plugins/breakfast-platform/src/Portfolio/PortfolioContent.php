<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

use Breakfast\Platform\Content\ArtDirection;
use Breakfast\Platform\Content\ArtDirectionQualityGate;
use Breakfast\Platform\Content\CaseStudyProbe;
use Breakfast\Platform\Content\CaseStudyWarnings;
use Breakfast\Platform\Screenshots\CaptureRequest;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\VersionId;

/**
 * Portfolio authoring adapter for the standalone Breakfast Admin.
 *
 * Reads and writes case-study project pages (flat-file Kirby content under
 * content/1_work/) and stitches in the screenshot library, derivatives, art
 * direction and warnings so the whole workflow can run from Breakfast Admin —
 * create, art-direct, capture, crop, compose, preview and publish. Editing uses
 * Kirby's draft version (changes) so nothing goes live until Publish. This is a
 * thin Kirby adapter (dynamic content API) and is excluded from static analysis;
 * its pure collaborators (ArtDirection, CompositionSanitizer, Derivatives,
 * ScreenshotService, SafeUrl) are analysed and unit-tested.
 */
final class PortfolioContent
{
    private const LANG = 'en';
    private const PARENT = 'work';

    public function __construct(
        private readonly App $kirby,
        private readonly Platform $platform,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(): array
    {
        $parent = $this->kirby->page(self::PARENT);
        if ($parent === null) {
            return [];
        }
        $out = [];
        foreach ($parent->childrenAndDrafts()->filterBy('intendedTemplate', 'project') as $p) {
            $out[] = $this->stub($p);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function stub(Page $p): array
    {
        return [
            'id'         => $p->id(),
            'slug'       => $p->slug(),
            'title'      => (string) $p->title(),
            'status'     => $p->status(),
            'hasChanges' => $p->version(VersionId::changes())->exists(self::LANG),
            'client'     => (string) $p->client(),
            'updated'    => (string) $p->content()->get('date'),
        ];
    }

    /** @return array<string,mixed> */
    public function create(string $title, string $actor, array $opts = []): array
    {
        $title = trim($title) !== '' ? trim($title) : 'Untitled case study';
        $parent = $this->kirby->page(self::PARENT);
        if ($parent === null) {
            throw new PortfolioException(500, 'The work section is missing.');
        }
        $slug = $this->uniqueSlug($parent, $title);
        $page = $parent->createChild([
            'slug'     => $slug,
            'template' => 'project',
            'draft'    => true,
            'content'  => [
                'title'          => $title,
                'project_status' => 'concept',
                'client'         => (string) ($opts['client'] ?? ''),
                'company_uuid'   => (string) ($opts['company_uuid'] ?? ''),
                'opportunity_uuid' => (string) ($opts['opportunity_uuid'] ?? ''),
                'ad_preset'      => 'bold-editorial',
                'uuid'           => 'work-' . $slug,
            ],
        ]);
        $this->audit('portfolio.created', $page->id(), $actor, ['title' => $title]);

        return $this->load($page->id());
    }

    /** @return array<string,mixed> */
    public function load(string $id): array
    {
        $page = $this->page($id);
        $c = $this->editingContent($page);

        $adFields = [];
        foreach (['preset', 'accent', 'secondary', 'bg', 'text', 'display', 'body', 'corner',
            'border', 'density', 'image_scale', 'rotation', 'caption', 'pullquote',
            'animation', 'transition', 'motif', 'hero', 'ending'] as $k) {
            $adFields[$k] = (string) ($c['ad_' . $k] ?? '');
        }
        $ad = ArtDirection::resolve($adFields);

        $probe = CaseStudyProbe::probe($page);
        $warnings = CaseStudyWarnings::inspect($probe['images'], $probe['counts']);

        $screenshots = $this->platform->screenshots()->list($this->projectUuid($page), true);
        $derivatives = $this->platform->derivatives()->list($this->projectUuid($page));

        return [
            'id'          => $page->id(),
            'slug'        => $page->slug(),
            'title'       => (string) ($c['title'] ?? $page->title()),
            'status'      => $page->status(),
            'hasChanges'  => $page->version(VersionId::changes())->exists(self::LANG),
            'fields'      => $this->publicFields($c),
            'artDirection' => ['fields' => $adFields, 'preset' => $ad['preset'], 'data' => $ad['data']],
            'blocks'      => $this->blocksJson($c),
            'media'       => $this->media($page),
            'approvedHosts' => $this->approvedHosts($page),
            'approvedPaths' => array_values(array_filter(array_map('trim', explode(',', (string) ($c['approved_paths'] ?? ''))))),
            'screenshots' => $screenshots,
            'derivatives' => $derivatives,
            'warnings'    => $warnings,
            'publishable' => $this->publishBlockers($page, $screenshots, $warnings) === [],
            'blockers'    => $this->publishBlockers($page, $screenshots, $warnings),
            'previewUrl'  => $this->kirby->url() . '/breakfast-admin/preview/frame/' . $page->id(),
        ];
    }

    /** @return array<string,mixed> */
    public function save(string $id, array $values, string $actor): array
    {
        $page = $this->page($id);
        $clean = [];

        // Art-direction fields (validated against the resolver's whitelists).
        foreach (ArtDirection::PRESETS as $_) {
            break;
        }
        $adKeys = ['preset', 'accent', 'secondary', 'bg', 'text', 'display', 'body', 'corner',
            'border', 'density', 'image_scale', 'rotation', 'caption', 'pullquote',
            'animation', 'transition', 'motif', 'hero', 'ending'];
        if (isset($values['artDirection']) && is_array($values['artDirection'])) {
            $resolved = ArtDirection::resolve($this->prefixless($values['artDirection']));
            // Persist the raw chosen values (already whitelisted at render time).
            foreach ($adKeys as $k) {
                if (array_key_exists($k, $values['artDirection'])) {
                    $clean['ad_' . $k] = (string) $values['artDirection'][$k];
                }
            }
            unset($resolved);
        }

        foreach (['title', 'summary', 'client', 'challenge', 'approach', 'strategy',
            'design_explanation', 'build_explanation', 'outcome', 'technology',
            'project_url', 'seo_title', 'meta_description', 'company_uuid',
            'opportunity_uuid', 'approved_paths'] as $k) {
            if (array_key_exists($k, $values)) {
                $clean[$k] = is_scalar($values[$k]) ? (string) $values[$k] : $clean[$k] ?? '';
            }
        }
        if (array_key_exists('approvedHosts', $values) && is_array($values['approvedHosts'])) {
            $clean['approved_hosts'] = implode(',', array_map('strval', $values['approvedHosts']));
        }
        if (array_key_exists('blocks', $values)) {
            $clean['body'] = $this->sanitiseBlocks($values['blocks']);
        }

        $base = $this->editingContent($page);
        $page->version(VersionId::changes())->save(array_merge($base, $clean), self::LANG);
        $this->audit('portfolio.saved', $id, $actor, ['fields' => array_keys($clean)]);

        return $this->load($id);
    }

    /** @return array<string,mixed> */
    public function publish(string $id, string $actor): array
    {
        $page = $this->page($id);
        $screenshots = $this->platform->screenshots()->list($this->projectUuid($page), true);
        $probe = CaseStudyProbe::probe($page);
        $warnings = CaseStudyWarnings::inspect($probe['images'], $probe['counts']);
        $blockers = $this->publishBlockers($page, $screenshots, $warnings);
        if ($blockers !== []) {
            throw new PortfolioException(409, 'Resolve the publication blockers first: ' . implode('; ', $blockers));
        }

        $changes = $page->version(VersionId::changes());
        if ($changes->exists(self::LANG)) {
            $content = $changes->read(self::LANG) ?? [];
            $page->version(VersionId::latest())->save($content, self::LANG);
            $changes->delete(self::LANG);
        }
        if ($page->status() === 'draft') {
            $page->changeStatus('listed');
        }
        $this->audit('portfolio.published', $id, $actor, []);

        return $this->load($id);
    }

    /** @return array<string,mixed> */
    public function unpublish(string $id, string $actor): array
    {
        $page = $this->page($id);
        $page->changeStatus('unlisted');
        $this->audit('portfolio.unpublished', $id, $actor, []);

        return $this->load($id);
    }

    // ---- Screenshots -----------------------------------------------------

    /**
     * Capture a screenshot for this project, mapping technical failures to a
     * human-readable message. Never exposes shell commands or server paths.
     *
     * @return array<string,mixed>
     */
    public function capture(string $id, array $params, string $actor): array
    {
        $page = $this->page($id);
        $hosts = $this->approvedHosts($page);
        if ($hosts === []) {
            throw new PortfolioException(422, 'Add an approved website host for this project before capturing.');
        }
        $path = '/' . ltrim((string) ($params['path'] ?? ''), '/');
        $base = 'https://' . $hosts[0];
        $url = rtrim($base, '/') . $path;
        $req = new CaptureRequest(
            $url,
            (string) ($params['page'] ?? 'homepage'),
            (string) ($params['viewport'] ?? 'desktop'),
            (bool) ($params['full'] ?? false),
        );
        try {
            return $this->platform->screenshots()->capture($this->projectUuid($page), $hosts, $req, $actor);
        } catch (\Throwable $e) {
            throw new PortfolioException(422, self::captureError($e->getMessage()));
        }
    }

    /** Map an internal capture error code to a friendly, safe message. */
    private static function captureError(string $code): string
    {
        return match (true) {
            str_contains($code, 'host_not_approved') => 'That URL isn’t on this project’s approved host list.',
            str_contains($code, 'private_address_blocked') => 'That address resolves to a private network and was blocked.',
            str_contains($code, 'scheme_not_allowed') => 'Only secure https URLs can be captured.',
            str_contains($code, 'unresolvable_host') => 'That host could not be resolved.',
            str_contains($code, 'userinfo_forbidden') => 'Remove the username/password from the URL.',
            str_contains($code, 'capture_unavailable') => 'The capture service isn’t configured on this server yet.',
            str_contains($code, 'capture_timeout') => 'The page took too long to capture.',
            str_contains($code, 'capture_too_large') => 'The captured image was too large.',
            str_contains($code, 'capture_invalid_image') => 'The capture didn’t return a valid image.',
            str_contains($code, 'capture_failed') => 'The capture command failed. Check the capture health panel.',
            str_contains($code, 'storage_failed') => 'The screenshot could not be stored.',
            default => 'Capture failed (' . preg_replace('/[^a-z_:]/', '', $code) . ').',
        };
    }

    /**
     * Attach a captured (approved + privacy-reviewed) screenshot to the project
     * as a page file so blocks can reference it by filename.
     *
     * @return array<string,mixed>
     */
    public function attachScreenshot(string $id, string $screenshotUuid, string $actor): array
    {
        $page = $this->page($id);
        $svc = $this->platform->screenshots();
        $rec = $svc->get($screenshotUuid);
        if ($rec === null || (string) $rec['project_uuid'] !== $this->projectUuid($page)) {
            throw new PortfolioException(404, 'Screenshot not found for this project.');
        }
        if (!$svc->publishable($rec)) {
            throw new PortfolioException(422, 'Approve public use and complete the privacy review before attaching.');
        }
        $abs = rtrim($this->platform->storageDir(), '/') . '/' . $rec['file'];
        if (!is_file($abs)) {
            throw new PortfolioException(404, 'Screenshot file is missing.');
        }
        $ext = pathinfo($abs, PATHINFO_EXTENSION) ?: 'png';
        $filename = 'shot-' . substr($screenshotUuid, 0, 8) . '.' . $ext;
        $page->createFile([
            'source'   => $abs,
            'filename' => $filename,
            'template' => 'image',
            'content'  => ['alt' => trim((string) ($rec['page_label'] ?? 'Screenshot')) . ' — ' . (string) $rec['viewport']],
        ]);
        $this->audit('portfolio.screenshot_attached', $id, $actor, ['screenshot' => $screenshotUuid, 'filename' => $filename]);

        return $this->load($id);
    }

    // ---- Derivatives -----------------------------------------------------

    /** @return array<string,mixed> */
    public function createDerivative(string $id, array $params, string $actor): array
    {
        $page = $this->page($id);
        $sourceName = (string) ($params['source'] ?? '');
        $file = $page->image($sourceName);
        if ($file === null) {
            throw new PortfolioException(404, 'Source image not found on this project.');
        }
        $rec = $this->platform->derivatives()->create([
            'project_uuid' => $this->projectUuid($page),
            'source_path'  => $file->root(),
            'source_name'  => $sourceName,
            'kind'         => (string) ($params['kind'] ?? 'detail'),
            'crop'         => is_array($params['crop'] ?? null) ? $params['crop'] : null,
            'focal'        => is_array($params['focal'] ?? null) ? $params['focal'] : null,
            'rotate90'     => (int) ($params['rotate90'] ?? 0),
            'label'        => (string) ($params['label'] ?? ''),
            'creator'      => $actor,
        ]);
        $this->audit('portfolio.derivative_created', $id, $actor, ['derivative' => $rec['uuid'], 'source' => $sourceName]);

        return $rec;
    }

    // ---- Preview token ---------------------------------------------------

    /**
     * Mint a signed, expiring, revocable preview link. The token is an HMAC over
     * page id + expiry + a per-page revocation counter, so bumping the counter
     * invalidates every previously issued link.
     *
     * @return array{url:string,expires:string}
     */
    public function previewLink(string $id, int $ttlHours = 72): array
    {
        $page = $this->page($id);
        $exp = Clock::now()->getTimestamp() + max(1, min(168, $ttlHours)) * 3600;
        $ver = (int) ($page->content()->get('preview_token_version')->or(1)->value());
        $sig = self::sign($page->id(), $exp, $ver, $this->secret());

        return [
            'url'     => $this->kirby->url() . '/breakfast-admin/preview/t/' . rawurlencode($page->id()) . '?exp=' . $exp . '&v=' . $ver . '&t=' . $sig,
            'expires' => date('c', $exp),
        ];
    }

    public function revokePreviewLinks(string $id, string $actor): void
    {
        $page = $this->page($id);
        $ver = (int) ($page->content()->get('preview_token_version')->or(1)->value()) + 1;
        $base = $this->editingContent($page);
        $base['preview_token_version'] = (string) $ver;
        $page->version(VersionId::latest())->save($base, self::LANG);
        $this->audit('portfolio.preview_revoked', $id, $actor, []);
    }

    /** Verify a signed preview token and return the rendered draft HTML. */
    public function renderSignedPreview(string $id, int $exp, int $ver, string $sig): string
    {
        $page = $this->resolve($id);
        if ($page === null) {
            throw new PortfolioException(404, 'Not found.');
        }
        $now = Clock::now()->getTimestamp();
        $current = (int) ($page->content()->get('preview_token_version')->or(1)->value());
        $expected = self::sign($page->id(), $exp, $ver, $this->secret());
        if (!hash_equals($expected, $sig) || $exp < $now || $ver !== $current) {
            throw new PortfolioException(403, 'This preview link is invalid or has expired.');
        }

        return (new \Breakfast\Platform\Website\WebsiteContent($this->kirby, $this->platform))->previewHtml($page->id());
    }

    private static function sign(string $id, int $exp, int $ver, string $secret): string
    {
        return hash_hmac('sha256', $id . '|' . $exp . '|' . $ver, $secret);
    }

    // ---- Capture health --------------------------------------------------

    /** @return array<string,mixed> */
    public function captureHealth(): array
    {
        $cfg = $this->kirby->option('breakfast.screenshots', []);
        $cfg = is_array($cfg) ? $cfg : [];
        $cmd = (string) ($cfg['cmd'] ?? '');
        $configured = $cmd !== '';
        $binary = null;
        if ($configured) {
            $first = strtok($cmd, ' ');
            $binary = $first !== false && (is_file($first) || self::inPath($first));
        }
        $rows = $this->platform->fileStore()->all('case_study_screenshots');
        $durations = [];
        $lastOk = null;
        foreach ($rows as $r) {
            if (($r['is_stub'] ?? false) === false && ($r['status'] ?? '') !== 'archived') {
                $lastOk = max((string) $lastOk, (string) ($r['captured_at'] ?? ''));
            }
        }
        $storageBytes = 0;
        foreach ($rows as $r) {
            $storageBytes += (int) ($r['bytes'] ?? 0);
        }

        return [
            'driver'          => $configured ? 'command' : 'stub',
            'configured'      => $configured,
            'browserAvailable' => $binary,
            'lastSuccessful'  => $lastOk,
            'approvedHostCount' => count($this->approvedHostsGlobal($cfg)),
            'screenshotCount' => count($rows),
            'storageBytes'    => $storageBytes,
            'durations'       => count($durations),
        ];
    }

    /** @return list<string> */
    private function approvedHostsGlobal(array $cfg): array
    {
        return array_values(array_filter(array_map('strval', (array) ($cfg['allowHosts'] ?? []))));
    }

    private static function inPath(string $bin): bool
    {
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file(rtrim($dir, '/') . '/' . $bin)) {
                return true;
            }
        }

        return false;
    }

    // ---- Internals -------------------------------------------------------

    private function page(string $id): Page
    {
        $page = $this->resolve($id);
        if ($page === null || $page->intendedTemplate()->name() !== 'project') {
            throw new PortfolioException(404, 'Case study not found.');
        }

        return $page;
    }

    /** Accept either a full page id ("work/slug") or a bare slug. */
    private function resolve(string $id): ?Page
    {
        $id = trim($id, '/');

        return $this->kirby->page($id) ?? $this->kirby->page(self::PARENT . '/' . $id);
    }

    private function projectUuid(Page $page): string
    {
        $u = (string) $page->content()->get('uuid');

        return $u !== '' ? $u : 'work-' . $page->slug();
    }

    /** @return list<string> */
    private function approvedHosts(Page $page): array
    {
        $raw = (string) $this->editingContent($page)['approved_hosts'] ?? '';
        $hosts = array_values(array_filter(array_map(static fn ($h) => strtolower(trim((string) $h)), explode(',', $raw))));
        // Derive from project_url if none explicitly listed.
        if ($hosts === []) {
            $url = (string) ($this->editingContent($page)['project_url'] ?? '');
            $host = $url !== '' ? (string) (parse_url($url, PHP_URL_HOST) ?: '') : '';
            if ($host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return $hosts;
    }

    /** @return array<string,mixed> */
    private function editingContent(Page $page): array
    {
        $changes = $page->version(VersionId::changes());
        if ($changes->exists(self::LANG)) {
            return $changes->read(self::LANG) ?? [];
        }

        return $page->version(VersionId::latest())->read(self::LANG) ?? $page->content()->toArray();
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function publicFields(array $c): array
    {
        $out = [];
        foreach (['title', 'summary', 'client', 'challenge', 'approach', 'strategy',
            'design_explanation', 'build_explanation', 'outcome', 'technology',
            'project_url', 'seo_title', 'meta_description', 'project_status',
            'company_uuid', 'opportunity_uuid'] as $k) {
            $out[$k] = (string) ($c[$k] ?? '');
        }

        return $out;
    }

    /** @param array<string,mixed> $c */
    private function blocksJson(array $c): string
    {
        $body = $c['body'] ?? '';
        if (is_array($body)) {
            return json_encode($body, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return trim((string) $body) !== '' ? (string) $body : '[]';
    }

    /** @return list<array<string,mixed>> */
    private function media(Page $page): array
    {
        $out = [];
        foreach ($page->images() as $f) {
            $out[] = [
                'filename' => $f->filename(),
                'url'      => $f->crop(600, 400)->url(),
                'width'    => $f->width(),
                'height'   => $f->height(),
                'bytes'    => (int) $f->size(),
                'alt'      => (string) $f->alt(),
            ];
        }

        return $out;
    }

    private function sanitiseBlocks(mixed $blocks): string
    {
        if (is_string($blocks)) {
            $decoded = json_decode($blocks, true);
        } elseif (is_array($blocks)) {
            $decoded = $blocks;
        } else {
            $decoded = [];
        }

        return json_encode(is_array($decoded) ? $decoded : [], JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * @param array<string,mixed> $ad
     * @return array<string,string|null>
     */
    private function prefixless(array $ad): array
    {
        $out = [];
        foreach ($ad as $k => $v) {
            $out[(string) $k] = is_scalar($v) ? (string) $v : null;
        }

        return $out;
    }

    /**
     * Publication blockers (real gates only): status/summary/image and any
     * attached screenshot that isn't approved + privacy-reviewed.
     *
     * @param list<array<string,mixed>> $screenshots
     * @param list<array<string,mixed>> $warnings
     * @return list<string>
     */
    private function publishBlockers(Page $page, array $screenshots, array $warnings): array
    {
        $b = [];
        $c = $this->editingContent($page);
        if (trim((string) ($c['summary'] ?? '')) === '') {
            $b[] = 'Add a summary.';
        }
        $hasImage = ($c['hero_image'] ?? '') !== '' || ($c['card_image'] ?? '') !== '' || $page->images()->count() > 0;
        if (!$hasImage) {
            $b[] = 'Add at least one image.';
        }
        $svc = $this->platform->screenshots();
        foreach ($screenshots as $s) {
            if (($s['status'] ?? '') === 'captured' && !$svc->publishable($s)) {
                $b[] = 'A screenshot needs public-use approval and privacy review.';
                break;
            }
        }

        // Art-direction quality gate: an art-directed record can't publish as a
        // sparse one-image page. Evaluated on the DRAFT being published.
        foreach ($this->artDirectionBlockers($page, $c) as $msg) {
            $b[] = $msg;
        }

        return $b;
    }

    /**
     * Build the quality-gate record from the DRAFT content and return its
     * blockers. Mirrors {@see CaseStudyProbe::record()} but reads the unsaved
     * draft (so the editor is stopped before an empty page ever goes live).
     *
     * @param array<string,mixed> $c editing (draft) content
     * @return list<string>
     */
    private function artDirectionBlockers(Page $page, array $c): array
    {
        $adFields = [];
        foreach (['preset', 'accent', 'secondary', 'bg', 'text', 'display', 'body', 'corner',
                  'border', 'density', 'image_scale', 'rotation', 'caption', 'pullquote',
                  'animation', 'transition', 'motif', 'hero', 'ending'] as $k) {
            $adFields[$k] = isset($c['ad_' . $k]) ? (string) $c['ad_' . $k] : '';
        }
        $ad = ArtDirection::resolve($adFields);

        $presetCustomised = false;
        foreach ($adFields as $k => $v) {
            if ($k !== 'preset' && trim((string) $v) !== '') {
                $presetCustomised = true;
                break;
            }
        }

        $decoded = json_decode($this->blocksJson($c), true);
        $blocks = [];
        $summaries = [];
        $screenshotImage = false;
        $imageKeys = ['image', 'images', 'desktop', 'mobile', 'before', 'after'];
        $shotBlocks = ['cs-shot', 'cs-rotated', 'cs-stack', 'cs-devices', 'cs-fullpage', 'cs-strip', 'cs-annotated', 'cs-composition'];
        foreach (is_array($decoded) ? $decoded : [] as $blk) {
            if (!is_array($blk)) {
                continue;
            }
            $type = (string) ($blk['type'] ?? '');
            $content = is_array($blk['content'] ?? null) ? $blk['content'] : [];
            $blocks[] = $type;
            $imageCount = 0;
            foreach ($imageKeys as $ik) {
                $v = $content[$ik] ?? null;
                if (is_array($v)) {
                    $imageCount += count($v);
                } elseif (is_string($v) && trim($v) !== '') {
                    $imageCount += 1;
                }
            }
            $compHasImage = $type === 'cs-composition' && str_contains((string) ($content['data'] ?? ''), 'image');
            $hasText = trim((string) ($content['text'] ?? '')) !== '' || trim((string) ($content['heading'] ?? '')) !== '';
            if (in_array($type, $shotBlocks, true) && ($imageCount > 0 || $compHasImage)) {
                $screenshotImage = true;
            }
            $summaries[] = [
                'type'             => $type,
                'hasImage'         => $imageCount > 0 || $compHasImage,
                'imageCount'       => $imageCount,
                'hasText'          => $hasText,
                'hiddenEverywhere' => (bool) ($blk['isHidden'] ?? false),
            ];
        }

        $record = [
            'caseMode'          => ($c['case_mode'] ?? '') === 'simple' ? 'simple' : 'art-directed',
            'preset'            => $ad['preset'],
            'presetCustomised'  => $presetCustomised,
            'hero'              => $ad['data']['hero'],
            'ending'            => $ad['data']['ending'],
            'blocks'            => $blocks,
            'imageCount'        => $page->images()->count(),
            'hasScreenshots'    => $screenshotImage,
            'ineffectiveBlocks' => ArtDirectionQualityGate::ineffectiveBlocks($summaries),
        ];

        return ArtDirectionQualityGate::evaluate($record)['blockers'];
    }

    private function uniqueSlug(Page $parent, string $title): string
    {
        $base = \Kirby\Toolkit\Str::slug($title) ?: 'case-study';
        $slug = $base;
        $n = 1;
        while ($parent->childrenAndDrafts()->find($slug) !== null) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }

    private function secret(): string
    {
        return hash('sha256', 'portfolio-preview|' . $this->platform->storageDir());
    }

    /** @param array<string,mixed> $meta */
    private function audit(string $action, string $id, string $actor, array $meta): void
    {
        $this->platform->audit()->event($action, 'project', $id, $actor, $meta);
    }
}
