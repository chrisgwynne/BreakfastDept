<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Settings\SettingsStore;

/**
 * Stripe configuration + secret storage.
 *
 * The secret key and webhook signing secret are stored ENCRYPTED via the
 * SettingsStore/SecretBox and are NEVER returned again — only a masked hint is
 * ever exposed. Non-secret config (mode, enabled, currency, account id, success/
 * cancel URLs) is stored in the clear. Test and live credentials are separate
 * (namespaced by mode) so test keys can never process a live invoice.
 */
final class StripeSettings
{
    private const MODES = ['test', 'live'];

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly AuditLog $audit,
    ) {
    }

    public function mode(): string
    {
        $m = $this->settings->get('stripe.mode', 'test');

        return in_array($m, self::MODES, true) ? $m : 'test';
    }

    public function enabled(): bool
    {
        return $this->settings->get('stripe.enabled') === '1'
            && $this->secretKey() !== '';
    }

    public function currency(): string
    {
        return strtoupper($this->settings->get('stripe.currency', 'GBP'));
    }

    /** The active secret key for the current mode (decrypted; internal use only). */
    public function secretKey(): string
    {
        return (string) ($this->settings->revealSecret('stripe.secret_key.' . $this->mode()) ?? '');
    }

    public function webhookSecret(): string
    {
        return (string) ($this->settings->revealSecret('stripe.webhook_secret.' . $this->mode()) ?? '');
    }

    public function publishableKey(): string
    {
        return $this->settings->get('stripe.publishable_key.' . $this->mode());
    }

    public function successUrl(): string
    {
        return $this->settings->get('stripe.success_url');
    }

    public function cancelUrl(): string
    {
        return $this->settings->get('stripe.cancel_url');
    }

    public function baseUrl(): string
    {
        return $this->settings->get('stripe.base_url', 'https://api.stripe.com/v1');
    }

    /**
     * Client-facing overview — NO secret material, only masked hints.
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $mode = $this->mode();

        return [
            'enabled'          => $this->enabled(),
            'mode'             => $mode,
            'currency'         => $this->currency(),
            'account_id'       => $this->settings->get('stripe.account_id'),
            'publishable_key'  => $this->publishableKey(),
            'secret_key_hint'  => $this->settings->secretHint('stripe.secret_key.' . $mode),
            'webhook_secret_hint' => $this->settings->secretHint('stripe.webhook_secret.' . $mode),
            'success_url'      => $this->successUrl(),
            'cancel_url'       => $this->cancelUrl(),
            'has_secret'       => $this->secretKey() !== '',
            'has_webhook_secret' => $this->webhookSecret() !== '',
            'last_webhook'     => $this->settings->get('stripe.last_webhook'),
            'last_payment'     => $this->settings->get('stripe.last_payment'),
            'last_failure'     => $this->settings->get('stripe.last_failure'),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(array $data, string $actor): array
    {
        if (array_key_exists('mode', $data)) {
            $mode = in_array($data['mode'], self::MODES, true) ? (string) $data['mode'] : 'test';
            $this->settings->set('stripe.mode', $mode, $actor);
        }
        if (array_key_exists('enabled', $data)) {
            $this->settings->set('stripe.enabled', !empty($data['enabled']) ? '1' : '0', $actor);
        }
        foreach (['currency', 'account_id', 'success_url', 'cancel_url'] as $f) {
            if (array_key_exists($f, $data)) {
                $this->settings->set('stripe.' . $f, (string) $data[$f], $actor);
            }
        }
        // Publishable key is not secret but mode-scoped.
        if (array_key_exists('publishable_key', $data)) {
            $this->settings->set('stripe.publishable_key.' . $this->mode(), (string) $data['publishable_key'], $actor);
        }
        $this->audit->event('stripe.config_updated', 'settings', 'stripe', $actor, ['fields' => array_keys($data)]);

        return $this->overview();
    }

    /** Store the secret key (encrypted) for a mode. Never returned again. */
    public function setSecretKey(string $plaintext, string $actor, ?string $mode = null): void
    {
        $mode = $mode !== null && in_array($mode, self::MODES, true) ? $mode : $this->mode();
        $key  = trim($plaintext);
        if ($key === '') {
            throw new PaymentException(422, 'Enter a Stripe secret key.');
        }
        // Guard against mixing modes: a live key must not be stored under test.
        if (str_starts_with($key, 'sk_live_') && $mode !== 'live') {
            throw new PaymentException(422, 'That looks like a LIVE key — switch to live mode to store it.');
        }
        if (str_starts_with($key, 'sk_test_') && $mode !== 'test') {
            throw new PaymentException(422, 'That looks like a TEST key — switch to test mode to store it.');
        }
        $this->settings->setSecret('stripe.secret_key.' . $mode, $key, $actor);
        $this->audit->event('stripe.secret_set', 'settings', 'stripe', $actor, ['mode' => $mode]);
    }

    public function setWebhookSecret(string $plaintext, string $actor, ?string $mode = null): void
    {
        $mode = $mode !== null && in_array($mode, self::MODES, true) ? $mode : $this->mode();
        if (trim($plaintext) === '') {
            throw new PaymentException(422, 'Enter a webhook signing secret.');
        }
        $this->settings->setSecret('stripe.webhook_secret.' . $mode, trim($plaintext), $actor);
        $this->audit->event('stripe.webhook_secret_set', 'settings', 'stripe', $actor, ['mode' => $mode]);
    }

    public function clearSecret(string $actor): void
    {
        $this->settings->clear('stripe.secret_key.' . $this->mode(), $actor);
        $this->audit->event('stripe.secret_removed', 'settings', 'stripe', $actor, ['mode' => $this->mode()]);
    }

    public function note(string $name, string $value, string $actor): void
    {
        $this->settings->set('stripe.' . $name, $value, $actor);
    }
}
