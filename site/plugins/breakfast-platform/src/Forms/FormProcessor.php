<?php

declare(strict_types=1);

namespace Breakfast\Platform\Forms;

use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Platform;
use Throwable;

/**
 * Processes a validated form submission end to end.
 *
 * Ordering matters and is deliberate: a valid enquiry is persisted to the CRM
 * (with an immutable activity record) BEFORE any external side effect. Emails
 * and the Hermes webhook are only ever *enqueued* — never sent inline — so a
 * temporarily unavailable SMTP server or Hermes endpoint can never lose a lead.
 */
final class FormProcessor
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * @param array<string,mixed> $input   raw field values
     * @param array<string,mixed> $context request/environment context (see class doc)
     */
    public function process(FormDefinition $def, array $input, array $context): FormResult
    {
        $messages = (array) ($context['messages'] ?? []);
        $old      = $this->repopulate($def, $input);

        // 1. CSRF — controller validates the token; we enforce the verdict here too.
        if (($context['csrf_valid'] ?? false) !== true) {
            $this->log('warning', 'csrf_failed', $def->type, $context);

            return FormResult::error(
                $messages['general.csrf'] ?? ($messages['general'] ?? 'Your session expired. Please try again.'),
                $old
            );
        }

        $guard = new SubmissionGuard($this->platform->fileStore(), $this->platform->rateLimiter());

        // 2. Bot signals: honeypot + timing. Respond with a benign success so bots
        //    do not learn they were caught; the lead is simply never created.
        if ($guard->honeypotTripped((string) ($context['honeypot'] ?? ''))) {
            $this->log('info', 'honeypot_tripped', $def->type, $context);

            return FormResult::ok('honeypot', 'HONEYPOT');
        }

        $renderedAt = isset($context['rendered_at']) ? (int) $context['rendered_at'] : null;
        if ($guard->timingSuspicious($renderedAt)) {
            $this->log('info', 'timing_suspicious', $def->type, $context + ['rendered_at' => $renderedAt]);

            return FormResult::ok('timing', 'TIMING');
        }

        // 3. Server-side validation.
        $validator = new Validator();
        if ($validator->validate($input, $def->rules, $messages) === false) {
            return FormResult::invalid($validator->errors(), $old);
        }

        $email = Sanitizer::email((string) ($input['email'] ?? ''));
        $ip    = $context['ip'] ?? null;

        // 4. Rate limiting.
        if (($reason = $guard->rateLimited($def->type, $ip, $email)) !== null) {
            $this->log('warning', $reason, $def->type, $context);

            return FormResult::error(
                $messages['general.rate_limit'] ?? ($messages['general'] ?? 'Too many attempts. Please try again shortly.'),
                $old,
                true
            );
        }

        // 5. Duplicate detection (identical content within a short window).
        $payload = Sanitizer::payload(array_intersect_key($input, array_flip($def->fields())));
        unset($payload['consent']);

        if ($guard->isDuplicate($def->type, $payload)) {
            $this->log('info', 'duplicate', $def->type, $context);

            // Treat as success — the first submission already went through.
            return FormResult::ok('duplicate', 'DUPLICATE');
        }

        // 6. Persist. All of this is local and durable.
        try {
            $enquiry = $this->persist($def, $input, $payload, $context, $email, $ip);
        } catch (Throwable $e) {
            $this->platform->logger()->error('forms', 'Persistence failed', [
                'form' => $def->type,
                'error' => $e->getMessage(),
            ]);

            return FormResult::error(
                $messages['general.error'] ?? ($messages['general'] ?? 'Something went wrong. Please try again.'),
                $old
            );
        }

        // 7. Enqueue side effects (emails + webhook). Enqueue = local DB insert.
        $this->enqueueSideEffects($def, $enquiry, $payload);

        return FormResult::ok((string) $enquiry['uuid'], (string) $enquiry['reference']);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $payload
     * @param array<string,mixed> $context
     * @return array<string,mixed> the created enquiry
     */
    private function persist(
        FormDefinition $def,
        array $input,
        array $payload,
        array $context,
        string $email,
        ?string $ip
    ): array {
        $crm = $this->platform->crm();

        // CRM records (companies, contacts, enquiries, activities) are flat-file,
        // each write atomic on its own — no cross-store transaction is needed.
        $persist = function () use ($crm, $def, $input, $payload, $context, $email, $ip): array {
            // Company (optional).
            $companyUuid = null;
            $companyName = trim((string) ($input['company'] ?? ''));
            if ($companyName !== '') {
                $company = $crm->companies()->findOrCreate($companyName, [
                    'website' => Sanitizer::text((string) ($input['website'] ?? ''), 200) ?: null,
                ]);
                $companyUuid = $company['uuid'] ?? null;
            }

            // Contact (upsert by email).
            $consentGranted = !empty($input['consent']);
            $contact = $crm->contacts()->upsertByEmail([
                'display_name'      => Sanitizer::headerSafe((string) ($input['name'] ?? 'Unknown'), 120),
                'first_name'        => $this->firstName((string) ($input['name'] ?? '')),
                'last_name'         => $this->lastName((string) ($input['name'] ?? '')),
                'email'             => $email,
                'phone'             => Sanitizer::text((string) ($input['phone'] ?? ''), 30) ?: null,
                'company_uuid'      => $companyUuid,
                'website'           => Sanitizer::text((string) ($input['website'] ?? ''), 200) ?: null,
                'contact_type'      => 'lead',
                'lead_source'       => match ($def->type) {
                    'start-project'  => 'project-form',
                    'website-review' => 'website-review-form',
                    default          => 'contact-form',
                },
                'marketing_consent' => $consentGranted ? 'granted' : 'unknown',
                'marketing_consent_at' => $consentGranted ? Clock::nowIso() : null,
                'consent_source'    => $consentGranted ? $def->type . '-form' : null,
            ]);

            // Enquiry.
            $enquiry = $crm->enquiries()->create([
                'form_type'    => $def->type,
                'contact_uuid' => $contact['uuid'] ?? null,
                'company_uuid' => $companyUuid,
                'payload'      => $payload,
                'summary'      => $this->buildSummary($def, $input, $contact),
                'source_page'  => Sanitizer::text((string) ($context['source_page'] ?? ''), 300) ?: null,
                'referrer'     => Sanitizer::text((string) ($context['referrer'] ?? ''), 300) ?: null,
                'utm'          => is_array($context['utm'] ?? null) ? $context['utm'] : [],
                'landing_page' => Sanitizer::text((string) ($context['landing_page'] ?? ''), 300) ?: null,
                'ip_hash'      => Hash::ip($ip),
                'consent'      => [
                    'granted' => $consentGranted,
                    'at'      => $consentGranted ? Clock::nowIso() : null,
                ],
            ]);

            // Immutable activity record on both the enquiry and the contact.
            $crm->activities()->record(
                'enquiry',
                (string) $enquiry['uuid'],
                'form.received',
                'Enquiry received via ' . $def->type . ' form',
                'system',
                null,
                ['reference' => $enquiry['reference'], 'form' => $def->type]
            );

            if (!empty($contact['uuid'])) {
                $crm->activities()->record(
                    'contact',
                    (string) $contact['uuid'],
                    'form.received',
                    'Submitted the ' . $def->type . ' form (' . $enquiry['reference'] . ')',
                    'system',
                    null,
                    ['enquiry_uuid' => $enquiry['uuid']]
                );
            }

            return $enquiry;
        };

        return $persist();
    }

    /**
     * @param array<string,mixed> $enquiry
     * @param array<string,string> $payload
     */
    private function enqueueSideEffects(FormDefinition $def, array $enquiry, array $payload): void
    {
        $ref     = (string) $enquiry['reference'];
        $contact = !empty($enquiry['contact_uuid'])
            ? ($this->platform->contacts()->find((string) $enquiry['contact_uuid']) ?? [])
            : [];

        $factory = $this->platform->enquiryMail();
        $mail    = $this->platform->mailService();

        // Both emails are SEPARATE application messages, queued (never sent
        // inline). Enqueuing is a local DB write — Brevo/SMTP availability is
        // irrelevant to whether the enquiry was saved.
        $mail->queue($factory->internal($enquiry, $contact));

        if (($ack = $factory->acknowledgement($enquiry, $contact)) !== null) {
            $mail->queue($ack);
        }

        // Fan out the enquiry.created webhook to registered endpoints (queued).
        $this->platform->webhooks()->dispatch('enquiry.created', [
            'uuid'       => $enquiry['uuid'],
            'reference'  => $ref,
            'form_type'  => $def->type,
            'summary'    => $enquiry['summary'] ?? null,
            'created_at' => $enquiry['created_at'] ?? null,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $contact
     */
    private function buildSummary(FormDefinition $def, array $input, array $contact): string
    {
        $who   = trim((string) ($input['name'] ?? 'Someone'));
        $what  = $def->type === 'start-project' ? 'wants to start a project' : 'sent an enquiry';
        $extra = [];

        foreach ($def->summaryFields as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '') {
                $extra[] = mb_substr($value, 0, 80);
            }
        }

        $summary = $who . ' ' . $what;
        if ($extra !== []) {
            $summary .= ' — ' . implode('; ', array_slice($extra, 0, 2));
        }

        return Sanitizer::text($summary, 300);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    private function repopulate(FormDefinition $def, array $input): array
    {
        $old = [];
        foreach ($def->fields() as $field) {
            if ($field === 'consent') {
                continue;
            }
            $old[$field] = Sanitizer::text((string) ($input[$field] ?? ''), 5000);
        }

        return $old;
    }

    private function firstName(string $name): ?string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return $parts[0] ?? null;
    }

    private function lastName(string $name): ?string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;
    }

    /** @param array<string,mixed> $context */
    private function log(string $level, string $reason, string $formType, array $context): void
    {
        $this->platform->logger()->{$level}('forms', 'Submission ' . $reason, [
            'form'   => $formType,
            'reason' => $reason,
            'ip'     => Hash::ip($context['ip'] ?? null),
        ]);
    }
}
