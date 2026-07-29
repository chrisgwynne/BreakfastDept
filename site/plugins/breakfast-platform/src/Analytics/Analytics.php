<?php

declare(strict_types=1);

namespace Breakfast\Platform\Analytics;

/**
 * Privacy-first analytics abstraction.
 *
 * Analytics is NEVER hard-wired. The provider is configured (none | plausible |
 * ga4). A cookie/consent banner is only warranted when the configured provider
 * actually sets non-essential cookies (i.e. GA4). Plausible is cookieless, so
 * no banner is shown for it. Personally identifiable form data is never sent.
 */
final class Analytics
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    public function provider(): string
    {
        $provider = (string) ($this->config['provider'] ?? 'none');

        return in_array($provider, ['none', 'plausible', 'ga4'], true) ? $provider : 'none';
    }

    public function enabled(): bool
    {
        return $this->provider() !== 'none';
    }

    public function site(): string
    {
        return (string) ($this->config['site'] ?? '');
    }

    /** Whether the configured provider sets non-essential cookies. */
    public function requiresConsent(): bool
    {
        return $this->provider() === 'ga4';
    }

    /**
     * Additional connect-src hosts the CSP must allow for the provider.
     *
     * @return list<string>
     */
    public function cspConnect(): array
    {
        return match ($this->provider()) {
            'plausible' => ['https://plausible.io'],
            'ga4'       => ['https://www.google-analytics.com', 'https://region1.google-analytics.com'],
            default     => [],
        };
    }

    /** @return list<string> */
    public function cspScript(): array
    {
        return match ($this->provider()) {
            'plausible' => ['https://plausible.io'],
            'ga4'       => ['https://www.googletagmanager.com'],
            default     => [],
        };
    }

    /**
     * The list of conversion events the front-end may emit. Kept here so the
     * event vocabulary is centralised and documented.
     *
     * @return list<string>
     */
    public function events(): array
    {
        return [
            'cta_click',
            'contact_form_started',
            'contact_form_completed',
            'project_viewed',
            'service_viewed',
            'external_project_link',
            'email_link',
        ];
    }

    /**
     * Render the provider's snippet. Consent-gated scripts must be injected only
     * after consent — the template checks requiresConsent() + the consent
     * cookie before calling this. `$nonce` satisfies the CSP.
     */
    public function script(string $nonce): string
    {
        $site   = htmlspecialchars($this->site(), ENT_QUOTES);
        $srcUrl = htmlspecialchars((string) ($this->config['scriptUrl'] ?? ''), ENT_QUOTES);

        return match ($this->provider()) {
            'plausible' => sprintf(
                '<script defer nonce="%s" data-domain="%s" src="%s"></script>',
                $nonce,
                $site,
                $srcUrl !== '' ? $srcUrl : 'https://plausible.io/js/script.js'
            ),
            'ga4' => sprintf(
                '<script defer nonce="%1$s" src="https://www.googletagmanager.com/gtag/js?id=%2$s"></script>'
                . '<script nonce="%1$s">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
                . 'gtag(\'js\',new Date());gtag(\'config\',\'%2$s\',{anonymize_ip:true});</script>',
                $nonce,
                $site
            ),
            default => '',
        };
    }
}
