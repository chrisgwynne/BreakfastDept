<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Portfolio & Case Studies — the editorial domain service.
 *
 * Owns the internal portfolio_record lifecycle. Status is the server-enforced
 * state machine in {@see PortfolioStatus}; every change is recorded as an
 * immutable portfolio_event (the API layer additionally writes a global audit
 * entry). The public-safe snapshot pipeline that materialises published work
 * for the website lives in {@see Publisher} (CP2); this service exposes the
 * validation and transition rules it builds on.
 *
 * Stable uuids are the only relationship key. The public `slug` is editable;
 * changing it retires the old slug into portfolio_redirects for a safe 301.
 */
final class Portfolio
{
    private const PLACEHOLDER_PATTERNS = ['lorem ipsum', 'todo', 'tbc', 'placeholder', 'xxxx', 'lorem'];

    public function __construct(private readonly Database $db)
    {
    }

    // ==================================================================
    // Read
    // ==================================================================

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!array_key_exists('include_archived', $filters) || $filters['include_archived'] === false) {
            if (empty($filters['status'])) {
                $where[] = "status != 'archived'";
            }
        }
        if (!empty($filters['company_uuid'])) {
            $where[] = 'company_uuid = :company';
            $params['company'] = (string) $filters['company_uuid'];
        }
        if (!empty($filters['featured'])) {
            $where[] = 'featured = 1';
        }
        if (array_key_exists('on_homepage', $filters) && $filters['on_homepage']) {
            $where[] = 'homepage_position IS NOT NULL';
        }
        $params['l'] = max(1, min(200, (int) ($filters['limit'] ?? 100)));
        $order = !empty($filters['on_homepage']) ? 'homepage_position ASC' : 'updated_at DESC';
        $rows = $this->db->all(
            'SELECT * FROM portfolio_records WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT :l',
            $params
        );

        return array_map(fn (array $r): array => $this->summariseRow($r), $rows);
    }

    /**
     * Counts per status for the navigation badges, plus a couple of operational
     * totals used by the dashboard.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (PortfolioStatus::ALL as $s) {
            $counts[$s] = 0;
        }
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM portfolio_records GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        $counts['all'] = (int) $this->db->scalar("SELECT COUNT(*) FROM portfolio_records WHERE status != 'archived'");
        $counts['on_homepage'] = (int) $this->db->scalar('SELECT COUNT(*) FROM portfolio_records WHERE homepage_position IS NOT NULL');

        return $counts;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $row = $this->db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]);
        if ($row === null) {
            return null;
        }
        $row['story']        = $this->db->all('SELECT * FROM portfolio_story_sections WHERE record_uuid = :u ORDER BY sort_order ASC', ['u' => $uuid]);
        $row['blocks']       = $this->db->all('SELECT * FROM portfolio_blocks WHERE record_uuid = :u AND deleted = 0 ORDER BY sort_order ASC', ['u' => $uuid]);
        $row['media']        = $this->db->all('SELECT * FROM portfolio_media WHERE record_uuid = :u AND archived = 0 ORDER BY sort_order ASC', ['u' => $uuid]);
        $row['results']      = $this->db->all('SELECT * FROM portfolio_results WHERE record_uuid = :u ORDER BY sort_order ASC', ['u' => $uuid]);
        $row['testimonial']  = $this->db->one('SELECT * FROM portfolio_testimonials WHERE record_uuid = :u LIMIT 1', ['u' => $uuid]);
        $row['taxonomies']   = $this->db->all(
            'SELECT t.* FROM portfolio_taxonomies t JOIN portfolio_record_taxonomies rt ON rt.taxonomy_uuid = t.uuid WHERE rt.record_uuid = :u ORDER BY rt.sort_order ASC',
            ['u' => $uuid]
        );
        $row['events']       = $this->db->all('SELECT * FROM portfolio_events WHERE record_uuid = :u ORDER BY created_at DESC LIMIT 100', ['u' => $uuid]);

        return $row;
    }

    // ==================================================================
    // Create / update
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $internalName = trim((string) ($data['internal_name'] ?? $data['project_title'] ?? $data['display_title'] ?? ''));
        if ($internalName === '') {
            throw new PortfolioException(422, 'Give the record an internal name so you can find it.', 'invalid');
        }
        $slug = $this->uniqueSlug((string) ($data['slug'] ?? $data['display_title'] ?? $internalName));
        $now  = Clock::nowIso();
        $uuid = Uuid::v4();

        return $this->db->transaction(function (Database $db) use ($data, $uuid, $slug, $internalName, $actor, $now): array {
            $db->run(
                'INSERT INTO portfolio_records
                    (uuid, slug, status, visibility, company_uuid, contact_uuid, project_uuid,
                     internal_name, project_title, client_display_name, display_title, card_title,
                     case_study_headline, summary, project_type, industry, location, website_url, link_label,
                     completion_date, launch_date, template, accent, animation, revision, created_by, updated_by, created_at, updated_at)
                 VALUES
                    (:uuid, :slug, :status, :visibility, :company, :contact, :project,
                     :internal, :ptitle, :client, :dtitle, :ctitle,
                     :headline, :summary, :ptype, :industry, :location, :website, :linklabel,
                     :completion, :launch, :template, :accent, :animation, 1, :actor, :actor, :now, :now)',
                [
                    'uuid' => $uuid, 'slug' => $slug, 'status' => PortfolioStatus::DRAFT, 'visibility' => 'private',
                    'company' => $data['company_uuid'] ?? null, 'contact' => $data['contact_uuid'] ?? null, 'project' => $data['project_uuid'] ?? null,
                    'internal' => $internalName, 'ptitle' => (string) ($data['project_title'] ?? ''),
                    'client' => (string) ($data['client_display_name'] ?? ''), 'dtitle' => (string) ($data['display_title'] ?? ''),
                    'ctitle' => (string) ($data['card_title'] ?? ''), 'headline' => (string) ($data['case_study_headline'] ?? ''),
                    'summary' => (string) ($data['summary'] ?? ''), 'ptype' => (string) ($data['project_type'] ?? ''),
                    'industry' => (string) ($data['industry'] ?? ''), 'location' => (string) ($data['location'] ?? ''),
                    'website' => (string) ($data['website_url'] ?? ''), 'linklabel' => (string) ($data['link_label'] ?? ''),
                    'completion' => $data['completion_date'] ?? null, 'launch' => $data['launch_date'] ?? null,
                    'template' => (string) ($data['template'] ?? 'standard'), 'accent' => (string) ($data['accent'] ?? 'butter'),
                    'animation' => (string) ($data['animation'] ?? 'standard'), 'actor' => $actor, 'now' => $now,
                ]
            );
            $this->event($db, $uuid, 'created', 'Record created', $actor);

            return $db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]) ?? [];
        });
    }

    /**
     * Update editorial fields with optimistic concurrency. The caller passes the
     * revision it last read; a mismatch means someone else saved in between.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, int $expectedRevision, string $actor): array
    {
        return $this->db->transaction(function (Database $db) use ($uuid, $data, $expectedRevision, $actor): array {
            $current = $db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]);
            if ($current === null) {
                throw new PortfolioException(404, 'That portfolio record no longer exists.', 'not_found');
            }
            if ((int) $current['revision'] !== $expectedRevision) {
                throw new PortfolioException(409, 'This record was changed elsewhere since you opened it. Reload to see the latest version.', 'revision_conflict');
            }

            // Editable text/relationship fields (public + internal). Status and
            // publication dates are NOT settable here — they move via transition().
            $editable = [
                'company_uuid', 'contact_uuid', 'project_uuid', 'internal_name', 'project_title',
                'client_display_name', 'display_title', 'card_title', 'case_study_headline', 'summary',
                'project_type', 'industry', 'location', 'website_url', 'link_label', 'completion_date',
                'launch_date', 'featured', 'template', 'accent', 'animation', 'cover_media_uuid',
                'seo_title', 'seo_description', 'og_title', 'og_description', 'og_media_uuid', 'robots', 'in_sitemap',
            ];
            $set = [];
            $params = ['u' => $uuid];
            foreach ($editable as $col) {
                if (array_key_exists($col, $data)) {
                    $set[] = "$col = :$col";
                    $params[$col] = is_bool($data[$col]) ? (int) $data[$col] : $data[$col];
                }
            }

            // A slug change retires the old slug into the redirect table.
            if (array_key_exists('slug', $data)) {
                $newSlug = $this->normaliseSlug((string) $data['slug']);
                if ($newSlug !== '' && $newSlug !== (string) $current['slug']) {
                    $newSlug = $this->uniqueSlug($newSlug, $uuid);
                    $this->retireSlug($db, $uuid, (string) $current['slug'], $newSlug);
                    $set[] = 'slug = :slug';
                    $params['slug'] = $newSlug;
                    $this->event($db, $uuid, 'slug_changed', 'Slug changed', $actor);
                }
            }

            $set[] = 'revision = revision + 1';
            $set[] = 'updated_by = :actor';
            $set[] = 'updated_at = :now';
            $params['actor'] = $actor;
            $params['now'] = Clock::nowIso();

            $db->run('UPDATE portfolio_records SET ' . implode(', ', $set) . ' WHERE uuid = :u', $params);
            $this->event($db, $uuid, 'updated', 'Record updated', $actor);

            return $db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]) ?? [];
        });
    }

    /**
     * Duplicate a record into a fresh draft. Story, blocks, results, taxonomies
     * and media rows are copied, but media public-use approval is RESET so a
     * human must re-confirm rights on the copy before it can publish.
     *
     * @return array<string,mixed>
     */
    public function duplicate(string $uuid, string $actor): array
    {
        return $this->db->transaction(function (Database $db) use ($uuid, $actor): array {
            $src = $db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]);
            if ($src === null) {
                throw new PortfolioException(404, 'That portfolio record no longer exists.', 'not_found');
            }
            $newUuid = Uuid::v4();
            $newSlug = $this->uniqueSlug(((string) $src['slug']) . '-copy');
            $now = Clock::nowIso();

            $db->run(
                'INSERT INTO portfolio_records
                    (uuid, slug, status, visibility, company_uuid, contact_uuid, project_uuid, internal_name,
                     project_title, client_display_name, display_title, card_title, case_study_headline, summary,
                     project_type, industry, location, website_url, link_label, completion_date, launch_date,
                     template, accent, animation, revision, created_by, updated_by, created_at, updated_at)
                 SELECT :nu, :ns, \'draft\', \'private\', company_uuid, contact_uuid, project_uuid,
                     internal_name || \' (copy)\', project_title, client_display_name, display_title, card_title,
                     case_study_headline, summary, project_type, industry, location, website_url, link_label,
                     completion_date, launch_date, template, accent, animation, 1, :actor, :actor, :now, :now
                 FROM portfolio_records WHERE uuid = :src',
                ['nu' => $newUuid, 'ns' => $newSlug, 'actor' => $actor, 'now' => $now, 'src' => $uuid]
            );

            // Story + results + taxonomy links copy verbatim.
            foreach ($db->all('SELECT * FROM portfolio_story_sections WHERE record_uuid = :u', ['u' => $uuid]) as $s) {
                $db->run(
                    'INSERT INTO portfolio_story_sections (uuid, record_uuid, section_key, heading, body, visible, layout, sort_order)
                     VALUES (:id, :r, :k, :h, :b, :v, :l, :o)',
                    ['id' => Uuid::v4(), 'r' => $newUuid, 'k' => $s['section_key'], 'h' => $s['heading'], 'b' => $s['body'], 'v' => $s['visible'], 'l' => $s['layout'], 'o' => $s['sort_order']]
                );
            }
            foreach ($db->all('SELECT * FROM portfolio_results WHERE record_uuid = :u', ['u' => $uuid]) as $r) {
                $db->run(
                    'INSERT INTO portfolio_results (uuid, record_uuid, value, label, note, is_numeric, source, verified, visible, sort_order)
                     VALUES (:id,:r,:val,:lab,:note,:num,:src,:ver,:vis,:ord)',
                    ['id' => Uuid::v4(), 'r' => $newUuid, 'val' => $r['value'], 'lab' => $r['label'], 'note' => $r['note'], 'num' => $r['is_numeric'], 'src' => $r['source'], 'ver' => $r['verified'], 'vis' => $r['visible'], 'ord' => $r['sort_order']]
                );
            }
            foreach ($db->all('SELECT * FROM portfolio_record_taxonomies WHERE record_uuid = :u', ['u' => $uuid]) as $t) {
                $db->run('INSERT INTO portfolio_record_taxonomies (record_uuid, taxonomy_uuid, sort_order) VALUES (:r,:t,:o)', ['r' => $newUuid, 't' => $t['taxonomy_uuid'], 'o' => $t['sort_order']]);
            }
            // Media copies with approval RESET (rights must be reconfirmed).
            foreach ($db->all('SELECT * FROM portfolio_media WHERE record_uuid = :u AND archived = 0', ['u' => $uuid]) as $m) {
                $db->run(
                    'INSERT INTO portfolio_media (uuid, record_uuid, file_uuid, source_file_uuid, kind, role, device, alt, decorative, caption, focal_x, focal_y, background, treatment, embed_url, downloadable, width, height, variants, approved_for_public, sort_order, created_by, created_at, updated_at)
                     VALUES (:id,:r,:f,:sf,:k,:ro,:d,:alt,:dec,:cap,:fx,:fy,:bg,:tr,:eu,:dl,:w,:h,:var,0,:ord,:actor,:now,:now)',
                    ['id' => Uuid::v4(), 'r' => $newUuid, 'f' => $m['file_uuid'], 'sf' => $m['source_file_uuid'], 'k' => $m['kind'], 'ro' => $m['role'], 'd' => $m['device'], 'alt' => $m['alt'], 'dec' => $m['decorative'], 'cap' => $m['caption'], 'fx' => $m['focal_x'], 'fy' => $m['focal_y'], 'bg' => $m['background'], 'tr' => $m['treatment'], 'eu' => $m['embed_url'], 'dl' => $m['downloadable'], 'w' => $m['width'], 'h' => $m['height'], 'var' => $m['variants'], 'ord' => $m['sort_order'], 'actor' => $actor, 'now' => $now]
                );
            }
            $this->event($db, $newUuid, 'created', 'Duplicated from ' . $uuid, $actor);

            return $db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $newUuid]) ?? [];
        });
    }

    /**
     * Set the homepage selection: an ordered list of record uuids. Only
     * published records may be featured; unpublished/archived are rejected. Any
     * record not in the list is removed from the homepage (without unpublishing).
     *
     * @param list<string> $orderedUuids
     * @return list<array<string,mixed>>
     */
    public function setHomepageSelection(array $orderedUuids, string $actor, int $max = 4): array
    {
        $orderedUuids = array_values(array_unique(array_filter(array_map('strval', $orderedUuids))));
        if (count($orderedUuids) > $max) {
            throw new PortfolioException(422, sprintf('The homepage shows at most %d projects.', $max), 'too_many');
        }

        return $this->db->transaction(function (Database $db) use ($orderedUuids, $actor): array {
            foreach ($orderedUuids as $uuid) {
                $rec = $db->one('SELECT status FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]);
                if ($rec === null) {
                    throw new PortfolioException(404, 'A selected record no longer exists.', 'not_found');
                }
                if ((string) $rec['status'] !== PortfolioStatus::PUBLISHED) {
                    throw new PortfolioException(422, 'Only published work can be featured on the homepage.', 'not_published');
                }
            }
            // Clear all, then set positions for the chosen few.
            $db->run('UPDATE portfolio_records SET homepage_position = NULL WHERE homepage_position IS NOT NULL');
            foreach ($orderedUuids as $i => $uuid) {
                $db->run('UPDATE portfolio_records SET homepage_position = :p, updated_at = :now WHERE uuid = :u', ['p' => $i + 1, 'now' => Clock::nowIso(), 'u' => $uuid]);
                $this->event($db, $uuid, 'homepage_feature_added', 'Featured on the homepage at position ' . ($i + 1), $actor);
            }

            return $this->list(['on_homepage' => true]);
        });
    }

    // ==================================================================
    // State machine
    // ==================================================================

    /**
     * Move a record to a new status. Invalid transitions are rejected here,
     * server-side. Publishing runs editorial validation first (unless an
     * explicit privileged override is supplied) — the actual public snapshot is
     * materialised by the Publisher, which the API wires in at CP2.
     *
     * @param array<string,mixed> $opts  detail, override(bool), override_reason, scheduled_at
     * @return array<string,mixed>
     */
    public function transition(string $uuid, string $to, string $actor, array $opts = []): array
    {
        if (!PortfolioStatus::isValid($to)) {
            throw new PortfolioException(422, 'Unknown status.', 'invalid_status');
        }

        return $this->db->transaction(function (Database $db) use ($uuid, $to, $actor, $opts): array {
            $record = $db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]);
            if ($record === null) {
                throw new PortfolioException(404, 'That portfolio record no longer exists.', 'not_found');
            }
            $from = (string) $record['status'];
            if ($from === $to) {
                return $record;
            }
            if (!PortfolioStatus::canTransition($from, $to)) {
                throw new PortfolioException(422, sprintf('Cannot move a %s record to %s.', $from, $to), 'invalid_transition');
            }

            // Publishing requires a clean validation pass unless overridden.
            if ($to === PortfolioStatus::PUBLISHED) {
                $blockers = $this->validateForPublish($uuid);
                $override = (bool) ($opts['override'] ?? false);
                if ($blockers !== [] && !$override) {
                    throw new PortfolioException(422, 'This record is not ready to publish. Resolve the blockers first.', 'validation_failed');
                }
                if ($blockers !== [] && $override) {
                    $reason = trim((string) ($opts['override_reason'] ?? ''));
                    if ($reason === '') {
                        throw new PortfolioException(422, 'An override reason is required to publish with unresolved blockers.', 'override_reason_required');
                    }
                    $this->event($db, $uuid, 'validation_overridden', 'Published with override: ' . $reason, $actor);
                }
            }

            $now = Clock::nowIso();
            $extra = ['status' => $to, 'visibility' => PortfolioStatus::visibilityFor($to), 'now' => $now, 'actor' => $actor, 'u' => $uuid];
            $cols = 'status = :status, visibility = :visibility, updated_at = :now, updated_by = :actor';

            if ($to === PortfolioStatus::SCHEDULED) {
                $at = trim((string) ($opts['scheduled_at'] ?? ''));
                if ($at === '') {
                    throw new PortfolioException(422, 'A publish time is required to schedule.', 'schedule_time_required');
                }
                $cols .= ', scheduled_at = :at';
                $extra['at'] = $at;
            }
            if ($to === PortfolioStatus::PUBLISHED) {
                $cols .= ', published_at = COALESCE(published_at, :pub), scheduled_at = NULL';
                $extra['pub'] = $now;
            }
            if ($to === PortfolioStatus::ARCHIVED) {
                $cols .= ', archived_at = :now';
            }
            if ($from === PortfolioStatus::ARCHIVED) {
                $cols .= ', archived_at = NULL';
            }

            $db->run('UPDATE portfolio_records SET ' . $cols . ' WHERE uuid = :u', $extra);

            // Publishing materialises a fresh public-safe snapshot and flips the
            // current pointer, all inside this transaction. If anything here
            // throws, the whole transaction rolls back and the previously
            // published snapshot (last-known-good) stays live and untouched.
            if ($to === PortfolioStatus::PUBLISHED) {
                $this->materialiseSnapshot($db, $uuid, $actor);
            }
            // Unpublishing / archiving takes the public representation offline
            // without destroying the snapshot history.
            if ($to === PortfolioStatus::UNPUBLISHED || $to === PortfolioStatus::ARCHIVED) {
                $db->run('UPDATE portfolio_snapshots SET is_current = 0 WHERE record_uuid = :u', ['u' => $uuid]);
            }

            $this->event($db, $uuid, 'status:' . $to, trim((string) ($opts['detail'] ?? ('Moved from ' . $from . ' to ' . $to))), $actor);

            return $db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]) ?? [];
        });
    }

    // ==================================================================
    // Publication validation (editorial blockers)
    // ==================================================================

    /**
     * Everything that must be true before a record may be published. Returned as
     * a flat list of human-readable blockers so the API/UI can show them in one
     * panel. The Publisher (CP2) reuses this.
     *
     * @return list<string>
     */
    public function validateForPublish(string $uuid): array
    {
        $r = $this->db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $uuid]);
        if ($r === null) {
            return ['Record not found.'];
        }
        $blockers = [];
        if (trim((string) $r['display_title']) === '') {
            $blockers[] = 'A public project title is required.';
        }
        if (trim((string) $r['client_display_name']) === '') {
            $blockers[] = 'A client display name is required.';
        }
        if (trim((string) $r['summary']) === '') {
            $blockers[] = 'A short public introduction is required.';
        }
        if (trim((string) $r['slug']) === '') {
            $blockers[] = 'A public slug is required.';
        }

        // At least one linked service taxonomy.
        $services = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM portfolio_record_taxonomies rt JOIN portfolio_taxonomies t ON t.uuid = rt.taxonomy_uuid WHERE rt.record_uuid = :u AND t.kind = :k',
            ['u' => $uuid, 'k' => 'service']
        );
        if ($services === 0) {
            $blockers[] = 'Select at least one service.';
        }

        // At least one approved public image, and every public non-decorative
        // image needs alt text; no referenced media may be archived.
        $media = $this->db->all('SELECT * FROM portfolio_media WHERE record_uuid = :u', ['u' => $uuid]);
        $publicImages = array_filter($media, static fn ($m) => (int) $m['approved_for_public'] === 1 && (int) $m['archived'] === 0 && $m['kind'] === 'image');
        if ($publicImages === []) {
            $blockers[] = 'At least one image approved for public use is required.';
        }
        foreach ($publicImages as $m) {
            if ((int) $m['decorative'] === 0 && trim((string) $m['alt']) === '') {
                $blockers[] = 'Every public image needs alt text (or must be marked decorative).';
                break;
            }
        }

        // At least one visible story section with a body.
        $story = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM portfolio_story_sections WHERE record_uuid = :u AND visible = 1 AND TRIM(body) != ''",
            ['u' => $uuid]
        );
        if ($story === 0) {
            $blockers[] = 'Add at least one part of the case-study story.';
        }

        // No placeholder text in the public fields.
        $haystack = strtolower(trim((string) $r['display_title'] . ' ' . (string) $r['summary'] . ' ' . (string) $r['card_title'] . ' ' . (string) $r['case_study_headline']));
        foreach (self::PLACEHOLDER_PATTERNS as $needle) {
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                $blockers[] = 'Remove placeholder text before publishing.';
                break;
            }
        }

        // A social image must be resolvable (explicit og, cover, or an approved image).
        $hasSocial = trim((string) $r['og_media_uuid']) !== '' || trim((string) $r['cover_media_uuid']) !== '' || $publicImages !== [];
        if (!$hasSocial) {
            $blockers[] = 'A social sharing image is required.';
        }

        return $blockers;
    }

    // ==================================================================
    // Slugs
    // ==================================================================

    public function normaliseSlug(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /** A unique, normalised slug, excluding $ignoreUuid (the record being renamed). */
    public function uniqueSlug(string $raw, ?string $ignoreUuid = null): string
    {
        $base = $this->normaliseSlug($raw);
        if ($base === '') {
            $base = 'project';
        }
        $slug = $base;
        $n = 1;
        while ($this->slugTaken($slug, $ignoreUuid)) {
            $n++;
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?string $ignoreUuid): bool
    {
        $row = $this->db->one('SELECT uuid FROM portfolio_records WHERE slug = :s', ['s' => $slug]);
        if ($row !== null && $row['uuid'] !== $ignoreUuid) {
            return true;
        }
        // A retired slug pointing at a DIFFERENT record also collides.
        $red = $this->db->one('SELECT record_uuid FROM portfolio_redirects WHERE old_slug = :s', ['s' => $slug]);

        return $red !== null && $red['record_uuid'] !== $ignoreUuid;
    }

    private function retireSlug(Database $db, string $uuid, string $oldSlug, string $newSlug): void
    {
        // The new slug must not remain a redirect target (avoid a loop).
        $db->run('DELETE FROM portfolio_redirects WHERE old_slug = :s', ['s' => $newSlug]);
        // Point any existing redirects that resolved to the old slug at the new one.
        $db->run('UPDATE portfolio_redirects SET old_slug = old_slug WHERE record_uuid = :u', ['u' => $uuid]);
        $db->run(
            'INSERT INTO portfolio_redirects (old_slug, record_uuid, created_at) VALUES (:s, :u, :now)
             ON CONFLICT(old_slug) DO UPDATE SET record_uuid = :u, created_at = :now',
            ['s' => $oldSlug, 'u' => $uuid, 'now' => Clock::nowIso()]
        );
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /**
     * Trim a list row down to the fields the admin list/nav needs.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function summariseRow(array $r): array
    {
        return [
            'uuid' => $r['uuid'], 'slug' => $r['slug'], 'status' => $r['status'], 'visibility' => $r['visibility'],
            'internal_name' => $r['internal_name'], 'display_title' => $r['display_title'], 'card_title' => $r['card_title'],
            'client_display_name' => $r['client_display_name'], 'summary' => $r['summary'],
            'featured' => (int) $r['featured'], 'homepage_position' => $r['homepage_position'],
            'project_type' => $r['project_type'], 'industry' => $r['industry'],
            'cover_media_uuid' => $r['cover_media_uuid'], 'published_at' => $r['published_at'], 'scheduled_at' => $r['scheduled_at'],
            'revision' => (int) $r['revision'], 'updated_at' => $r['updated_at'],
        ];
    }

    /**
     * Build the public-safe snapshot from the record's current state and flip it
     * to current in one step. The previous snapshot is retained (last-known-good).
     */
    private function materialiseSnapshot(Database $db, string $recordUuid, string $actor): void
    {
        $record = $db->one('SELECT * FROM portfolio_records WHERE uuid = :u', ['u' => $recordUuid]);
        if ($record === null) {
            throw new PortfolioException(404, 'Record vanished mid-publish.', 'not_found');
        }
        $story = $db->all('SELECT * FROM portfolio_story_sections WHERE record_uuid = :u ORDER BY sort_order ASC', ['u' => $recordUuid]);
        $blocks = $db->all('SELECT * FROM portfolio_blocks WHERE record_uuid = :u AND deleted = 0 ORDER BY sort_order ASC', ['u' => $recordUuid]);
        $media = $db->all('SELECT * FROM portfolio_media WHERE record_uuid = :u ORDER BY sort_order ASC', ['u' => $recordUuid]);
        $results = $db->all('SELECT * FROM portfolio_results WHERE record_uuid = :u ORDER BY sort_order ASC', ['u' => $recordUuid]);
        $testimonial = $db->one('SELECT * FROM portfolio_testimonials WHERE record_uuid = :u LIMIT 1', ['u' => $recordUuid]);
        $taxonomies = $db->all(
            'SELECT t.* FROM portfolio_taxonomies t JOIN portfolio_record_taxonomies rt ON rt.taxonomy_uuid = t.uuid WHERE rt.record_uuid = :u ORDER BY rt.sort_order ASC',
            ['u' => $recordUuid]
        );

        $payload = PortfolioSnapshot::build($record, $story, $blocks, $media, $results, $testimonial, $taxonomies);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new PortfolioException(500, 'Could not build the public snapshot.', 'snapshot_failed');
        }

        // Pointer swap: retire the old current, insert + mark the new one current.
        $db->run('UPDATE portfolio_snapshots SET is_current = 0 WHERE record_uuid = :u', ['u' => $recordUuid]);
        $db->run(
            'INSERT INTO portfolio_snapshots (uuid, record_uuid, slug, revision, payload, is_current, created_by, created_at)
             VALUES (:id, :r, :slug, :rev, :payload, 1, :actor, :now)',
            [
                'id' => Uuid::v4(), 'r' => $recordUuid, 'slug' => (string) $record['slug'],
                'rev' => (int) $record['revision'], 'payload' => $json, 'actor' => $actor, 'now' => Clock::nowIso(),
            ]
        );
    }

    // ==================================================================
    // Public reads (what the website serves — is_current snapshots only)
    // ==================================================================

    /**
     * The published, public-safe payload for a slug, or null. Only records that
     * are currently published (is_current snapshot) resolve; drafts, ready,
     * scheduled, unpublished and archived records are invisible here.
     *
     * @return array<string,mixed>|null
     */
    public function publicBySlug(string $slug): ?array
    {
        $row = $this->db->one(
            "SELECT s.payload FROM portfolio_snapshots s
             JOIN portfolio_records r ON r.uuid = s.record_uuid
             WHERE s.slug = :slug AND s.is_current = 1 AND r.status = 'published'",
            ['slug' => $slug]
        );
        if ($row === null) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Published records for the public /work listing, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(int $limit = 60): array
    {
        $rows = $this->db->all(
            "SELECT s.payload FROM portfolio_snapshots s
             JOIN portfolio_records r ON r.uuid = s.record_uuid
             WHERE s.is_current = 1 AND r.status = 'published'
             ORDER BY r.published_at DESC LIMIT :l",
            ['l' => max(1, min(200, $limit))]
        );
        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (is_array($payload)) {
                $out[] = $payload;
            }
        }

        return $out;
    }

    /**
     * Published records selected for the homepage, in homepage order. Never
     * returns unpublished/archived work (guarded by the is_current + status join).
     *
     * @return list<array<string,mixed>>
     */
    public function homepageSelection(int $limit = 4): array
    {
        $rows = $this->db->all(
            "SELECT s.payload FROM portfolio_snapshots s
             JOIN portfolio_records r ON r.uuid = s.record_uuid
             WHERE s.is_current = 1 AND r.status = 'published' AND r.homepage_position IS NOT NULL
             ORDER BY r.homepage_position ASC LIMIT :l",
            ['l' => max(1, min(12, $limit))]
        );
        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (is_array($payload)) {
                $out[] = $payload;
            }
        }

        return $out;
    }

    /**
     * The current slug a retired slug should 301 to, or null. Returns null if
     * the old slug is itself a live published slug (no redirect needed / no loop).
     */
    public function redirectFor(string $oldSlug): ?string
    {
        $live = $this->db->one("SELECT 1 FROM portfolio_records WHERE slug = :s AND status = 'published'", ['s' => $oldSlug]);
        if ($live !== null) {
            return null;
        }
        $red = $this->db->one('SELECT record_uuid FROM portfolio_redirects WHERE old_slug = :s', ['s' => $oldSlug]);
        if ($red === null) {
            return null;
        }
        $target = $this->db->one("SELECT slug FROM portfolio_records WHERE uuid = :u AND status = 'published'", ['u' => $red['record_uuid']]);
        if ($target === null || (string) $target['slug'] === $oldSlug) {
            return null; // target not public, or would loop
        }

        return (string) $target['slug'];
    }

    // ==================================================================
    // Scheduled publishing (idempotent — safe to run on any cadence)
    // ==================================================================

    /**
     * Publish every record whose scheduled time has arrived. Idempotent: a
     * record leaves the `scheduled` state the moment it publishes, so a repeated
     * run never republishes it. A record that fails to publish (e.g. validation
     * regressed) is left scheduled and reported, never half-published.
     *
     * @return array{published:list<string>, failed:array<string,string>}
     */
    public function publishDue(string $asOfIso, string $actor): array
    {
        $due = $this->db->all(
            "SELECT uuid FROM portfolio_records WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= :now ORDER BY scheduled_at ASC",
            ['now' => $asOfIso]
        );
        $published = [];
        $failed = [];
        foreach ($due as $row) {
            $uuid = (string) $row['uuid'];
            try {
                $this->transition($uuid, PortfolioStatus::PUBLISHED, $actor, ['detail' => 'Scheduled publish']);
                $published[] = $uuid;
            } catch (PortfolioException $e) {
                $failed[$uuid] = $e->getMessage();
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }

    // ==================================================================
    // Linked-project population (explicit opt-in; never auto-exposes internals)
    // ==================================================================

    /**
     * Public-safe field suggestions drawn from a linked delivery project. This
     * only ever SUGGESTS values for the editor to accept via update() — it never
     * writes them and never surfaces private project data (budgets, notes,
     * internal names, files). Returns [] if the project is unknown.
     *
     * @param array<string,mixed> $project a projects row (from Projects::find)
     * @return array<string,mixed>
     */
    public function projectSuggestions(array $project): array
    {
        if ($project === []) {
            return [];
        }

        return array_filter([
            'display_title' => trim((string) ($project['name'] ?? '')),
            'project_type'  => trim((string) ($project['project_type'] ?? '')),
            'completion_date' => $project['target_date'] ?? null,
            'launch_date'   => $project['target_date'] ?? null,
            // A public summary suggestion from the project description — the
            // editor still reviews/edits it before it becomes public.
            'summary'       => trim((string) ($project['description'] ?? '')),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private function event(Database $db, string $recordUuid, string $type, string $detail, string $actor): void
    {
        $db->run(
            'INSERT INTO portfolio_events (uuid, record_uuid, type, detail, actor, created_at) VALUES (:id, :r, :t, :d, :a, :now)',
            ['id' => Uuid::v4(), 'r' => $recordUuid, 't' => $type, 'd' => $detail, 'a' => $actor, 'now' => Clock::nowIso()]
        );
    }
}
