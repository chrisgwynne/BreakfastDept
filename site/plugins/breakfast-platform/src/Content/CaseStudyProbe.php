<?php

declare(strict_types=1);

namespace Breakfast\Platform\Content;

use Kirby\Cms\Page;

/**
 * Thin Kirby adapter: pulls the image + block inputs a case study needs for
 * {@see CaseStudyWarnings} out of a Kirby page's dynamically-typed content API.
 * Isolated here (and excluded from static analysis, like the other Kirby
 * adapters) so the warning logic itself stays pure and analysed.
 */
final class CaseStudyProbe
{
    /**
     * @return array{images:list<array{label:string,bytes:int,hasAlt:bool}>,counts:array<string,int>}
     */
    public static function probe(Page $page): array
    {
        $images = [];
        foreach ($page->images() as $file) {
            $images[] = [
                'label'  => $file->filename(),
                'bytes'  => (int) $file->size(),
                'hasAlt' => $file->alt()->isNotEmpty(),
            ];
        }

        $counts = [];
        $body = $page->content()->get('body');
        if ($body->isNotEmpty()) {
            foreach ($body->toBlocks() as $block) {
                $type = (string) $block->type();
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        return ['images' => $images, 'counts' => $counts];
    }
}
