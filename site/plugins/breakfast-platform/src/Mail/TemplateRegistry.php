<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Central registry of logical transactional templates. Each logical key maps to
 * an optional provider (Brevo) template id plus application-rendered fallbacks
 * (subject + HTML + text) so the system works whether or not provider templates
 * are configured. Editors never enter executable logic — only Brevo template ids
 * (via env) and, in future, Panel-managed metadata.
 */
final class TemplateRegistry
{
    /**
     * @var array<string,array{
     *   name:string, brevoTemplateId:?int, enabled:bool,
     *   allowedRoles:list<string>, requiredParams:list<string>,
     *   fallbackSubject:string
     * }>
     */
    private array $templates;

    /**
     * @param array<string,int|string|null> $brevoTemplateIds  key => brevo id (from env)
     */
    public function __construct(array $brevoTemplateIds = [])
    {
        $id = static fn (string $k): ?int => isset($brevoTemplateIds[$k]) && $brevoTemplateIds[$k] !== ''
            ? (int) $brevoTemplateIds[$k]
            : null;

        $this->templates = [
            'enquiry_internal' => [
                'name'            => 'New enquiry — internal notification',
                'brevoTemplateId' => $id('enquiry_internal'),
                'enabled'         => true,
                'allowedRoles'    => ['admin', 'crm-manager'],
                'requiredParams'  => ['enquiry_reference'],
                'fallbackSubject' => 'New {form_type} enquiry — {enquiry_reference}',
            ],
            'enquiry_ack' => [
                'name'            => 'Enquiry acknowledgement',
                'brevoTemplateId' => $id('enquiry_ack'),
                'enabled'         => true,
                'allowedRoles'    => ['admin', 'crm-manager'],
                'requiredParams'  => ['contact_name'],
                'fallbackSubject' => 'We got your message — Breakfast',
            ],
            'crm_reply' => [
                'name'            => 'General CRM reply',
                'brevoTemplateId' => $id('crm_reply'),
                'enabled'         => true,
                'allowedRoles'    => ['admin', 'crm-manager'],
                'requiredParams'  => [],
                'fallbackSubject' => 'A message from Breakfast',
            ],
            'project_followup' => [
                'name'            => 'Project follow-up',
                'brevoTemplateId' => $id('crm_reply'),
                'enabled'         => true,
                'allowedRoles'    => ['admin', 'crm-manager'],
                'requiredParams'  => [],
                'fallbackSubject' => 'Following up on your project',
            ],
            'task_reminder' => [
                'name'            => 'Task reminder',
                'brevoTemplateId' => $id('task_reminder'),
                'enabled'         => true,
                'allowedRoles'    => ['admin', 'crm-manager'],
                'requiredParams'  => ['next_action'],
                'fallbackSubject' => 'Reminder: {next_action}',
            ],
            'daily_digest' => [
                'name'            => 'Daily CRM digest',
                'brevoTemplateId' => $id('daily_digest'),
                'enabled'         => false,
                'allowedRoles'    => ['admin'],
                'requiredParams'  => [],
                'fallbackSubject' => 'Breakfast — your studio briefing',
            ],
            'failed_job' => [
                'name'            => 'Failed queue alert',
                'brevoTemplateId' => $id('failed_job'),
                'enabled'         => true,
                'allowedRoles'    => ['admin'],
                'requiredParams'  => [],
                'fallbackSubject' => 'Breakfast — a background job failed',
            ],
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    /**
     * @return array{name:string, brevoTemplateId:?int, enabled:bool, allowedRoles:list<string>, requiredParams:list<string>, fallbackSubject:string}|null
     */
    public function get(string $key): ?array
    {
        return $this->templates[$key] ?? null;
    }

    public function brevoTemplateId(string $key): ?int
    {
        return $this->templates[$key]['brevoTemplateId'] ?? null;
    }

    /**
     * Validate that all required params are present (non-empty) for a template.
     *
     * @param array<string,scalar> $params
     * @return list<string> the names of missing required params
     */
    public function missingParams(string $key, array $params): array
    {
        $required = $this->templates[$key]['requiredParams'] ?? [];
        $missing  = [];
        foreach ($required as $name) {
            if (!isset($params[$name]) || (string) $params[$name] === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /** @return array<string,array<string,mixed>> for the Panel status view */
    public function all(): array
    {
        $out = [];
        foreach ($this->templates as $key => $t) {
            $out[$key] = [
                'name'      => $t['name'],
                'enabled'   => $t['enabled'],
                'brevo_set' => $t['brevoTemplateId'] !== null,
            ];
        }

        return $out;
    }
}
