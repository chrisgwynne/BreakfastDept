<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Crm\CompanyRepository;
use Breakfast\Platform\Crm\ContactRepository;

/**
 * Builds a CRM email DRAFT inviting a client to view a preview. It is ONLY a
 * draft: it is returned for human review and sent through the normal permissioned
 * CRM email path (queue + provider) — never sent automatically from here. The
 * password is NEVER placed in the subject, URL or params; if the preview is
 * password-protected the draft simply notes that the password is shared
 * separately. The logical template is `client_preview_invitation`.
 */
final class PreviewEmailDraft
{
    public function __construct(
        private readonly PreviewUrlGenerator $urls,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
    ) {
    }

    /**
     * @param array<string,mixed> $preview
     * @return array{ok:bool,error?:string,to?:string,subject?:string,body?:string,template?:string,params?:array<string,string>,links?:array<string,?string>}
     */
    public function build(array $preview, ?string $studioName, ?string $studioEmail): array
    {
        $contactUuid = $preview['contact_uuid'] ?? null;
        if (!is_string($contactUuid) || $contactUuid === '') {
            return ['ok' => false, 'error' => 'no_contact_linked'];
        }
        $contact = $this->contacts->find($contactUuid);
        if ($contact === null || ($contact['email'] ?? '') === '') {
            return ['ok' => false, 'error' => 'contact_has_no_email'];
        }

        $companyName = '';
        if (is_string($preview['company_uuid'] ?? null) && $preview['company_uuid'] !== '') {
            $company     = $this->companies->find((string) $preview['company_uuid']);
            $companyName = $company !== null ? (string) ($company['name'] ?? '') : '';
        }

        $contactName = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
        if ($contactName === '') {
            $contactName = (string) ($contact['display_name'] ?? 'there');
        }

        $url        = $this->urls->url((string) $preview['public_slug']);
        $expiry     = is_string($preview['expires_at'] ?? null) && $preview['expires_at'] !== '' ? substr((string) $preview['expires_at'], 0, 10) : '';
        $hasPassword = (int) ($preview['password_protected'] ?? 0) === 1;

        $params = [
            'contact_name'            => $contactName,
            'company_name'            => $companyName,
            'preview_name'            => (string) ($preview['client_display_name'] ?: $preview['name']),
            'preview_url'             => $url,
            'preview_expiry'          => $expiry,
            'breakfast_contact_name'  => (string) ($studioName ?? 'Breakfast'),
            'breakfast_contact_email' => (string) ($studioEmail ?? ''),
            'has_separate_password'   => $hasPassword ? 'yes' : 'no',
        ];

        $subject = 'Your preview from Breakfast is ready';

        $lines = [
            'Hi ' . $contactName . ',',
            '',
            'Your preview' . ($params['preview_name'] !== '' ? ' — ' . $params['preview_name'] . ' —' : '') . ' is ready to look at:',
            $url,
        ];
        if ($expiry !== '') {
            $lines[] = '';
            $lines[] = 'This link is available until ' . $expiry . '.';
        }
        if ($hasPassword) {
            $lines[] = '';
            $lines[] = 'It is password-protected — we will send the password to you separately.';
        }
        $lines[] = '';
        $lines[] = 'Any thoughts, just reply to this email.';
        $lines[] = '';
        $lines[] = 'Thanks,';
        $lines[] = (string) ($studioName ?? 'Breakfast');

        return [
            'ok'       => true,
            'to'       => (string) $contact['email'],
            'subject'  => $subject,
            'body'     => implode("\n", $lines),
            'template' => 'client_preview_invitation',
            'params'   => $params,
            'links'    => [
                'contact_uuid'     => $contactUuid,
                'opportunity_uuid' => is_string($preview['opportunity_uuid'] ?? null) ? $preview['opportunity_uuid'] : null,
            ],
        ];
    }
}
