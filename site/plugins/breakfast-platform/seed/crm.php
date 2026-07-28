<?php

declare(strict_types=1);

/**
 * Development-only CRM seeder. Inserts a handful of realistic-but-clearly
 * illustrative CRM records so the Panel area has something to show. Invoked by
 * `php bin/console seed:crm` (refused in production).
 *
 * @var \Breakfast\Platform\Support\Platform $platform  (in scope from bin/console)
 */

use Breakfast\Platform\Support\Clock;

$crm = $platform->crm();

$company = $crm->companies()->findOrCreate('Rivers Joinery', ['industry' => 'Joinery', 'website' => 'riversjoinery.co.uk']);

$contact = $crm->contacts()->upsertByEmail([
    'display_name' => 'Jamie Rivers',
    'first_name'   => 'Jamie',
    'last_name'    => 'Rivers',
    'email'        => 'jamie@riversjoinery.co.uk',
    'company_uuid' => $company['uuid'],
    'contact_type' => 'lead',
    'lead_source'  => 'contact-form',
]);

$enquiry = $crm->enquiries()->create([
    'form_type'    => 'contact',
    'contact_uuid' => $contact['uuid'],
    'company_uuid' => $company['uuid'],
    'payload'      => ['name' => 'Jamie Rivers', 'message' => 'Our website is embarrassing and nobody can find the contact page.'],
    'summary'      => 'Jamie Rivers wants a clearer website for the joinery',
    'source_page'  => '/contact',
    'status'       => 'new',
]);

$crm->activities()->record('enquiry', (string) $enquiry['uuid'], 'form.received', 'Enquiry received via contact form (seed)', 'system');

$opp = $crm->createOpportunity([
    'title'           => 'Rivers Joinery — new website',
    'contact_uuid'    => $contact['uuid'],
    'company_uuid'    => $company['uuid'],
    'enquiry_uuid'    => $enquiry['uuid'],
    'stage'           => 'qualified',
    'estimated_value' => 450000, // £4,500 in pence
    'probability'     => 40,
    'next_action'     => 'Send a short proposal',
    'next_action_date' => Clock::now()->modify('+3 days')->format('c'),
]);

$crm->createTask([
    'title'            => 'Send Rivers Joinery a proposal',
    'contact_uuid'     => $contact['uuid'],
    'opportunity_uuid' => $opp['uuid'],
    'due_date'         => Clock::now()->modify('+2 days')->format('c'),
    'priority'         => 'high',
]);

echo "Seeded: 1 company, 1 contact, 1 enquiry, 1 opportunity, 1 task.\n";
echo "Reference: " . $enquiry['reference'] . "\n";
