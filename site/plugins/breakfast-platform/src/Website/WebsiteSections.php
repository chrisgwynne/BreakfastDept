<?php

declare(strict_types=1);

namespace Breakfast\Platform\Website;

use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\VersionId;

/**
 * Manage a page's content sections — the ordered blocks of its page-builder
 * field — as real, reorderable records.
 *
 * Every mutation (add / edit / reorder / delete) writes to the DRAFT (`changes`)
 * version only, exactly like the field editor, so it flows through the same
 * preview → publish → discard workflow and never touches the live site until
 * published. Optimistic concurrency: each read returns a hash of the current
 * blocks, and every write must present the matching hash — a stale edit made
 * against an out-of-date view is rejected (409) rather than silently clobbering
 * someone else's change.
 */
final class WebsiteSections
{
    private const LANG = 'default';

    /** Block types this editor can add, with their starter content. */
    private const ADDABLE = [
        'heading' => ['level' => 'h2', 'text' => 'New heading'],
        'text'    => ['text' => '<p>New text section — edit me.</p>'],
        'quote'   => ['text' => 'A memorable quote.', 'citation' => ''],
        'callout' => ['variant' => 'info', 'title' => 'Note', 'text' => '<p>Something worth highlighting.</p>'],
        'divider' => [],
    ];

    /** Which content key on each block type holds its main editable text. */
    private const TEXT_KEY = [
        'heading' => 'text',
        'text'    => 'text',
        'quote'   => 'text',
        'callout' => 'text',
    ];

    public function __construct(
        private readonly App $kirby,
        private readonly Platform $platform,
    ) {
    }

    /**
     * List a page's sections plus a concurrency hash.
     *
     * @return array<string,mixed>
     */
    public function list(string $pageId): array
    {
        $page  = $this->page($pageId);
        $field = $this->blocksField($page);
        $blocks = $this->readBlocks($page, $field);

        return [
            'id'       => $pageId,
            'field'    => $field,
            'sections' => array_map([$this, 'sectionRow'], $blocks),
            'hash'     => $this->hash($blocks),
            'addable'  => array_keys(self::ADDABLE),
        ];
    }

    /**
     * Reorder the sections to match the given list of block ids.
     *
     * @param list<string> $orderedIds
     * @return array<string,mixed>
     */
    public function reorder(string $pageId, array $orderedIds, string $expectedHash, string $actor): array
    {
        $page   = $this->page($pageId);
        $field  = $this->blocksField($page);
        $blocks = $this->guardedBlocks($page, $field, $expectedHash);

        $byId = [];
        foreach ($blocks as $b) {
            $byId[(string) ($b['id'] ?? '')] = $b;
        }
        // Keep exactly the same set of blocks — reject an order that adds/drops any.
        if (count($orderedIds) !== count($byId) || array_diff($orderedIds, array_keys($byId)) !== []) {
            throw new WebsiteException(422, 'The section order must include every existing section exactly once.');
        }
        $reordered = array_map(static fn (string $id): array => $byId[$id], $orderedIds);

        return $this->persist($page, $field, $reordered, 'website.section_reordered', $actor);
    }

    /**
     * Append a new section of a supported type.
     *
     * @return array<string,mixed>
     */
    public function add(string $pageId, string $type, string $expectedHash, string $actor): array
    {
        if (!isset(self::ADDABLE[$type])) {
            throw new WebsiteException(422, 'That section type can’t be added here.');
        }
        $page   = $this->page($pageId);
        $field  = $this->blocksField($page);
        $blocks = $this->guardedBlocks($page, $field, $expectedHash);

        $blocks[] = [
            'id'      => $this->newId(),
            'type'    => $type,
            'content' => self::ADDABLE[$type],
        ];

        return $this->persist($page, $field, $blocks, 'website.section_added', $actor);
    }

    /**
     * Edit a section's primary text (heading/text/quote/callout).
     *
     * @return array<string,mixed>
     */
    public function updateSection(string $pageId, string $blockId, string $text, string $expectedHash, string $actor): array
    {
        $page   = $this->page($pageId);
        $field  = $this->blocksField($page);
        $blocks = $this->guardedBlocks($page, $field, $expectedHash);

        $found = false;
        foreach ($blocks as &$b) {
            if ((string) ($b['id'] ?? '') === $blockId) {
                $type = (string) ($b['type'] ?? '');
                $key  = self::TEXT_KEY[$type] ?? null;
                if ($key === null) {
                    throw new WebsiteException(422, 'This section type isn’t editable here — use the advanced editor.');
                }
                $b['content'] = is_array($b['content'] ?? null) ? $b['content'] : [];
                $b['content'][$key] = $text;
                $found = true;
                break;
            }
        }
        unset($b);
        if (!$found) {
            throw new WebsiteException(404, 'That section could not be found.');
        }

        return $this->persist($page, $field, $blocks, 'website.section_updated', $actor);
    }

