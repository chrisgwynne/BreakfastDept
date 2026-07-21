<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Mail\BrevoApiMailProvider;
use Breakfast\Platform\Mail\FakeMailProvider;
use Breakfast\Platform\Mail\MailProviderFactory;
use Breakfast\Platform\Mail\SmtpMailProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailProviderFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        MailProviderFactory::override(null);
    }

    public function testSelectsSmtp(): void
    {
        $this->assertInstanceOf(SmtpMailProvider::class, MailProviderFactory::make(['provider' => 'smtp'], false));
    }

    public function testSelectsFake(): void
    {
        $this->assertInstanceOf(FakeMailProvider::class, MailProviderFactory::make(['provider' => 'fake'], false));
    }

    public function testBrevoWithKey(): void
    {
        $p = MailProviderFactory::make(['provider' => 'brevo', 'brevo' => ['apiKey' => 'k']], true);
        $this->assertInstanceOf(BrevoApiMailProvider::class, $p);
    }

    public function testBrevoWithoutKeyFailsInProduction(): void
    {
        $this->expectException(RuntimeException::class);
        MailProviderFactory::make(['provider' => 'brevo', 'brevo' => ['apiKey' => '']], true);
    }

    public function testBrevoWithoutKeyFallsBackToFakeInDev(): void
    {
        $p = MailProviderFactory::make(['provider' => 'brevo', 'brevo' => ['apiKey' => '']], false);
        $this->assertInstanceOf(FakeMailProvider::class, $p, 'dev never sends real email with an unconfigured provider');
    }
}
