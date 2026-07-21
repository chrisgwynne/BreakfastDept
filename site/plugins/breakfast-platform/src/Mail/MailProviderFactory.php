<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use RuntimeException;

/**
 * Selects and constructs the configured mail provider. Fails safely: in
 * production, selecting Brevo without an API key is a hard error; development
 * and test environments default to the fake provider so no real email is ever
 * sent by accident.
 */
final class MailProviderFactory
{
    private static ?MailProvider $override = null;

    /**
     * @param array<string,mixed> $config the platform 'mail' config block
     */
    public static function make(array $config, bool $isProduction): MailProvider
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $provider = (string) ($config['provider'] ?? 'smtp');

        return match ($provider) {
            'brevo' => self::brevo($config, $isProduction),
            'smtp'  => new SmtpMailProvider(is_array($config['smtp'] ?? null) ? $config['smtp'] : []),
            'fake', 'null' => new FakeMailProvider(),
            default => $isProduction
                ? throw new RuntimeException('Unknown mail provider: ' . $provider)
                : new FakeMailProvider(),
        };
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function brevo(array $config, bool $isProduction): MailProvider
    {
        $brevo  = is_array($config['brevo'] ?? null) ? $config['brevo'] : [];
        $apiKey = (string) ($brevo['apiKey'] ?? '');

        if ($apiKey === '') {
            if ($isProduction) {
                throw new RuntimeException('Brevo selected but BREVO_API_KEY is not set');
            }

            // Never send real email in dev/test with an unconfigured provider.
            return new FakeMailProvider();
        }

        return new BrevoApiMailProvider([
            'apiKey'      => $apiKey,
            'baseUrl'     => (string) ($brevo['baseUrl'] ?? 'https://api.brevo.com/v3'),
            'trackOpens'  => (bool) ($brevo['trackOpens'] ?? false),
            'trackClicks' => (bool) ($brevo['trackClicks'] ?? false),
        ]);
    }

    /** @internal test seam: force a specific provider instance */
    public static function override(?MailProvider $provider): void
    {
        self::$override = $provider;
    }
}
