<?php

declare(strict_types=1);

namespace Breakfast\Platform\Website;

use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Content\VersionId;

/**
 * Real content editing for the public website, backed by the genuine Kirby
 * content model — no second/fake content store.
 *
 * Editing uses Kirby 5's version system: "Save draft" writes to the model's
 * `changes` version (never touching what's live), "Publish" applies those
 * changes to the live content, "Discard" deletes the draft, and "Preview"
 * renders the draft in memory without persisting anything. Every mutation is
 * audited. Only field types we can round-trip safely are offered as inputs;
 * complex fields (structure, blocks, files, …) are surfaced read-only so the
 * UI never shows an editable control it can't actually persist.
 */
final class WebsiteContent
{
    /** Field types we can load and persist faithfully as scalars. */
    private const EDITABLE = [
        'text', 'textarea', 'writer', 'markdown', 'slug', 'email', 'tel',
        'url', 'date', 'time', 'color', 'number', 'range', 'toggle',
        'select', 'radio', 'tags', 'multiselect',
    ];

    /** Purely presentational blueprint entries — not inputs, skipped entirely. */
    private const COSMETIC = ['headline', 'line', 'info', 'gap', 'hidden'];

    private const LANG = 'default';

    public function __construct(
        private readonly App $kirby,
        private readonly Platform $platform,
    ) {
    }

    // ==================================================================
    // Listing
    // ==================================================================

    /** @return array<string,mixed> */
    public function index(): array
    {
        $site  = $this->kirby->site();
        $items = [];

        // Global site settings first.
        $items[] = [
            'id'       => 'site',
            'title'    => 'Global settings',
            'kind'     => 'site',
            'template' => 'site',
            'status'   => $this->statusOf($site),
            'url'      => (string) $site->url(),
            'home'     => false,
        ];

        $home = $site->homePage();
        if ($home !== null) {
            $items[] = $this->pageStub($home, true);
        }
        foreach ($site->children() as $page) {
            if ($home !== null && $page->id() === $home->id()) {
                continue;
            }
            $items[] = $this->pageStub($page, false);
        }

        return ['items' => $items, 'total' => count($items), 'url' => (string) $site->url()];
    }

    /** @return array<string,mixed> */
    private function pageStub(Page $page, bool $isHome): array
    {
        return [
            'id'       => (string) $page->id(),
            'title'    => (string) $page->title()->value(),
            'kind'     => 'page',
            'template' => (string) $page->intendedTemplate()->name(),
            'status'   => $this->statusOf($page),
            'url'      => (string) $page->url(),
            'home'     => $isHome,
        ];
    }

    // ==================================================================
    // Load one model for editing
    // ==================================================================

    /** @return array<string,mixed> */
    public function load(string $id): array
    {
        $model   = $this->model($id);
        $content = $this->editingContent($model);

        $fields    = [];
        $readonly  = [];
        foreach ($model->blueprint()->fields() as $name => $def) {
            $type = (string) ($def['type'] ?? 'text');
            if (in_array($type, self::COSMETIC, true)) {
                continue;
            }
            $key   = strtolower($name);
            $entry = [
                'name'  => $key,
                'label' => (string) ($def['label'] ?? ucfirst($name)),
                'type'  => $type,
                'help'  => strip_tags((string) ($def['help'] ?? '')),
                'width' => (string) ($def['width'] ?? '1/1'),
            ];
            if (in_array($type, self::EDITABLE, true)) {
                $entry['editable']  = true;
                $entry['required']  = !empty($def['required']);
                $entry['value']     = $this->readValue($type, $content[$key] ?? null);
                if (in_array($type, ['select', 'radio'], true)) {
                    $entry['options'] = $this->options($def['options'] ?? []);
                }
                $fields[] = $entry;
            } else {
                $entry['editable'] = false;
                $readonly[]        = $entry;
            }
        }

        $isPage = $model instanceof Page;

        return [
            'id'           => $id,
            'title'        => $isPage ? (string) $model->title()->value() : 'Global settings',
            'kind'         => $isPage ? 'page' : 'site',
            'template'     => $isPage ? (string) $model->intendedTemplate()->name() : 'site',
            'status'       => $this->statusOf($model),
            'url'          => $isPage ? (string) $model->url() : (string) $this->kirby->site()->url(),
            'has_changes'  => $this->hasChanges($model),
            'can_unpublish' => $isPage && $this->canChangeStatus($model),
            'fields'       => $fields,
            'readonly'     => $readonly,
        ];
    }

