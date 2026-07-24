<?php

declare(strict_types=1);

namespace Breakfast\Platform\Settings;

use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Mail\BrevoAdmin;
use Breakfast\Platform\Support\Clock;

/**
 * Secure, application-managed Brevo configuration for the standalone admin.
 *
 * The API key is stored encrypted (SettingsStore + SecretBox) and is NEVER
 * returned to a client — only a masked hint ("••••4F9C") and a configured flag.
 * Non-secret settings (sender, reply-to, base URL, tracking) round-trip as
 * plain values. The effective configuration used by the mail provider prefers
 * an application-managed key over the deployment's environment variable, so the
 * user really can manage Brevo from Settings. Every change and every connection
 * test is audited.
 */
final class BrevoSettings
{
    private const SECRET = 'brevo.api_key';

    /** Non-secret settings and their kinds: 'bool' | 'str'. */
    private const FIELDS = [
        'brevo.enabled'          => 'bool',
        'brevo.sender_name'      => 'str',
        'brevo.sender_email'     => 'str',
        'brevo.reply_to_name'    => 'str',
        'brevo.reply_to_email'   => 'str',
        'brevo.base_url'         => 'str',
        'brevo.marketing_list_id' => 'str',
        'brevo.track_opens'      => 'bool',
        'brevo.track_clicks'     => 'bool',
        'brevo.contact_sync'     => 'bool',
    ];

