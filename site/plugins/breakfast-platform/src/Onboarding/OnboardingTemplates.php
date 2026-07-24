<?php

declare(strict_types=1);

namespace Breakfast\Platform\Onboarding;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Versioned onboarding templates + form builder.
 *
 * Publishing a version freezes its sections, questions, options, conditions and
 * mapping rules. Instances bind to an exact frozen version, so later edits (a
 * new draft version) never mutate in-flight answers. Conditions are validated
 * server-side and rejected if they reference removed questions or form a cycle.
 */
final class OnboardingTemplates
{
    /** @var list<string> */
    public const TYPES = ['short_text', 'long_text', 'email', 'phone', 'url', 'number', 'currency', 'date', 'single_choice', 'multi_choice', 'yes_no', 'address', 'file', 'image', 'info', 'heading'];

    public function __construct(private readonly Database $db)
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
            if ($this->db->one('SELECT uuid FROM onboarding_templates WHERE slug = :s', ['s' => $slug]) !== null) {
                continue;
            }
            $this->db->transaction(function (Database $db) use ($slug, $def): void {
                $tUuid = Uuid::v4();
                $now = Clock::nowIso();
                $db->run(
                    'INSERT INTO onboarding_templates (uuid, slug, name, description, category, project_type, current_version, builtin, created_at, updated_at)
                     VALUES (:uuid, :slug, :name, :desc, :cat, :type, 1, 1, :now, :now)',
                    ['uuid' => $tUuid, 'slug' => $slug, 'name' => (string) $def['name'], 'desc' => (string) ($def['description'] ?? ''), 'cat' => (string) $def['category'], 'type' => (string) $def['project_type'], 'now' => $now]
                );
                $vUuid = $this->insertVersion($db, $tUuid, 1, 'published', 'Built-in', 'system', $now);
                $this->writeRows($db, $vUuid, $def);
            });
        }
    }

    // ==================================================================
    // Read
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $this->seedBuiltins();

        return $this->db->all('SELECT * FROM onboarding_templates WHERE archived = 0 ORDER BY name ASC');
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $t = $this->db->one('SELECT * FROM onboarding_templates WHERE uuid = :u', ['u' => $uuid]);
        if ($t === null) {
            return null;
        }
        $t['versions'] = $this->db->all('SELECT * FROM onboarding_template_versions WHERE template_uuid = :u ORDER BY version DESC', ['u' => $uuid]);

        return $t;
    }

    /**
     * The full frozen structure for a specific version.
     *
     * @return array<string,mixed>|null
     */
    public function versionStructure(string $templateUuid, int $version): ?array
    {
        $v = $this->db->one('SELECT * FROM onboarding_template_versions WHERE template_uuid = :t AND version = :v', ['t' => $templateUuid, 'v' => $version]);
        if ($v === null) {
            return null;
        }
        $vUuid = (string) $v['uuid'];
        $v['sections'] = $this->db->all('SELECT * FROM onboarding_sections WHERE version_uuid = :v ORDER BY sort_order ASC', ['v' => $vUuid]);
        $questions = $this->db->all('SELECT * FROM onboarding_questions WHERE version_uuid = :v ORDER BY sort_order ASC', ['v' => $vUuid]);
        foreach ($questions as &$q) {
            $q['options'] = $this->db->all('SELECT value, label FROM onboarding_question_options WHERE question_uuid = :q ORDER BY sort_order ASC', ['q' => (string) $q['uuid']]);
        }
        unset($q);
        $v['questions'] = $questions;
        $v['mappings'] = $this->db->all('SELECT * FROM onboarding_field_mappings WHERE version_uuid = :v', ['v' => $vUuid]);

        return $v;
    }

    /** @return array<string,mixed>|null */
    public function publishedStructure(string $templateUuid): ?array
    {
        $t = $this->db->one('SELECT current_version FROM onboarding_templates WHERE uuid = :u', ['u' => $templateUuid]);
        if ($t === null || (int) $t['current_version'] === 0) {
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
        if ($slug === '' || $this->db->one('SELECT uuid FROM onboarding_templates WHERE slug = :s', ['s' => $slug]) !== null) {
            $slug = ($slug ?: 'template') . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->db->run(
            'INSERT INTO onboarding_templates (uuid, slug, name, description, category, project_type, current_version, builtin, created_at, updated_at)
             VALUES (:uuid, :slug, :name, :desc, :cat, :type, 0, 0, :now, :now)',
            ['uuid' => $uuid, 'slug' => (string) $slug, 'name' => $name, 'desc' => (string) ($data['description'] ?? ''), 'cat' => (string) ($data['category'] ?? ''), 'type' => (string) ($data['project_type'] ?? ''), 'now' => $now]
        );
        $vUuid = $this->insertVersion($this->db, $uuid, 1, 'draft', '', $actor, $now);
        $this->writeRows($this->db, $vUuid, ['sections' => $data['sections'] ?? [], 'questions' => $data['questions'] ?? [], 'mappings' => $data['mappings'] ?? []]);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function newDraftVersion(string $templateUuid, string $actor): array
    {
        $t = $this->db->one('SELECT * FROM onboarding_templates WHERE uuid = :u', ['u' => $templateUuid]);
        if ($t === null) {
            throw new OnboardingException(404, 'Template not found.');
        }
        $latest = (int) $this->db->scalar('SELECT COALESCE(MAX(version),0) FROM onboarding_template_versions WHERE template_uuid = :t', ['t' => $templateUuid]);
        $next = $latest + 1;
        $now = Clock::nowIso();

        return $this->db->transaction(function (Database $db) use ($templateUuid, $latest, $next, $actor, $now): array {
            $vUuid = $this->insertVersion($db, $templateUuid, $next, 'draft', '', $actor, $now);
            if ($latest > 0) {
                $prev = $this->versionStructure($templateUuid, $latest);
                $this->writeRows($db, $vUuid, $this->structureToDef($prev ?? []));
            }

            return $this->find($templateUuid) ?? [];
        });
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
        $this->db->transaction(function (Database $db) use ($templateUuid, $version, $structure): void {
            $db->run("UPDATE onboarding_template_versions SET status = 'published', published_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => (string) $structure['uuid']]);
            $db->run('UPDATE onboarding_templates SET current_version = :v, updated_at = :now WHERE uuid = :u', ['v' => $version, 'now' => Clock::nowIso(), 'u' => $templateUuid]);
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

    private function insertVersion(Database $db, string $templateUuid, int $version, string $status, string $notes, string $actor, string $now): string
    {
        $uuid = Uuid::v4();
        $db->run(
            'INSERT INTO onboarding_template_versions (uuid, template_uuid, version, status, notes, published_at, created_by, created_at)
             VALUES (:uuid, :t, :v, :status, :notes, :pub, :actor, :now)',
            ['uuid' => $uuid, 't' => $templateUuid, 'v' => $version, 'status' => $status, 'notes' => $notes, 'pub' => $status === 'published' ? $now : null, 'actor' => $actor, 'now' => $now]
        );

        return $uuid;
    }

    /** @param array<string,mixed> $def */
    private function writeRows(Database $db, string $versionUuid, array $def): void
    {
        $now = Clock::nowIso();
        $order = 0;
        foreach (is_array($def['sections'] ?? null) ? $def['sections'] : [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $db->run(
                'INSERT INTO onboarding_sections (uuid, version_uuid, skey, title, description, condition, sort_order, created_at)
                 VALUES (:uuid, :v, :skey, :title, :desc, :cond, :order, :now)',
                ['uuid' => Uuid::v4(), 'v' => $versionUuid, 'skey' => (string) ($s['key'] ?? ('s' . $order)), 'title' => (string) ($s['title'] ?? ''), 'desc' => (string) ($s['description'] ?? ''), 'cond' => $this->encodeCond($s['condition'] ?? ''), 'order' => $order++, 'now' => $now]
            );
        }
        $order = 0;
        foreach (is_array($def['questions'] ?? null) ? $def['questions'] : [] as $q) {
            if (!is_array($q)) {
                continue;
            }
            $type = in_array((string) ($q['type'] ?? 'short_text'), self::TYPES, true) ? (string) $q['type'] : 'short_text';
            $qUuid = Uuid::v4();
            $db->run(
                'INSERT INTO onboarding_questions (uuid, version_uuid, section_key, qkey, type, label, help, placeholder, required, internal_only, client_visible, config, condition, sort_order, created_at)
                 VALUES (:uuid, :v, :skey, :qkey, :type, :label, :help, :ph, :req, :int, :cv, :config, :cond, :order, :now)',
                [
                    'uuid' => $qUuid, 'v' => $versionUuid, 'skey' => (string) ($q['section'] ?? ''), 'qkey' => (string) ($q['key'] ?? ('q' . $order)), 'type' => $type,
                    'label' => (string) ($q['label'] ?? ''), 'help' => (string) ($q['help'] ?? ''), 'ph' => (string) ($q['placeholder'] ?? ''),
                    'req' => !empty($q['required']) ? 1 : 0, 'int' => !empty($q['internal_only']) ? 1 : 0, 'cv' => array_key_exists('client_visible', $q) ? (!empty($q['client_visible']) ? 1 : 0) : 1,
                    'config' => is_array($q['config'] ?? null) ? (json_encode($q['config']) ?: '') : '', 'cond' => $this->encodeCond($q['condition'] ?? ''), 'order' => $order++, 'now' => $now,
                ]
            );
            $oOrder = 0;
            foreach (is_array($q['options'] ?? null) ? $q['options'] : [] as $opt) {
                $value = is_array($opt) ? (string) ($opt['value'] ?? '') : (string) $opt;
                $label = is_array($opt) ? (string) ($opt['label'] ?? $value) : (string) $opt;
                $db->run('INSERT INTO onboarding_question_options (uuid, question_uuid, value, label, sort_order) VALUES (:uuid, :q, :value, :label, :order)', ['uuid' => Uuid::v4(), 'q' => $qUuid, 'value' => $value, 'label' => $label, 'order' => $oOrder++]);
            }
        }
        foreach (is_array($def['mappings'] ?? null) ? $def['mappings'] : [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $db->run(
                'INSERT INTO onboarding_field_mappings (uuid, version_uuid, question_key, target, mode, config, created_at)
                 VALUES (:uuid, :v, :qk, :target, :mode, :config, :now)',
                ['uuid' => Uuid::v4(), 'v' => $versionUuid, 'qk' => (string) ($m['question'] ?? ''), 'target' => (string) ($m['target'] ?? ''), 'mode' => (string) ($m['mode'] ?? 'direct'), 'config' => is_array($m['config'] ?? null) ? (json_encode($m['config']) ?: '') : '', 'now' => $now]
            );
        }
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