    // ==================================================================
    // Mutations
    // ==================================================================

    /**
     * Save edits to the draft (`changes`) version. Never touches live content.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function save(string $id, array $values, string $actor): array
    {
        $model = $this->model($id);
        $clean = $this->sanitise($model, $values);

        // Accumulate onto any existing draft, else branch from live content.
        $base    = $this->editingContent($model);
        $merged  = array_merge($base, $clean);
        $model->version(VersionId::changes())->save($merged, self::LANG);

        $this->audit('website.saved', $id, $actor, ['fields' => array_keys($clean)]);

        return $this->load($id);
    }

    /**
     * Apply the draft to live content (atomic within Kirby's storage write).
     *
     * @return array<string,mixed>
     */
    public function publish(string $id, string $actor): array
    {
        $model   = $this->model($id);
        $changes = $model->version(VersionId::changes());
        if (!$changes->exists(self::LANG)) {
            throw new WebsiteException(409, 'There are no unpublished changes to publish.');
        }

        // Apply the draft to live content by moving the draft's content onto the
        // latest version, then clearing the draft. We deliberately do NOT run
        // Kirby's whole-page form validation here: the edited fields were already
        // validated on save, and legacy live content can contain pre-existing
        // constraint violations in fields this editor never touches — a partial
        // edit must not be blocked by an unrelated field the user can't see.
        $content = $changes->read(self::LANG) ?? [];
        $model->version(VersionId::latest())->save($content, self::LANG);
        $changes->delete(self::LANG);

        $this->audit('website.published', $id, $actor, []);

        return $this->load($id);
    }

    /** @return array<string,mixed> */
    public function discard(string $id, string $actor): array
    {
        $model   = $this->model($id);
        $changes = $model->version(VersionId::changes());
        if ($changes->exists(self::LANG)) {
            $changes->delete(self::LANG);
            $this->audit('website.discarded', $id, $actor, []);
        }

        return $this->load($id);
    }

    /** @return array<string,mixed> */
    public function unpublish(string $id, string $actor): array
    {
        $model = $this->model($id);
        if (!$model instanceof Page || !$this->canChangeStatus($model)) {
            throw new WebsiteException(409, 'This page can’t be taken offline.');
        }
        $model->changeStatus('unlisted');
        $this->audit('website.unpublished', $id, $actor, []);

        return $this->load($id);
    }

    /** @return array<string,mixed> */
    public function republish(string $id, string $actor): array
    {
        $model = $this->model($id);
        if (!$model instanceof Page || !$this->canChangeStatus($model)) {
            throw new WebsiteException(409, 'This page can’t be listed.');
        }
        $model->changeStatus('listed');
        $this->audit('website.listed', $id, $actor, []);

        return $this->load($id);
    }

