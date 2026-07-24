<?php

declare(strict_types=1);

namespace Breakfast\Platform\Admin;

use Breakfast\Platform\Support\Platform;

/**
 * Server-authoritative CRM write operations for the standalone admin.
 *
 * Every method validates its input, performs a REAL persisted mutation inside a
 * transaction where more than one row is affected, records a CRM activity and an
 * audit event, and returns the created/updated entity. Nothing here fakes a
 * result: a returned entity means it is in the database. Invalid input raises an
 * ApiException(422) with per-field messages so the UI can show them.
 */
final class CrmWrite
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * Create a lead: a contact (upserted by email when given), an optional
     * company, and the enquiry that ties them together — atomically. Optionally
     * opens a follow-up task. This is what "Add a lead" does.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed> { enquiry, contact, company }
     */
    public function createLead(array $in, string $actor): array
    {
        $name  = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));

        $errors = [];
        if ($name === '' && $email === '') {
            $errors['name'] = 'Enter a name or an email.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($errors !== []) {
            throw new ApiException(422, 'Please fix the highlighted fields.', 'invalid', $errors);
        }

        /** @var array<string,mixed> $result */
        $result = $this->platform->db()->transaction(function () use ($in, $name, $email, $actor): array {
            $company = $this->resolveCompany($in, $actor);

            [$first, $last] = $this->splitName($name);
            $contact = $this->upsertContact([
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => $name !== '' ? $name : null,
                'email'        => $email !== '' ? $email : null,
                'phone'        => $this->str($in, 'phone'),
                'website'      => $this->str($in, 'website'),
                'location'     => $this->str($in, 'location'),
                'lead_source'  => $this->str($in, 'source'),
                'company_uuid' => $company['uuid'] ?? null,
                'owner'        => $this->str($in, 'owner'),
                'notes'        => $this->str($in, 'notes'),
                'contact_type' => 'lead',
                'marketing_consent' => $this->str($in, 'consent') ?: 'unknown',
                'tags'         => $this->tags($in),
            ], $email, $actor);

            $enquiry = $this->platform->enquiries()->create([
                'form_type'    => $this->str($in, 'enquiry_type') ?: 'manual',
                'contact_uuid' => $contact['uuid'],
                'company_uuid' => $company['uuid'] ?? null,
                'summary'      => $this->str($in, 'notes') ?: $this->str($in, 'next_action'),
                'status'       => $this->str($in, 'status') ?: 'new',
                'owner'        => $this->str($in, 'owner'),
                'payload'      => ['manual' => true, 'created_by' => $actor],
            ]);

            $this->platform->activities()->record(
                'enquiry',
                (string) $enquiry['uuid'],
                'enquiry.created',
                'Lead added manually by ' . $actor,
                'user',
                $actor,
                ['reference' => $enquiry['reference'] ?? null]
            );

            // Optional follow-up task.
            $followUp = $this->str($in, 'next_action');
            $dueDate  = $this->str($in, 'follow_up_date');
            if ($followUp !== '' || $dueDate !== '') {
                $this->platform->crm()->createTask([
                    'title'        => $followUp !== '' ? $followUp : 'Follow up on new lead',
                    'due_date'     => $dueDate !== '' ? $dueDate : null,
                    'contact_uuid' => $contact['uuid'],
                    'status'       => 'open',
                ], 'user', $actor);
            }

            $this->platform->audit()->event('lead.created', 'enquiry', (string) $enquiry['uuid'], $actor, [
                'contact' => $contact['uuid'],
                'company' => $company['uuid'] ?? null,
            ]);

            return ['enquiry' => $enquiry, 'contact' => $contact, 'company' => $company];
        });

        return $result;
    }

    /**
     * Create (or de-duplicate by email) a contact, optionally attached to a
     * company, atomically.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed> { contact, company }
     */
    public function createContact(array $in, string $actor): array
    {
        $first   = trim((string) ($in['first_name'] ?? ''));
        $last    = trim((string) ($in['last_name'] ?? ''));
        $display = trim((string) ($in['display_name'] ?? ''));
        $email   = strtolower(trim((string) ($in['email'] ?? '')));

        $errors = [];
        if ($first === '' && $last === '' && $display === '' && $email === '') {
            $errors['first_name'] = 'Enter a name or an email.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($errors !== []) {
            throw new ApiException(422, 'Please fix the highlighted fields.', 'invalid', $errors);
        }

        /** @var array<string,mixed> $result */
        $result = $this->platform->db()->transaction(function () use ($in, $first, $last, $display, $email, $actor): array {
            $company = $this->resolveCompany($in, $actor);

            $contact = $this->upsertContact([
                'first_name'   => $first !== '' ? $first : null,
                'last_name'    => $last !== '' ? $last : null,
                'display_name' => $display !== '' ? $display : null,
                'email'        => $email !== '' ? $email : null,
                'phone'        => $this->str($in, 'phone'),
                'role'         => $this->str($in, 'job_title'),
                'website'      => $this->str($in, 'website'),
                'location'     => $this->str($in, 'location'),
                'lead_source'  => $this->str($in, 'source'),
                'company_uuid' => $company['uuid'] ?? null,
                'owner'        => $this->str($in, 'owner'),
                'notes'        => $this->str($in, 'notes'),
                'contact_type' => $this->str($in, 'contact_type') ?: 'contact',
                'marketing_consent' => $this->str($in, 'marketing_consent') ?: 'unknown',
                'tags'         => $this->tags($in),
            ], $email, $actor);

            $this->platform->audit()->event('contact.created', 'contact', (string) $contact['uuid'], $actor, [
                'company' => $company['uuid'] ?? null,
            ]);

            return ['contact' => $contact, 'company' => $company];
        });

        return $result;
    }

    /**
     * Create (or find by name) a company.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function createCompany(array $in, string $actor): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new ApiException(422, 'A company name is required.', 'invalid', ['name' => 'Required.']);
        }

        $company = $this->platform->companies()->findOrCreate($name, [
            'website'  => $this->str($in, 'website'),
            'industry' => $this->str($in, 'sector'),
            'address'  => $this->str($in, 'location'),
            'notes'    => $this->str($in, 'notes'),
        ]);

        $this->platform->audit()->event('company.created', 'company', (string) $company['uuid'], $actor, ['name' => $name]);

        return $company;
    }

    /**
     * Create a task (optionally linked to a contact or opportunity).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function createTask(array $in, string $actor): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            throw new ApiException(422, 'A task title is required.', 'invalid', ['title' => 'Required.']);
        }

        return $this->platform->crm()->createTask([
            'title'            => $title,
            'due_date'         => $this->str($in, 'due_date') ?: null,
            'priority'         => $this->str($in, 'priority') ?: 'normal',
            'contact_uuid'     => $this->str($in, 'contact_uuid') ?: null,
            'opportunity_uuid' => $this->str($in, 'opportunity_uuid') ?: null,
            'assigned_to'      => $this->str($in, 'assigned_to') ?: null,
            'notes'            => $this->str($in, 'notes') ?: null,
            'status'           => 'open',
        ], 'user', $actor);
    }

    /**
     * Create an opportunity in a valid pipeline stage.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function createOpportunity(array $in, string $actor): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        $stage = trim((string) ($in['stage'] ?? 'new'));
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'A title is required.';
        }
        if (in_array($stage, $this->platform->crm()->stages(), true) === false) {
            $errors['stage'] = 'Choose a valid stage.';
        }
        if ($errors !== []) {
            throw new ApiException(422, 'Please fix the highlighted fields.', 'invalid', $errors);
        }

        return $this->platform->crm()->createOpportunity([
            'title'           => $title,
            'stage'           => $stage,
            'estimated_value' => (int) ($in['value'] ?? 0),
            'probability'     => (int) ($in['probability'] ?? 0),
            'contact_uuid'    => $this->str($in, 'contact_uuid') ?: null,
            'company_uuid'    => $this->str($in, 'company_uuid') ?: null,
        ], 'user', $actor);
    }

    /**
     * Attach a note to any entity's timeline.
     *
     * @return array<string,mixed>
     */
    public function addNote(string $entityType, string $uuid, string $note, string $actor): array
    {
        $note = trim($note);
        if ($note === '') {
            throw new ApiException(422, 'The note cannot be empty.', 'invalid', ['note' => 'Required.']);
        }
        if (in_array($entityType, ['contact', 'enquiry', 'opportunity', 'company'], true) === false) {
            throw new ApiException(422, 'Unknown record type.', 'invalid');
        }

        return $this->platform->crm()->addNote($entityType, $uuid, $note, 'user', $actor);
    }

    // ==================================================================
    // Edit / convert / archive
    // ==================================================================

    /**
     * Edit an existing contact. Only the provided, allowed fields are changed;
     * an activity and audit event are recorded.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function editContact(string $uuid, array $in, string $actor): array
    {
        $contact = $this->platform->contacts()->find($uuid);
        if ($contact === null) {
            throw new ApiException(404, 'Contact not found.', 'not_found');
        }
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (array_key_exists('email', $in) && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Enter a valid email address.', 'invalid', ['email' => 'Invalid email.']);
        }

        $map = [
            'first_name' => 'first_name', 'last_name' => 'last_name', 'display_name' => 'display_name',
            'phone' => 'phone', 'job_title' => 'role', 'website' => 'website', 'location' => 'location',
            'source' => 'lead_source', 'owner' => 'owner', 'notes' => 'notes',
            'marketing_consent' => 'marketing_consent',
        ];
        $data = [];
        foreach ($map as $inKey => $col) {
            if (array_key_exists($inKey, $in)) {
                $data[$col] = $this->str($in, $inKey);
            }
        }
        if (array_key_exists('email', $in)) {
            $data['email'] = $email !== '' ? $email : null;
        }
        if (array_key_exists('tags', $in)) {
            $data['tags'] = $this->tags($in);
        }
        if (array_key_exists('company', $in)) {
            $company = $this->resolveCompany($in, $actor);
            $data['company_uuid'] = $company['uuid'] ?? null;
        }

        $updated = $this->platform->contacts()->update($uuid, $data);
        $this->platform->activities()->record('contact', $uuid, 'contact.updated', 'Contact edited by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('contact.updated', 'contact', $uuid, $actor, ['fields' => array_keys($data)]);

        return ['contact' => $updated ?? []];
    }

    /**
     * Edit an existing lead (enquiry) — status, owner, summary and next action.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function editLead(string $uuid, array $in, string $actor): array
    {
        $enquiry = $this->platform->enquiries()->find($uuid);
        if ($enquiry === null) {
            throw new ApiException(404, 'Lead not found.', 'not_found');
        }
        $data = [];
        foreach (['status', 'owner', 'summary'] as $f) {
            if (array_key_exists($f, $in)) {
                $data[$f] = $this->str($in, $f);
            }
        }
        $updated = $this->platform->enquiries()->update($uuid, $data);
        $this->platform->activities()->record('enquiry', $uuid, 'enquiry.updated', 'Lead edited by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('lead.updated', 'enquiry', $uuid, $actor, ['fields' => array_keys($data)]);

        return ['enquiry' => $updated ?? []];
    }

    /**
     * Convert a lead into an opportunity, atomically: create the opportunity from
     * the enquiry's contact/company, mark the enquiry converted, record activities
     * on both records and an audit event. Never partially applied.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function convertLead(string $uuid, array $in, string $actor): array
    {
        $enquiry = $this->platform->enquiries()->find($uuid);
        if ($enquiry === null) {
            throw new ApiException(404, 'Lead not found.', 'not_found');
        }
        if ((string) ($enquiry['status'] ?? '') === 'converted') {
            throw new ApiException(409, 'This lead has already been converted.', 'conflict');
        }
        $stage = trim((string) ($in['stage'] ?? 'new'));
        if (in_array($stage, $this->platform->crm()->stages(), true) === false) {
            $stage = 'new';
        }
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            $title = 'Opportunity from ' . (string) ($enquiry['reference'] ?? 'lead');
        }

        /** @var array<string,mixed> $result */
        $result = $this->platform->db()->transaction(function () use ($enquiry, $uuid, $title, $stage, $in, $actor): array {
            $opportunity = $this->platform->crm()->createOpportunity([
                'title'           => $title,
                'stage'           => $stage,
                'estimated_value' => (int) ($in['value'] ?? 0),
                'probability'     => (int) ($in['probability'] ?? 0),
                'contact_uuid'    => $enquiry['contact_uuid'] ?? null,
                'company_uuid'    => $enquiry['company_uuid'] ?? null,
            ], 'user', $actor);

            $this->platform->enquiries()->update($uuid, ['status' => 'converted']);

            $this->platform->activities()->record('enquiry', $uuid, 'enquiry.converted', 'Converted to opportunity by ' . $actor, 'user', $actor, ['opportunity' => $opportunity['uuid'] ?? null]);
            $this->platform->activities()->record('opportunity', (string) ($opportunity['uuid'] ?? ''), 'opportunity.created', 'Created from lead ' . (string) ($enquiry['reference'] ?? ''), 'user', $actor, []);
            $this->platform->audit()->event('lead.converted', 'enquiry', $uuid, $actor, ['opportunity' => $opportunity['uuid'] ?? null]);

            return ['opportunity' => $opportunity, 'enquiry_uuid' => $uuid];
        });

        return $result;
    }

    /** @return array<string,mixed> */
    public function archiveContact(string $uuid, string $actor): array
    {
        if ($this->platform->contacts()->find($uuid) === null) {
            throw new ApiException(404, 'Contact not found.', 'not_found');
        }
        $this->platform->contacts()->archive($uuid);
        $this->platform->activities()->record('contact', $uuid, 'contact.archived', 'Contact archived by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('contact.archived', 'contact', $uuid, $actor, []);

        return ['ok' => true, 'contact' => $this->platform->contacts()->find($uuid) ?? []];
    }

    /** @return array<string,mixed> */
    public function archiveLead(string $uuid, string $actor): array
    {
        if ($this->platform->enquiries()->find($uuid) === null) {
            throw new ApiException(404, 'Lead not found.', 'not_found');
        }
        $this->platform->enquiries()->update($uuid, ['status' => 'archived']);
        $this->platform->activities()->record('enquiry', $uuid, 'enquiry.archived', 'Lead archived by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('lead.archived', 'enquiry', $uuid, $actor, []);

        return ['ok' => true, 'enquiry' => $this->platform->enquiries()->find($uuid)];
    }

    // ==================================================================
    // Companies — edit / archive / restore
    // ==================================================================

    /**
     * Edit an existing company's core fields.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function editCompany(string $uuid, array $in, string $actor): array
    {
        if ($this->platform->companies()->find($uuid) === null) {
            throw new ApiException(404, 'Company not found.', 'not_found');
        }
        if (array_key_exists('name', $in) && trim((string) $in['name']) === '') {
            throw new ApiException(422, 'A company name is required.', 'invalid', ['name' => 'Required.']);
        }

        $map  = ['name' => 'name', 'website' => 'website', 'sector' => 'industry', 'size_band' => 'size_band', 'location' => 'address', 'notes' => 'notes'];
        $data = [];
        foreach ($map as $inKey => $col) {
            if (array_key_exists($inKey, $in)) {
                $data[$col] = $this->str($in, $inKey);
            }
        }

        $updated = $this->platform->companies()->update($uuid, $data);
        $this->platform->activities()->record('company', $uuid, 'company.updated', 'Company edited by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('company.updated', 'company', $uuid, $actor, ['fields' => array_keys($data)]);

        return ['company' => $updated ?? []];
    }

    /**
     * Archive a company (soft — kept and restorable). Linked contacts and
     * opportunities are preserved; the relationship counts are surfaced so the
     * UI can warn before archiving a company that still has active records.
     *
     * @return array<string,mixed>
     */
    public function archiveCompany(string $uuid, string $actor): array
    {
        if ($this->platform->companies()->find($uuid) === null) {
            throw new ApiException(404, 'Company not found.', 'not_found');
        }
        $related = $this->platform->companies()->relatedCounts($uuid);
        $company = $this->platform->companies()->archive($uuid);
        $this->platform->activities()->record('company', $uuid, 'company.archived', 'Company archived by ' . $actor, 'user', $actor, $related);
        $this->platform->audit()->event('company.archived', 'company', $uuid, $actor, $related);

        return ['ok' => true, 'company' => $company ?? [], 'related' => $related];
    }

    /** @return array<string,mixed> */
    public function restoreCompany(string $uuid, string $actor): array
    {
        if ($this->platform->companies()->find($uuid) === null) {
            throw new ApiException(404, 'Company not found.', 'not_found');
        }
        $company = $this->platform->companies()->restore($uuid);
        $this->platform->activities()->record('company', $uuid, 'company.restored', 'Company restored by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('company.restored', 'company', $uuid, $actor, []);

        return ['ok' => true, 'company' => $company ?? []];
    }

    // ==================================================================
    // Opportunities — edit / close / archive / restore
    // ==================================================================

    /**
     * Edit an opportunity's core fields (not stage — that goes through move/close).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function editOpportunity(string $uuid, array $in, string $actor): array
    {
        if ($this->platform->opportunities()->find($uuid) === null) {
            throw new ApiException(404, 'Opportunity not found.', 'not_found');
        }
        if (array_key_exists('title', $in) && trim((string) $in['title']) === '') {
            throw new ApiException(422, 'A title is required.', 'invalid', ['title' => 'Required.']);
        }

        $data = [];
        foreach (['title', 'owner', 'next_action', 'notes'] as $f) {
            if (array_key_exists($f, $in)) {
                $data[$f] = $this->str($in, $f);
            }
        }
        foreach (['estimated_value' => 'value', 'probability' => 'probability'] as $col => $inKey) {
            if (array_key_exists($inKey, $in)) {
                $data[$col] = max(0, (int) $in[$inKey]);
            }
        }
        if (array_key_exists('expected_close_date', $in)) {
            $data['expected_close_date'] = $this->str($in, 'expected_close_date') ?: null;
        }
        if (array_key_exists('next_action_date', $in)) {
            $data['next_action_date'] = $this->str($in, 'next_action_date') ?: null;
        }

        $updated = $this->platform->opportunities()->update($uuid, $data);
        $this->platform->activities()->record('opportunity', $uuid, 'opportunity.updated', 'Opportunity edited by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('opportunity.updated', 'opportunity', $uuid, $actor, ['fields' => array_keys($data)]);

        return ['opportunity' => $updated ?? []];
    }

    /**
     * Close an opportunity with a terminal outcome. won/lost move the pipeline
     * stage (stamping won_at/lost_at); abandoned archives it with the outcome
     * recorded. All are reversible via restore.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function closeOpportunity(string $uuid, string $outcome, array $in, string $actor): array
    {
        if ($this->platform->opportunities()->find($uuid) === null) {
            throw new ApiException(404, 'Opportunity not found.', 'not_found');
        }
        if (!in_array($outcome, ['won', 'lost', 'abandoned'], true)) {
            throw new ApiException(422, 'Choose a valid outcome (won, lost or abandoned).', 'invalid', ['outcome' => 'Invalid.']);
        }
        $reason = trim((string) ($in['reason'] ?? ''));

        if ($outcome === 'abandoned') {
            $result = $this->platform->opportunities()->archive($uuid, 'abandoned');
        } else {
            $this->platform->crm()->moveOpportunity($uuid, $outcome, 'user', $actor, $outcome === 'lost' ? ($reason ?: null) : null);
            $result = $this->platform->opportunities()->update($uuid, ['close_outcome' => $outcome]);
        }

        $this->platform->activities()->record('opportunity', $uuid, 'opportunity.closed', 'Opportunity closed (' . $outcome . ')' . ($reason !== '' ? ': ' . $reason : '') . ' by ' . $actor, 'user', $actor, ['outcome' => $outcome]);
        $this->platform->audit()->event('opportunity.closed', 'opportunity', $uuid, $actor, ['outcome' => $outcome, 'reason' => $reason]);

        return ['ok' => true, 'opportunity' => $result ?? []];
    }

    /**
     * Archive an opportunity without a win/loss outcome (e.g. tidy-up).
     *
     * @return array<string,mixed>
     */
    public function archiveOpportunity(string $uuid, string $actor): array
    {
        if ($this->platform->opportunities()->find($uuid) === null) {
            throw new ApiException(404, 'Opportunity not found.', 'not_found');
        }
        $opp = $this->platform->opportunities()->archive($uuid);
        $this->platform->activities()->record('opportunity', $uuid, 'opportunity.archived', 'Opportunity archived by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('opportunity.archived', 'opportunity', $uuid, $actor, []);

        return ['ok' => true, 'opportunity' => $opp ?? []];
    }

    /** @return array<string,mixed> */
    public function restoreOpportunity(string $uuid, string $actor): array
    {
        if ($this->platform->opportunities()->find($uuid) === null) {
            throw new ApiException(404, 'Opportunity not found.', 'not_found');
        }
        $opp = $this->platform->opportunities()->restore($uuid);
        $this->platform->activities()->record('opportunity', $uuid, 'opportunity.restored', 'Opportunity restored by ' . $actor, 'user', $actor, []);
        $this->platform->audit()->event('opportunity.restored', 'opportunity', $uuid, $actor, []);

        return ['ok' => true, 'opportunity' => $opp ?? []];
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Find or create the company named in the input, if any.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>|null
     */
    private function resolveCompany(array $in, string $actor): ?array
    {
        $name = trim((string) ($in['company'] ?? ''));
        if ($name === '') {
            return null;
        }

        return $this->platform->companies()->findOrCreate($name, [
            'website' => $this->str($in, 'website'),
            'address' => $this->str($in, 'location'),
        ]);
    }

    /**
     * Upsert a contact by email when an email is given (de-duplication), else
     * create a fresh record. Records the right activity on the contact timeline.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function upsertContact(array $data, string $email, string $actor): array
    {
        if ($email !== '') {
            $existing = $this->platform->contacts()->findByEmail($email);
            $contact  = $this->platform->contacts()->upsertByEmail($data);
            $this->platform->activities()->record(
                'contact',
                (string) $contact['uuid'],
                $existing !== null ? 'contact.updated' : 'contact.created',
                ($existing !== null ? 'Contact updated by ' : 'Contact created by ') . $actor,
                'user',
                $actor,
                []
            );

            return $contact;
        }

        $contact = $this->platform->contacts()->create($data);
        $this->platform->activities()->record(
            'contact',
            (string) $contact['uuid'],
            'contact.created',
            'Contact created by ' . $actor,
            'user',
            $actor,
            []
        );

        return $contact;
    }

    /** @param array<string,mixed> $in */
    private function str(array $in, string $key): string
    {
        $v = $in[$key] ?? '';

        return is_scalar($v) ? trim((string) $v) : '';
    }

    /**
     * @param array<string,mixed> $in
     * @return list<string>
     */
    private function tags(array $in): array
    {
        $tags = $in['tags'] ?? [];
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }
        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($t): string => is_scalar($t) ? trim((string) $t) : '', $tags)));
    }

    /** @return array{0:?string,1:?string} */
    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], null];
        }
        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }
}