    /**
     * Delete a section.
     *
     * @return array<string,mixed>
     */
    public function remove(string $pageId, string $blockId, string $expectedHash, string $actor): array
    {
        $page   = $this->page($pageId);
        $field  = $this->blocksField($page);
        $blocks = $this->guardedBlocks($page, $field, $expectedHash);

        $kept = array_values(array_filter($blocks, static fn (array $b): bool => (string) ($b['id'] ?? '') !== $blockId));
        if (count($kept) === count($blocks)) {
            throw new WebsiteException(404, 'That section could not be found.');
        }

        return $this->persist($page, $field, $kept, 'website.section_deleted', $actor);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function page(string $id): Page
    {
        $page = $this->kirby->page($id);
        if ($page === null) {
            throw new WebsiteException(404, 'That page could not be found.');
        }

        return $page;
    }

    /** The name of the page's first blocks-type field, or 404 if it has none. */
    private function blocksField(Page $page): string
    {
        foreach ($page->blueprint()->fields() as $name => $def) {
            if ((string) ($def['type'] ?? '') === 'blocks') {
                return strtolower((string) $name);
            }
        }
        throw new WebsiteException(422, 'This page has no reorderable sections.');
    }

    /**
     * Read the blocks array from the draft if present, else live content.
     *
     * @return list<array<string,mixed>>
     */
    private function readBlocks(Page $page, string $field): array
    {
        $changes = $page->version(VersionId::changes());
        $version = $changes->exists(self::LANG) ? $changes : $page->version(VersionId::latest());
        $content = array_change_key_case($version->read(self::LANG) ?? [], CASE_LOWER);
        $raw     = $content[$field] ?? '';
        if (is_array($raw)) {
            return array_values($raw);
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Read blocks and enforce the optimistic-concurrency hash.
     *
     * @return list<array<string,mixed>>
     */
    private function guardedBlocks(Page $page, string $field, string $expectedHash): array
    {
        $blocks = $this->readBlocks($page, $field);
        if ($this->hash($blocks) !== $expectedHash) {
            throw new WebsiteException(409, 'These sections changed since you loaded them. Reload and try again.');
        }

        return $blocks;
    }

    /**
     * Save the mutated blocks onto the draft version and return the fresh list.
     *
     * @param list<array<string,mixed>> $blocks
     * @return array<string,mixed>
     */
    private function persist(Page $page, string $field, array $blocks, string $event, string $actor): array
    {
        $changes = $page->version(VersionId::changes());
        $base    = $changes->exists(self::LANG)
            ? ($changes->read(self::LANG) ?? [])
            : ($page->version(VersionId::latest())->read(self::LANG) ?? []);

        // Preserve the original field key casing if it already exists.
        $key = $field;
        foreach (array_keys($base) as $existing) {
            if (strtolower((string) $existing) === $field) {
                $key = (string) $existing;
                break;
            }
        }
        $base[$key] = json_encode(array_values($blocks), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        $changes->save($base, self::LANG);

        $this->platform->audit()->event($event, 'website', (string) $page->id(), $actor, ['field' => $field, 'count' => count($blocks)]);

        return $this->list((string) $page->id());
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function sectionRow(array $block): array
    {
        $type    = (string) ($block['type'] ?? 'unknown');
        $content = is_array($block['content'] ?? null) ? $block['content'] : [];
        $key     = self::TEXT_KEY[$type] ?? null;
        $text    = $key !== null ? trim(strip_tags((string) ($content[$key] ?? ''))) : '';

        return [
            'id'       => (string) ($block['id'] ?? ''),
            'type'     => $type,
            'title'    => (string) ($content['title'] ?? '') ?: ucfirst($type),
            'summary'  => mb_substr($text, 0, 120),
            'editable' => $key !== null,
            'text'     => $key !== null ? (string) ($content[$key] ?? '') : '',
        ];
    }

    /** @param list<array<string,mixed>> $blocks */
    private function hash(array $blocks): string
    {
        return substr(hash('sha256', json_encode($blocks) ?: ''), 0, 16);
    }

    private function newId(): string
    {
        return 'b' . bin2hex(random_bytes(6));
    }
}