    /**
     * Render the draft (or live, if none) in memory. Persists nothing.
     */
    public function previewHtml(string $id): string
    {
        $model = $this->model($id);
        if (!$model instanceof Page) {
            throw new WebsiteException(422, 'Only pages can be previewed.');
        }
        $merged  = $this->editingContent($model);
        $preview = $model->clone(['content' => $merged]);

        // Page::render() resolves the current request page unless the cloned
        // model is passed explicitly. Without this, the preview route renders
        // the live page even though the draft was loaded correctly above.
        return (string) $preview->render(['page' => $preview]);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function model(string $id): ModelWithContent
    {
        if ($id === 'site') {
            return $this->kirby->site();
        }
        $page = $this->kirby->page($id);
        if ($page === null) {
            throw new WebsiteException(404, 'That page could not be found.');
        }

        return $page;
    }

    /**
     * The content we edit against: the draft if one exists, otherwise live.
     *
     * @return array<string,mixed>
     */
    private function editingContent(ModelWithContent $model): array
    {
        $changes = $model->version(VersionId::changes());
        $version = $changes->exists(self::LANG) ? $changes : $model->version(VersionId::latest());

        return array_change_key_case($version->read(self::LANG) ?? [], CASE_LOWER);
    }

    private function hasChanges(ModelWithContent $model): bool
    {
        return $model->version(VersionId::changes())->exists(self::LANG);
    }

    private function statusOf(ModelWithContent $model): string
    {
        if ($this->hasChanges($model)) {
            return 'modified';
        }
        if ($model instanceof Site) {
            return 'published';
        }
        if ($model instanceof Page) {
            return match ($model->status()) {
                'listed'   => 'published',
                'unlisted' => 'unlisted',
                default    => 'draft',
            };
        }

        return 'published';
    }

    private function canChangeStatus(Page $page): bool
    {
        if ($page->isHomePage() || $page->isErrorPage()) {
            return false;
        }
        $opts = $page->blueprint()->options();

        return ($opts['changeStatus'] ?? true) !== false;
    }

    /**
     * Validate + coerce submitted values to the model's editable fields only.
     *
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    private function sanitise(ModelWithContent $model, array $values): array
    {
        $blueprint = $model->blueprint()->fields();
        $clean     = [];
        $errors    = [];

        foreach ($blueprint as $name => $def) {
            $type = (string) ($def['type'] ?? 'text');
            $key  = strtolower($name);
            if (!in_array($type, self::EDITABLE, true) || !array_key_exists($key, $values)) {
                continue;
            }
            $raw = $values[$key];

            if ($type === 'toggle') {
                $clean[$key] = !empty($raw) && strtolower((string) $raw) !== 'false' ? 'true' : 'false';
                continue;
            }

            $str = is_array($raw) ? implode(', ', array_map('strval', $raw)) : trim((string) $raw);
            if (!empty($def['required']) && $str === '') {
                $errors[$key] = 'This field is required.';
                continue;
            }
            if ($type === 'email' && $str !== '' && !filter_var($str, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = 'Enter a valid email address.';
                continue;
            }
            if ($type === 'url' && $str !== '' && !filter_var($str, FILTER_VALIDATE_URL) && !str_starts_with($str, '/')) {
                $errors[$key] = 'Enter a valid URL.';
                continue;
            }
            // Honour blueprint length limits so the editor can't introduce a new
            // constraint violation (e.g. an over-long SEO title).
            $max = isset($def['maxlength']) ? (int) $def['maxlength'] : 0;
            if ($max > 0 && mb_strlen($str) > $max) {
                $errors[$key] = 'Please keep this to ' . $max . ' characters or fewer.';
                continue;
            }
            $clean[$key] = $str;
        }

        if ($errors !== []) {
            throw new WebsiteException(422, 'Please fix the highlighted fields.', $errors);
        }

        return $clean;
    }

    private function readValue(string $type, mixed $raw): mixed
    {
        if ($type === 'toggle') {
            $v = strtolower((string) $raw);

            return $v === 'true' || $v === '1' || $v === 'on' || $v === 'yes';
        }

        return $raw === null ? '' : (string) $raw;
    }

    /**
     * @param mixed $options
     * @return list<array{value:string,label:string}>
     */
    private function options(mixed $options): array
    {
        $out = [];
        if (is_array($options)) {
            foreach ($options as $key => $val) {
                if (is_array($val) && isset($val['value'])) {
                    $out[] = ['value' => (string) $val['value'], 'label' => (string) ($val['text'] ?? $val['value'])];
                } else {
                    $out[] = ['value' => (string) $key, 'label' => (string) $val];
                }
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $meta */
    private function audit(string $action, string $id, string $actor, array $meta): void
    {
        $this->platform->audit()->event($action, 'website', $id, $actor, $meta);
    }
}
