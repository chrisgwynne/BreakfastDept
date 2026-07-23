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
