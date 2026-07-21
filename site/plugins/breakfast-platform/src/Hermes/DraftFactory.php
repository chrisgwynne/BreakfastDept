<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

use Kirby\Cms\App as Kirby;
use Throwable;

/**
 * Creates UNPUBLISHED Kirby draft pages on behalf of Hermes.
 *
 * This is the only way Hermes may write website content. Drafts are created
 * under the correct parent with the correct template and are never listed or
 * published — a human must review and publish them in the Panel. No existing
 * content is ever modified or deleted.
 */
final class DraftFactory
{
    /** @var array<string,array{parent:string,template:string}> */
    private const TYPES = [
        'journal' => ['parent' => 'journal', 'template' => 'article'],
        'project' => ['parent' => 'work',    'template' => 'project'],
        'page'    => ['parent' => '',         'template' => 'page'],
    ];

    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,id?:string,error?:string}
     */
    public function create(string $type, array $data): array
    {
        if (isset(self::TYPES[$type]) === false) {
            return ['ok' => false, 'error' => 'unknown_type'];
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'title_required'];
        }

        if (class_exists(Kirby::class) === false || Kirby::instance(null, true) === null) {
            return ['ok' => false, 'error' => 'kirby_unavailable'];
        }

        $kirby  = Kirby::instance();
        $config = self::TYPES[$type];

        $parent = $config['parent'] === '' ? $kirby->site() : $kirby->page($config['parent']);
        if ($parent === null) {
            return ['ok' => false, 'error' => 'parent_missing'];
        }

        // Whitelist the content fields Hermes may set. Everything else is ignored.
        $content = ['title' => mb_substr($title, 0, 200)];
        foreach (['excerpt', 'summary', 'standfirst', 'meta_description', 'seo_title'] as $field) {
            if (isset($data[$field])) {
                $content[$field] = mb_substr((string) $data[$field], 0, 2000);
            }
        }

        // Body text becomes a single rich-text block (safe, structured).
        if (isset($data['body'])) {
            $content['hermes_body'] = mb_substr((string) $data['body'], 0, 20000);
        }

        try {
            // impersonate 'kirby' = a system-level actor that bypasses no
            // permissions beyond creating a draft; the page is created with
            // status draft and is never published here.
            $page = $kirby->impersonate('kirby', function () use ($parent, $config, $content) {
                return $parent->createChild([
                    'slug'     => \Kirby\Toolkit\Str::slug($content['title']) . '-' . substr(md5((string) microtime(true)), 0, 6),
                    'template' => $config['template'],
                    'draft'    => true,
                    'content'  => $content,
                ]);
            });

            return ['ok' => true, 'id' => $page->uuid()->id()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'create_failed'];
        }
    }
}
