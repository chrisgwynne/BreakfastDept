<?php

declare(strict_types=1);

namespace Breakfast\Tests\Support;

use Breakfast\Platform\Mail\FakeMailProvider;
use Breakfast\Platform\Mail\MailProviderFactory;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Platform;
use PHPUnit\Framework\TestCase;

/**
 * Base test case that boots the platform against a throwaway on-disk flat-file
 * store (a fresh temp directory per test; collections are created on first write).
 */
abstract class PlatformTestCase extends TestCase
{
    protected Platform $platform;
    protected string $tmpDir;
    protected FakeMailProvider $mail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/bf-test-' . bin2hex(random_bytes(6));
        @mkdir($this->tmpDir . '/logs', 0777, true);
        @mkdir($this->tmpDir . '/uploads', 0777, true);

        Platform::reset();
        Clock::unfreeze();

        // Force the fake provider so no test can ever send a real email.
        $this->mail = new FakeMailProvider();
        MailProviderFactory::override($this->mail);

        $this->platform = Platform::boot($this->tmpDir, [
            'storageDir'    => $this->tmpDir,
            'production'    => false,
            'uploadsDir'    => $this->tmpDir . '/uploads',
            'webhookSecret' => 'test-webhook-secret',
            'mail'          => [
                'provider'    => 'fake',
                'from'        => 'studio@example.com',
                'fromName'    => 'Breakfast',
                'senderEmail' => 'studio@example.com',
                'senderName'  => 'Breakfast',
                'enquiriesTo' => 'studio@example.com',
            ],
            'analytics'     => ['provider' => 'none'],
        ]);

    }

    protected function tearDown(): void
    {
        Platform::reset();
        Clock::unfreeze();
        MailProviderFactory::override(null);
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
