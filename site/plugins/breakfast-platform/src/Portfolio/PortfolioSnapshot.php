<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

/**
 * Builds the public-safe snapshot payload for a portfolio record — the ONLY
 * representation ever served to the public site. This is the trust boundary:
 * it is an explicit allow-list of public editorial fields. Internal
 * relationships, private notes, unapproved media, unapproved testimonials,
 * storage paths and every CRM/bookkeeping field are excluded by construction —
 * a new internal field can never leak simply by being added to the record.
 */
final class PortfolioSnapshot
{
    /**
     * @param array<string,mixed>       $record       a portfolio_records row
     * @param list<array<string,mixed>> $story        portfolio_story_sections rows
     * @param list<array<string,mixed>> $blocks       portfolio_blocks rows (not deleted)
     * @param list<array<string,mixed>> $media        portfolio_media rows
     * @param list<array<string,mixed>> $results      portfolio_results rows
     * @param array<string,mixed>|null  $testimonial  portfolio_testimonials row or null
     * @param list<array<string,mixed>> $taxonomies   linked portfolio_taxonomies rows
     * @return array<string,mixed>
     */
    public static function build(
        array $record,
        array $story,
        array $blocks,
        array $media,
        array $results,
        ?array $testimonial,
        array $taxonomies
    ): array {
        // Only images approved for public use, not archived, are ever included.
        $publicMedia = [];
        foreach ($media as $m) {
            if ((int) ($m['approved_for_public'] ?? 0) !== 1 || (int) ($m['archived'] ?? 0) === 1) {
                continue;
            }
            $publicMedia[(string) $m['uuid']] = self::publicMedia($m);
        }

        $services = [];
        $industries = [];
        $projectTypes = [];
        foreach ($taxonomies as $t) {
            $entry = ['label' => (string) $t['label'], 'slug' => (string) $t['slug']];
            if (($t['kind'] ?? '') === 'service') {
                $entry['service_slug'] = (string) ($t['service_slug'] ?? '');
                $services[] = $entry;
            } elseif (($t['kind'] ?? '') === 'industry') {
                $industries[] = $entry;
            } elseif (($t['kind'] ?? '') === 'project_type') {
                $projectTypes[] = $entry;
            }
        }

        // Visible story sections, media resolved to public variants only.
        $storyOut = [];
        foreach ($story as $s) {
            if ((int) ($s['visible'] ?? 1) !== 1 || trim((string) ($s['body'] ?? '')) === '') {
                continue;
            }
            $storyOut[] = [
                'key'     => (string) $s['section_key'],
                'heading' => (string) $s['heading'],
                'body'    => (string) $s['body'],
                'layout'  => (string) ($s['layout'] ?? 'standard'),
            ];
        }

        // Visible blocks, media refs filtered to approved public media only.
        $blocksOut = [];
        foreach ($blocks as $b) {
            if ((int) ($b['deleted'] ?? 0) === 1 || (int) ($b['visible'] ?? 1) !== 1) {
                continue;
            }
            $refs = self::decodeList((string) ($b['media_refs'] ?? '[]'));
            $resolved = [];
            foreach ($refs as $ref) {
                if (isset($publicMedia[$ref])) {
                    $resolved[] = $publicMedia[$ref];
                }
            }
            $blocksOut[] = [
                'type'    => (string) $b['type'],
                'content' => self::decodeAssoc((string) ($b['content'] ?? '{}')),
                'options' => self::decodeAssoc((string) ($b['options'] ?? '{}')),
                'media'   => $resolved,
            ];
        }

        $resultsOut = [];
        foreach ($results as $r) {
            if ((int) ($r['visible'] ?? 1) !== 1) {
                continue;
            }
            $resultsOut[] = [
                'value' => (string) $r['value'],
                'label' => (string) $r['label'],
                'note'  => (string) $r['note'],
            ];
        }

        // A testimonial is public ONLY when approved and visible.
        $testimonialOut = null;
        if ($testimonial !== null && (int) ($testimonial['approved'] ?? 0) === 1 && (int) ($testimonial['visible'] ?? 1) === 1) {
            $photo = null;
            $photoUuid = (string) ($testimonial['photo_media_uuid'] ?? '');
            if ($photoUuid !== '' && isset($publicMedia[$photoUuid])) {
                $photo = $publicMedia[$photoUuid];
            }
            $testimonialOut = [
                'quote'  => (string) $testimonial['quote'],
                'name'   => (string) $testimonial['person_name'],
                'role'   => (string) $testimonial['person_role'],
                'company' => (string) $testimonial['company'],
                'photo'  => $photo,
            ];
        }

        $cover = self::pickMedia($publicMedia, (string) ($record['cover_media_uuid'] ?? ''));
        $ogMedia = self::pickMedia($publicMedia, (string) ($record['og_media_uuid'] ?? '')) ?? $cover;

        $displayTitle = (string) $record['display_title'];
        $summary = (string) $record['summary'];

        return [
            'slug'                => (string) $record['slug'],
            'client_display_name' => (string) $record['client_display_name'],
            'display_title'       => $displayTitle,
            'card_title'          => (string) ($record['card_title'] ?: $displayTitle),
            'case_study_headline' => (string) ($record['case_study_headline'] ?: $displayTitle),
            'summary'             => $summary,
            'project_type'        => (string) $record['project_type'],
            'industry'            => (string) $record['industry'],
            'location'            => (string) $record['location'],
            'website_url'         => (string) $record['website_url'],
            'link_label'          => (string) ($record['link_label'] ?: 'Visit the site'),
            'completion_date'     => $record['completion_date'] ?? null,
            'launch_date'         => $record['launch_date'] ?? null,
            'published_at'        => $record['published_at'] ?? null,
            'accent'              => (string) ($record['accent'] ?: 'butter'),
            'animation'           => (string) ($record['animation'] ?: 'standard'),
            'template'            => (string) ($record['template'] ?: 'standard'),
            'featured'            => (int) ($record['featured'] ?? 0) === 1,
            'services'            => $services,
            'industries'          => $industries,
            'project_types'       => $projectTypes,
            'story'               => $storyOut,
            'blocks'              => $blocksOut,
            'results'             => $resultsOut,
            'testimonial'         => $testimonialOut,
            'gallery'             => array_values($publicMedia),
            'cover'               => $cover,
            'seo'                 => [
                'title'       => (string) ($record['seo_title'] ?: ($displayTitle . ' — ' . (string) $record['client_display_name'])),
                'description' => (string) ($record['seo_description'] ?: $summary),
                'og_title'    => (string) ($record['og_title'] ?: $displayTitle),
                'og_description' => (string) ($record['og_description'] ?: $summary),
                'og_image'    => $ogMedia['src'] ?? null,
                'robots'      => (string) ($record['robots'] ?: 'index'),
                'in_sitemap'  => (int) ($record['in_sitemap'] ?? 1) === 1,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    private static function publicMedia(array $m): array
    {
        $variants = self::decodeAssoc((string) ($m['variants'] ?? '{}'));

        return [
            'role'       => (string) ($m['role'] ?? 'gallery'),
            'device'     => (string) ($m['device'] ?? 'none'),
            'alt'        => (int) ($m['decorative'] ?? 0) === 1 ? '' : (string) ($m['alt'] ?? ''),
            'decorative' => (int) ($m['decorative'] ?? 0) === 1,
            'caption'    => (string) ($m['caption'] ?? ''),
            'focal'      => ['x' => (float) ($m['focal_x'] ?? 0.5), 'y' => (float) ($m['focal_y'] ?? 0.5)],
            'background' => (string) ($m['background'] ?? ''),
            'treatment'  => (string) ($m['treatment'] ?? 'auto'),
            'width'      => $m['width'] ?? null,
            'height'     => $m['height'] ?? null,
            'embed_url'  => (string) ($m['embed_url'] ?? ''),
            // Public variant paths only — never the internal storage key/original.
            'src'        => $variants['card'] ?? $variants['hero'] ?? $variants['original'] ?? null,
            'variants'   => $variants,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $publicMedia
     * @return array<string,mixed>|null
     */
    private static function pickMedia(array $publicMedia, string $uuid): ?array
    {
        if ($uuid !== '' && isset($publicMedia[$uuid])) {
            return $publicMedia[$uuid];
        }
        // Fall back to the first media flagged as cover, else the first image.
        foreach ($publicMedia as $m) {
            if (($m['role'] ?? '') === 'cover') {
                return $m;
            }
        }

        return $publicMedia === [] ? null : reset($publicMedia);
    }

    /** @return list<string> */
    private static function decodeList(string $json): array
    {
        $v = json_decode($json, true);

        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /** @return array<string,mixed> */
    private static function decodeAssoc(string $json): array
    {
        $v = json_decode($json, true);

        return is_array($v) ? $v : [];
    }
}
