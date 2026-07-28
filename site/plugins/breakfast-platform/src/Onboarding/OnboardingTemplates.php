<?php

declare(strict_types=1);

namespace Breakfast\Platform\Onboarding;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Versioned onboarding templates + form builder, stored as flat files.
 *
 * Publishing a version freezes its sections, questions, options, conditions and
 * mapping rules. Instances bind to an exact frozen version, so later edits (a
 * new draft version) never mutate in-flight answers. Conditions are validated
 * server-side and rejected if they reference removed questions or form a cycle.
 *
 * Each template is one JSON record whose versions — and each version's full
 * frozen structure — live embedded in that record as native arrays.
 */
final class OnboardingTemplates
{
    private const COLLECTION = 'onboarding_templates';

    /** @var list<string> */
    public const TYPES = ['short_text', 'long_text', 'email', 'phone', 'url', 'number', 'currency', 'date', 'single_choice', 'multi_choice', 'yes_no', 'address', 'file', 'image', 'info', 'heading'];

    public function __construct(private readonly FileStore $store)
    {
    }

    // ==================================================================
    // Built-ins
    // ==================================================================

    /** @return array<string,array<string,mixed>> */
    public static function builtins(): array
    {
        $base = static fn (string $name, string $type): array => [
            'name' => $name, 'category' => $type, 'project_type' => $type,
            'sections' => [
                ['key' => 'business', 'title' => 'Business details'],
                ['key' => 'project', 'title' => 'Project goals'],
                ['key' => 'access', 'title' => 'Access & technical'],
            ],
            'questions' => [
                ['key' => 'business_name', 'section' => 'business', 'type' => 'short_text', 'label' => 'Business name', 'required' => true],
                ['key' => 'business_phone', 'section' => 'business', 'type' => 'phone', 'label' => 'Best contact phone'],
                ['key' => 'website_url', 'section' => 'business', 'type' => 'url', 'label' => 'Current website'],
                ['key' => 'goals', 'section' => 'project', 'type' => 'long_text', 'label' => 'What are your goals for this project?', 'required' => true],
                ['key' => 'has_ecommerce', 'section' => 'project', 'type' => 'yes_no', 'label' => 'Will this project include an online shop?'],
                // Conditional: only shown when has_ecommerce = yes.
                ['key' => 'products_count', 'section' => 'project', 'type' => 'number', 'label' => 'Roughly how many products?', 'condition' => ['op' => 'and', 'rules' => [['q' => 'has_ecommerce', 'cmp' => 'equals', 'value' => 'yes']]]],
                ['key' => 'registrar', 'section' => 'access', 'type' => 'short_text', 'label' => 'Domain registrar'],
                ['key' => 'brand_assets', 'section' => 'access', 'type' => 'file', 'label' => 'Upload your logo / brand assets'],
            ],
            'mappings' => [
                ['question' => 'business_phone', 'target' => 'contact.phone', 'mode' => 'direct'],
                ['question' => 'website_url', 'target' => 'company.website', 'mode' => 'direct'],
                ['question' => 'goals', 'target' => 'project.client_summary', 'mode' => 'direct'],
            ],
        ];

        return [
            'brochure_website'    => $base('Brochure website onboarding', 'brochure'),
            'ecommerce_website'   => $base('Ecommerce website onboarding', 'ecommerce'),
            'landing_page'        => $base('Landing page onboarding', 'landing'),
            'website_redesign'    => $base('Website redesign onboarding', 'redesign'),
            'seo_project'         => $base('SEO project onboarding', 'seo'),
            'hosting_migration'   => $base('Hosting migration onboarding', 'migration'),
            'website_maintenance' => $base('Website maintenance onboarding', 'maintenance'),
            'branding_project'    => $base('Branding project onboarding', 'branding'),
            'general_digital'     => $base('General digital project onboarding', 'general'),
        ];
    }

