<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

/**
 * One-time, safe import of the existing Kirby "project" case-study content into
 * portfolio records. Everything imported lands as a DRAFT and is never
 * published automatically; concept/demo pieces are flagged for review, and an
 * import report is returned. Nothing is deleted.
 */
final class PortfolioImporter
{
    public function __construct(private readonly Portfolio $portfolio, private readonly string $contentDir)
    {
    }

    /**
     * @return array{imported:list<string>, skipped:list<string>, duplicate:list<string>, placeholder:list<string>, incomplete:list<string>, missing_media:list<string>}
     */
    public function import(string $actor, bool $dryRun = false): array
    {
        $report = ['imported' => [], 'skipped' => [], 'duplicate' => [], 'placeholder' => [], 'incomplete' => [], 'missing_media' => []];

        foreach ($this->findProjectFiles() as $dir => $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                $report['skipped'][] = $dir;
                continue;
            }
            $fields = $this->parse($raw);
            $title = trim((string) ($fields['title'] ?? ''));
            if ($title === '') {
                $report['incomplete'][] = $dir;
                continue;
            }
            // Folder name → stable public slug (drops the numeric sort prefix),
            // preserving the old public /work/{slug} URL where possible.
            $slug = preg_replace('/^\d+_/', '', basename($dir)) ?: basename($dir);

            if ($this->slugExists($slug)) {
                $report['duplicate'][] = $slug;
                continue;
            }

            $isConcept = in_array(strtolower((string) ($fields['project_status'] ?? '')), ['concept', 'internal', 'draft', 'demo'], true);
            if ($isConcept) {
                $report['placeholder'][] = $slug;
            }
            if (empty($fields['hero_image']) && empty($fields['card_image'])) {
                $report['missing_media'][] = $slug;
            }

            if ($dryRun) {
                $report['imported'][] = $slug . ' (dry-run)';
                continue;
            }

            $rec = $this->portfolio->create([
                'internal_name'       => $title . ($isConcept ? ' (imported concept — review)' : ' (imported — review)'),
                'slug'                => $slug,
                'display_title'       => $this->displayTitle($title),
                'client_display_name' => trim((string) ($fields['client'] ?? '')),
                'summary'             => trim((string) ($fields['summary'] ?? '')),
                'project_type'        => $isConcept ? 'concept' : trim((string) ($fields['project_type'] ?? '')),
            ], $actor);
            $uuid = (string) $rec['uuid'];

            // Map the old narrative fields onto structured story sections.
            $map = [
                'problem'   => $fields['challenge'] ?? '',
                'objective' => $fields['strategy'] ?? '',
                'approach'  => $fields['approach'] ?? '',
                'work'      => trim(((string) ($fields['design_explanation'] ?? '')) . "\n\n" . ((string) ($fields['build_explanation'] ?? ''))),
                'outcome'   => $fields['outcome'] ?? '',
            ];
            foreach ($map as $key => $body) {
                $body = trim((string) $body);
                if ($body !== '') {
                    $this->portfolio->upsertStorySection($uuid, $key, ['body' => '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>', 'visible' => true], $actor);
                }
            }

            $report['imported'][] = $slug;
        }

        return $report;
    }

    /** @return array<string,string> dir => project.txt path */
    private function findProjectFiles(): array
    {
        $out = [];
        foreach (['_drafts/work', '1_work', 'work'] as $rel) {
            $base = rtrim($this->contentDir, '/') . '/' . $rel;
            if (!is_dir($base)) {
                continue;
            }
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $childDir) {
                foreach (['project.txt', 'project.en.txt'] as $name) {
                    if (is_file($childDir . '/' . $name)) {
                        $out[$childDir] = $childDir . '/' . $name;
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = (string) preg_replace('/\n+----\n+/', "\n----\n", $raw);
        $fields = [];
        foreach (explode("\n----\n", $raw) as $block) {
            $block = trim($block);
            if ($block === '' || !str_contains($block, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $block, 2);
            $key = strtolower(trim($k));
            if (preg_match('/^[a-z0-9_]+$/', $key)) {
                $fields[$key] = trim($v);
            }
        }

        return $fields;
    }

    private function displayTitle(string $title): string
    {
        // Old titles were often "Client — long descriptive sentence"; keep the
        // client-facing lead, trimmed to something card-sized.
        $parts = preg_split('/\s+[—–-]\s+/', $title, 2) ?: [$title];

        return trim($parts[0]);
    }

    private function slugExists(string $slug): bool
    {
        // A record or a retired redirect already using the slug.
        return $this->portfolio->uniqueSlug($slug) !== $slug;
    }
}