    /**
     * @param array{apiKey?:string,baseUrl?:string} $envMail environment-sourced mail config (fallback)
     */
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly AuditLog $audit,
        private readonly array $envMail = [],
    ) {
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Client-facing overview. Contains NO secret material — only a masked hint.
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $key = $this->effectiveKey();
        $source = $this->settings->has(self::SECRET) ? 'settings' : ($this->envKey() !== '' ? 'env' : 'none');

        return [
            'configured'   => $key !== '',
            'source'       => $source,
            'key_hint'     => $key !== '' ? '••••' . substr($key, -4) : null,
            'config'       => $this->config(),
            'last_success' => $this->settings->get('brevo.last_success'),
            'last_failure' => $this->settings->get('brevo.last_failure'),
            'env_managed'  => $source === 'env',
        ];
    }

    /** @return array<string,string|bool> */
    public function config(): array
    {
        $out = [];
        foreach (self::FIELDS as $name => $kind) {
            $short = substr($name, strlen('brevo.'));
            $val   = $this->settings->get($name);
            $out[$short] = $kind === 'bool' ? ($val === '1') : $val;
        }
        if (($out['base_url'] ?? '') === '') {
            $out['base_url'] = (string) ($this->envMail['baseUrl'] ?? 'https://api.brevo.com/v3');
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(array $data, string $actor): array
    {
        $errors = [];
        foreach (['sender_email' => 'Sender email', 'reply_to_email' => 'Reply-to email'] as $f => $label) {
            $v = trim((string) ($data[$f] ?? ''));
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[$f] = $label . ' must be a valid email address.';
            }
        }
        $base = trim((string) ($data['base_url'] ?? ''));
        if ($base !== '' && !filter_var($base, FILTER_VALIDATE_URL)) {
            $errors['base_url'] = 'API base URL must be a valid URL.';
        }
        if ($errors !== []) {
            throw new SettingsException(422, 'Please fix the highlighted fields.', $errors);
        }

        foreach (self::FIELDS as $name => $kind) {
            $short = substr($name, strlen('brevo.'));
            if (!array_key_exists($short, $data)) {
                continue;
            }
            $raw = $data[$short];
            $val = $kind === 'bool'
                ? (!empty($raw) && strtolower((string) $raw) !== 'false' ? '1' : '0')
                : trim((string) $raw);
            $this->settings->set($name, $val, $actor);
        }
        $this->audit->event('brevo.config_updated', 'settings', 'brevo', $actor, ['fields' => array_keys($data)]);

        return $this->overview();
    }

    /** @return array<string,mixed> */
    public function setKey(string $plaintext, string $actor): array
    {
        $key = trim($plaintext);
        if (strlen($key) < 16) {
            throw new SettingsException(422, 'That doesn’t look like a valid Brevo API key.', ['api_key' => 'Enter the full API key.']);
        }
        $this->settings->setSecret(self::SECRET, $key, $actor);
        // Record only that it changed — never the value.
        $this->audit->event('brevo.key_set', 'settings', 'brevo', $actor, ['hint' => '••••' . substr($key, -4)]);

        return $this->overview();
    }

    /** @return array<string,mixed> */
    public function clearKey(string $actor): array
    {
        $this->settings->clear(self::SECRET, $actor);
        $this->audit->event('brevo.key_removed', 'settings', 'brevo', $actor, []);

        return $this->overview();
    }

    // ------------------------------------------------------------------
    // Connection test
    // ------------------------------------------------------------------

    /**
     * Test authentication, account access and sender availability. Records the
     * outcome (never the key) and returns per-step results with safe details.
     *
     * @return array<string,mixed>
     */
    public function test(string $actor): array
    {
        $key = $this->effectiveKey();
        $steps = [];

        if ($key === '') {
            return ['ok' => false, 'steps' => [
                ['key' => 'key', 'label' => 'API key present', 'ok' => false, 'detail' => 'No API key configured.'],
            ]];
        }
        $steps[] = ['key' => 'key', 'label' => 'API key present', 'ok' => true, 'detail' => 'Configured'];

        $admin   = new BrevoAdmin(['apiKey' => $key, 'baseUrl' => (string) $this->config()['base_url']]);
        $account = $admin->accountCheck();
        $ok = $account['ok'];
        $steps[] = ['key' => 'account', 'label' => 'Account access', 'ok' => $account['ok'], 'detail' => $this->safeDetail($account['detail'])];

        if ($account['ok']) {
            $senders = $admin->sendersCheck();
            $from    = strtolower((string) $this->config()['sender_email']);
            $senderOk = $senders['ok'] && ($from === '' || in_array($from, $senders['senders'], true));
            $detail = !$senders['ok']
                ? $this->safeDetail($senders['detail'])
                : ($from === '' ? 'No sender configured to verify.' : ($senderOk ? 'Sender verified.' : 'Sender ' . $from . ' is not a verified Brevo sender.'));
            $ok = $ok && $senderOk;
            $steps[] = ['key' => 'sender', 'label' => 'Sender availability', 'ok' => $senderOk, 'detail' => $detail];
        }

        $now = Clock::nowIso();
        if ($ok) {
            $this->settings->set('brevo.last_success', $now, $actor);
        } else {
            $this->settings->set('brevo.last_failure', $now, $actor);
        }
        $this->audit->event('brevo.test', 'settings', 'brevo', $actor, ['ok' => $ok]);

        return ['ok' => $ok, 'steps' => $steps];
    }

    // ------------------------------------------------------------------
    // Effective config for the mail provider (server-side only)
    // ------------------------------------------------------------------

    /**
     * The Brevo values to overlay onto the environment mail config. Includes the
     * decrypted key — for internal mail-provider use ONLY, never the API.
     *
     * @return array<string,mixed>
     */
    public function effectiveMailOverlay(): array
    {
        $overlay = [];
        $key = $this->effectiveKey();
        if ($key !== '') {
            $overlay['apiKey'] = $key;
        }
        $c = $this->config();
        if ($c['base_url'] !== '') {
            $overlay['baseUrl'] = $c['base_url'];
        }
        if ($c['sender_email'] !== '') {
            $overlay['fromEmail'] = $c['sender_email'];
        }
        if ($c['sender_name'] !== '') {
            $overlay['fromName'] = $c['sender_name'];
        }
        if ($c['reply_to_email'] !== '') {
            $overlay['replyTo'] = $c['reply_to_email'];
        }

        return $overlay;
    }

    public function effectiveKey(): string
    {
        $stored = $this->settings->revealSecret(self::SECRET);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $this->envKey();
    }

    private function envKey(): string
    {
        return (string) ($this->envMail['apiKey'] ?? '');
    }

    private function safeDetail(string $detail): string
    {
        // Only pass through our own bounded status strings; never raw provider bodies.
        return preg_match('/^(authenticated|HTTP \d{3}|\d+ sender)/', $detail) === 1 ? $detail : 'Request failed.';
    }
}