    public function seedBuiltins(): void
    {
        foreach (self::builtins() as $slug => $def) {
            // Deterministic id keeps seeding idempotent without a UNIQUE(slug).
            $uuid = sha1('onboarding-builtin:' . $slug);
            if ($this->store->exists(self::COLLECTION, $uuid)) {
                continue;
            }
            $now = Clock::nowIso();
            $version = $this->buildVersion(1, 'published', 'Built-in', 'system', $now, $def);
            $this->store->putIfAbsent(self::COLLECTION, $uuid, [
                'uuid'            => $uuid,
                'slug'            => $slug,
                'name'            => (string) $def['name'],
                'description'     => (string) ($def['description'] ?? ''),
                'category'        => (string) $def['category'],
                'project_type'    => (string) $def['project_type'],
                'current_version' => 1,
                'builtin'         => 1,
                'archived'        => 0,
                'versions'        => [$version],
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    // ==================================================================
    // Read
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $this->seedBuiltins();
        $rows = array_values(array_filter($this->store->all(self::COLLECTION), static fn (array $t): bool => (int) ($t['archived'] ?? 0) === 0));
        usort($rows, static fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $t = $this->store->find(self::COLLECTION, $uuid);
        if ($t === null) {
            return null;
        }
        $versions = is_array($t['versions'] ?? null) ? array_values($t['versions']) : [];
        usort($versions, static fn ($a, $b) => (int) ($b['version'] ?? 0) <=> (int) ($a['version'] ?? 0));
        $t['versions'] = $versions;

        return $t;
    }

    /**
     * The full frozen structure for a specific version.
     *
     * @return array<string,mixed>|null
     */
    public function versionStructure(string $templateUuid, int $version): ?array
    {
        $t = $this->store->find(self::COLLECTION, $templateUuid);
        if ($t === null) {
            return null;
        }
        foreach (is_array($t['versions'] ?? null) ? $t['versions'] : [] as $v) {
            if ((int) ($v['version'] ?? 0) === $version) {
                return $v;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function publishedStructure(string $templateUuid): ?array
    {
        $t = $this->store->find(self::COLLECTION, $templateUuid);
        if ($t === null || (int) ($t['current_version'] ?? 0) === 0) {
            return null;
        }

        return $this->versionStructure($templateUuid, (int) $t['current_version']);
    }

    // ==================================================================
    // Authoring
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new OnboardingException(422, 'Enter a template name.');
        }
        $slug = trim((string) ($data['slug'] ?? '')) ?: preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
        $slug = trim((string) $slug, '_');
        if ($slug === '' || $this->slugTaken((string) $slug)) {
            $slug = ($slug ?: 'template') . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $version = $this->buildVersion(1, 'draft', '', $actor, $now, ['sections' => $data['sections'] ?? [], 'questions' => $data['questions'] ?? [], 'mappings' => $data['mappings'] ?? []]);
        $this->store->put(self::COLLECTION, [
            'uuid'            => $uuid,
            'slug'            => (string) $slug,
            'name'            => $name,
            'description'     => (string) ($data['description'] ?? ''),
            'category'        => (string) ($data['category'] ?? ''),
            'project_type'    => (string) ($data['project_type'] ?? ''),
            'current_version' => 0,
            'builtin'         => 0,
            'archived'        => 0,
            'versions'        => [$version],
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function newDraftVersion(string $templateUuid, string $actor): array
    {
        $t = $this->store->find(self::COLLECTION, $templateUuid);
        if ($t === null) {
            throw new OnboardingException(404, 'Template not found.');
        }
        $latest = 0;
        foreach (is_array($t['versions'] ?? null) ? $t['versions'] : [] as $v) {
            $latest = max($latest, (int) ($v['version'] ?? 0));
        }
        $next = $latest + 1;
        $now = Clock::nowIso();
        $prev = $latest > 0 ? $this->versionStructure($templateUuid, $latest) : null;
        $version = $this->buildVersion($next, 'draft', '', $actor, $now, $prev !== null ? $this->structureToDef($prev) : []);
        $this->store->update(self::COLLECTION, $templateUuid, static function (array $row) use ($version, $now): array {
            $row['versions'][] = $version;
            $row['updated_at'] = $now;

            return $row;
        });

        return $this->find($templateUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function publish(string $templateUuid, int $version, string $actor): array
    {
        $structure = $this->versionStructure($templateUuid, $version);
        if ($structure === null) {
            throw new OnboardingException(404, 'Template version not found.');
        }
        if ((string) $structure['status'] === 'published') {
            throw new OnboardingException(409, 'That version is already published.');
        }
        $this->validateConditions($structure);
        $now = Clock::nowIso();
        $this->store->update(self::COLLECTION, $templateUuid, static function (array $row) use ($version, $now): array {
            $versions = is_array($row['versions'] ?? null) ? $row['versions'] : [];
            foreach ($versions as &$v) {
                if ((int) ($v['version'] ?? 0) === $version) {
                    $v['status']       = 'published';
                    $v['published_at'] = $now;
                }
            }
            unset($v);
            $row['versions']        = $versions;
            $row['current_version'] = $version;
            $row['updated_at']      = $now;

            return $row;
        });

        return $this->find($templateUuid) ?? [];
    }

    /** Reject conditions that reference unknown questions or form a cycle. */
    /** @param array<string,mixed> $structure */
    private function validateConditions(array $structure): void
    {
        $keys = array_map(static fn (array $q): string => (string) $q['qkey'], $structure['questions']);
        $refs = [];
        foreach ($structure['questions'] as $q) {
            $cond = trim((string) $q['condition']);
            $referenced = $cond === '' ? [] : $this->referencedKeys(json_decode($cond, true));
            foreach ($referenced as $r) {
                if (!in_array($r, $keys, true)) {
                    throw new OnboardingException(422, 'A condition references an unknown question: ' . $r);
                }
            }
            $refs[(string) $q['qkey']] = $referenced;
        }
        if (OnboardingConditions::hasCircularVisibility($refs)) {
            throw new OnboardingException(422, 'The conditions form a circular visibility dependency.');
        }
    }

    /**
     * @param mixed $group
     * @return list<string>
     */
    private function referencedKeys(mixed $group): array
    {
        if (!is_array($group)) {
            return [];
        }
        $out = [];
        foreach (is_array($group['rules'] ?? null) ? $group['rules'] : [] as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (isset($rule['op'])) {
                $out = array_merge($out, $this->referencedKeys($rule));
            } elseif (isset($rule['q'])) {
                $out[] = (string) $rule['q'];
            }
        }

        return array_values(array_unique($out));
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function slugTaken(string $slug): bool
    {
        foreach ($this->store->all(self::COLLECTION) as $t) {
            if ((string) ($t['slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a frozen version record (its structure embedded).
     *
     * @param array<string,mixed> $def
     * @return array<string,mixed>
     */
    private function buildVersion(int $version, string $status, string $notes, string $actor, string $now, array $def): array
    {
        $structure = $this->buildStructure($def);

        return [
            'uuid'         => Uuid::v4(),
            'version'      => $version,
            'status'       => $status,
            'notes'        => $notes,
            'published_at' => $status === 'published' ? $now : null,
            'created_by'   => $actor,
            'created_at'   => $now,
            'sections'     => $structure['sections'],
            'questions'    => $structure['questions'],
            'mappings'     => $structure['mappings'],
        ];
    }

    /**
     * @param array<string,mixed> $def
     * @return array{sections:list<array<string,mixed>>,questions:list<array<string,mixed>>,mappings:list<array<string,mixed>>}
     */
    private function buildStructure(array $def): array
    {
        $sections = [];
        $order = 0;
        foreach (is_array($def['sections'] ?? null) ? $def['sections'] : [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sections[] = [
                'uuid' => Uuid::v4(), 'skey' => (string) ($s['key'] ?? ('s' . $order)), 'title' => (string) ($s['title'] ?? ''),
                'description' => (string) ($s['description'] ?? ''), 'condition' => $this->encodeCond($s['condition'] ?? ''), 'sort_order' => $order++,
            ];
        }
        $questions = [];
        $order = 0;
        foreach (is_array($def['questions'] ?? null) ? $def['questions'] : [] as $q) {
            if (!is_array($q)) {
                continue;
            }
            $type = in_array((string) ($q['type'] ?? 'short_text'), self::TYPES, true) ? (string) $q['type'] : 'short_text';
            $options = [];
            $oOrder = 0;
            foreach (is_array($q['options'] ?? null) ? $q['options'] : [] as $opt) {
                $value = is_array($opt) ? (string) ($opt['value'] ?? '') : (string) $opt;
                $label = is_array($opt) ? (string) ($opt['label'] ?? $value) : (string) $opt;
                $options[] = ['value' => $value, 'label' => $label, 'sort_order' => $oOrder++];
            }
            $questions[] = [
                'uuid' => Uuid::v4(), 'section_key' => (string) ($q['section'] ?? ''), 'qkey' => (string) ($q['key'] ?? ('q' . $order)), 'type' => $type,
                'label' => (string) ($q['label'] ?? ''), 'help' => (string) ($q['help'] ?? ''), 'placeholder' => (string) ($q['placeholder'] ?? ''),
                'required' => !empty($q['required']) ? 1 : 0, 'internal_only' => !empty($q['internal_only']) ? 1 : 0,
                'client_visible' => array_key_exists('client_visible', $q) ? (!empty($q['client_visible']) ? 1 : 0) : 1,
                'config' => is_array($q['config'] ?? null) ? (json_encode($q['config']) ?: '') : '', 'condition' => $this->encodeCond($q['condition'] ?? ''),
                'sort_order' => $order++, 'options' => $options,
            ];
        }
        $mappings = [];
        foreach (is_array($def['mappings'] ?? null) ? $def['mappings'] : [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $mappings[] = [
                'uuid' => Uuid::v4(), 'question_key' => (string) ($m['question'] ?? ''), 'target' => (string) ($m['target'] ?? ''),
                'mode' => (string) ($m['mode'] ?? 'direct'), 'config' => is_array($m['config'] ?? null) ? (json_encode($m['config']) ?: '') : '',
            ];
        }

        return ['sections' => $sections, 'questions' => $questions, 'mappings' => $mappings];
    }

    private function encodeCond(mixed $cond): string
    {
        if (is_array($cond)) {
            return json_encode($cond) ?: '';
        }

        return (string) $cond;
    }

    /**
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private function structureToDef(array $s): array
    {
        return [
            'sections' => array_map(static fn (array $x): array => ['key' => (string) $x['skey'], 'title' => (string) $x['title'], 'description' => (string) $x['description'], 'condition' => (string) $x['condition']], $s['sections'] ?? []),
            'questions' => array_map(static fn (array $x): array => [
                'key' => (string) $x['qkey'], 'section' => (string) $x['section_key'], 'type' => (string) $x['type'], 'label' => (string) $x['label'],
                'help' => (string) $x['help'], 'placeholder' => (string) $x['placeholder'], 'required' => (int) $x['required'] === 1, 'internal_only' => (int) $x['internal_only'] === 1,
                'client_visible' => (int) $x['client_visible'] === 1, 'config' => $x['config'] !== '' ? json_decode((string) $x['config'], true) : null, 'condition' => (string) $x['condition'],
                'options' => array_map(static fn (array $o): array => ['value' => (string) $o['value'], 'label' => (string) $o['label']], $x['options'] ?? []),
            ], $s['questions'] ?? []),
            'mappings' => array_map(static fn (array $x): array => ['question' => (string) $x['question_key'], 'target' => (string) $x['target'], 'mode' => (string) $x['mode']], $s['mappings'] ?? []),
        ];
    }
}
