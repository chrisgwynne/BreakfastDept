<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

/**
 * Built-in, data-driven contract templates. Each template is a set of ordered,
 * structured clause sections (optional ones can be toggled) written in plain,
 * editable prose with {{merge_field}} placeholders — never executable code.
 *
 * Templates are deliberately non-committal boilerplate: they set out a sensible
 * structure for Breakfast to edit, not fixed legal assertions. The operator
 * edits clause text per contract before sending.
 */
final class ContractTemplates
{
    /** Merge fields the system can resolve from a contract's linked records. */
    public const MERGE_FIELDS = [
        'client_name', 'company_name', 'contact_name', 'proposal_number',
        'project_name', 'contract_value', 'deposit_amount', 'start_date',
        'target_date', 'seller_name', 'revision_limit', 'governing_law',
    ];

    /**
     * @return array<string,array{name:string,description:string,default_payment_terms:string,default_revision_limit:int,sections:list<array{key:string,heading:string,body:string,optional:bool,enabled:bool}>}>
     */
    public static function all(): array
    {
        $common = static fn (): array => [
            ['key' => 'parties', 'heading' => 'Parties', 'body' => "This agreement is between {{seller_name}} (\"the Supplier\") and {{client_name}} of {{company_name}} (\"the Client\").", 'optional' => false, 'enabled' => true],
            ['key' => 'scope', 'heading' => 'Scope of work', 'body' => "The Supplier will carry out the work described in proposal {{proposal_number}} and any scope agreed in writing. Work outside that scope is handled as a separate change request.", 'optional' => false, 'enabled' => true],
            ['key' => 'fees', 'heading' => 'Fees & payment', 'body' => "The total fee is {{contract_value}}. A deposit of {{deposit_amount}} is payable before work begins; the balance is invoiced as set out in the proposal. Invoices are due within 14 days.", 'optional' => false, 'enabled' => true],
            ['key' => 'revisions', 'heading' => 'Revisions', 'body' => "The fee includes up to {{revision_limit}} rounds of revisions at each review stage. Further revisions are quoted separately.", 'optional' => true, 'enabled' => true],
            ['key' => 'client_resp', 'heading' => 'Client responsibilities', 'body' => "The Client will provide content, feedback and approvals promptly. Delays in these may affect the timeline.", 'optional' => false, 'enabled' => true],
            ['key' => 'ip', 'heading' => 'Intellectual property', 'body' => "On full payment, ownership of the final delivered work transfers to the Client, except third-party assets and the Supplier's pre-existing tools and libraries, which remain licensed for use in the delivered work.", 'optional' => true, 'enabled' => true],
            ['key' => 'cancellation', 'heading' => 'Cancellation', 'body' => "Either party may end this agreement in writing. The Client pays for work completed up to the cancellation date; the deposit is non-refundable once work has begun.", 'optional' => true, 'enabled' => true],
            ['key' => 'confidentiality', 'heading' => 'Confidentiality', 'body' => "Each party will keep the other's non-public information confidential.", 'optional' => true, 'enabled' => true],
            ['key' => 'law', 'heading' => 'Governing law', 'body' => "This agreement is governed by {{governing_law}}.", 'optional' => false, 'enabled' => true],
        ];

        $maintenance = static fn (): array => [
            ['key' => 'parties', 'heading' => 'Parties', 'body' => "This care-plan agreement is between {{seller_name}} (\"the Supplier\") and {{client_name}} of {{company_name}} (\"the Client\").", 'optional' => false, 'enabled' => true],
            ['key' => 'services', 'heading' => 'Services', 'body' => "The Supplier will provide hosting, updates, backups and reasonable support for the Client's website on an ongoing basis.", 'optional' => false, 'enabled' => true],
            ['key' => 'fees', 'heading' => 'Fees', 'body' => "The care plan is billed at the agreed recurring rate. Fees may be reviewed annually with 30 days' notice.", 'optional' => false, 'enabled' => true],
            ['key' => 'term', 'heading' => 'Term & notice', 'body' => "The plan runs month to month unless otherwise agreed and may be cancelled by either party with 30 days' written notice.", 'optional' => false, 'enabled' => true],
            ['key' => 'exclusions', 'heading' => 'Exclusions', 'body' => "New features, redesigns and third-party costs are outside the plan and quoted separately.", 'optional' => true, 'enabled' => true],
            ['key' => 'law', 'heading' => 'Governing law', 'body' => "This agreement is governed by {{governing_law}}.", 'optional' => false, 'enabled' => true],
        ];

        return [
            'website_build'    => ['name' => 'Website design & build', 'description' => 'Fixed-scope website project.', 'default_payment_terms' => '50% deposit, 50% on launch', 'default_revision_limit' => 2, 'sections' => $common()],
            'website_redesign' => ['name' => 'Website redesign', 'description' => 'Redesign of an existing site.', 'default_payment_terms' => '50% deposit, 50% on launch', 'default_revision_limit' => 2, 'sections' => $common()],
            'ecommerce'        => ['name' => 'Ecommerce website', 'description' => 'Online store build.', 'default_payment_terms' => '40% / 30% / 30%', 'default_revision_limit' => 2, 'sections' => $common()],
            'landing_page'     => ['name' => 'Landing page', 'description' => 'Single conversion-focused page.', 'default_payment_terms' => '50% deposit, 50% on launch', 'default_revision_limit' => 1, 'sections' => $common()],
            'maintenance'      => ['name' => 'Website maintenance', 'description' => 'Ongoing maintenance.', 'default_payment_terms' => 'Monthly in advance', 'default_revision_limit' => 0, 'sections' => $maintenance()],
            'care_plan'        => ['name' => 'Hosting & care plan', 'description' => 'Hosting + support retainer.', 'default_payment_terms' => 'Monthly in advance', 'default_revision_limit' => 0, 'sections' => $maintenance()],
            'seo'              => ['name' => 'SEO services', 'description' => 'Search visibility work.', 'default_payment_terms' => 'Monthly in advance', 'default_revision_limit' => 0, 'sections' => $common()],
            'general'          => ['name' => 'General digital services', 'description' => 'Flexible services agreement.', 'default_payment_terms' => '50% deposit, 50% on completion', 'default_revision_limit' => 2, 'sections' => $common()],
        ];
    }

    /** @return array{name:string,description:string,default_payment_terms:string,default_revision_limit:int,sections:list<array{key:string,heading:string,body:string,optional:bool,enabled:bool}>}|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Resolve {{merge_field}} placeholders in a string against a value map, and
     * return any REQUIRED placeholders that remain unresolved (empty value).
     *
     * @param array<string,string> $values
     * @return array{text:string,missing:list<string>}
     */
    public static function merge(string $text, array $values): array
    {
        $missing = [];
        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($values, &$missing): string {
            $key = $m[1];
            $val = trim((string) ($values[$key] ?? ''));
            if ($val === '') {
                $missing[] = $key;

                return '{{' . $key . '}}';
            }

            return $val;
        }, $text) ?? $text;

        return ['text' => $out, 'missing' => array_values(array_unique($missing))];
    }
}
