<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Forms\UploadHandler;
use Breakfast\Tests\Support\PlatformTestCase;

final class UploadHandlerTest extends PlatformTestCase
{
    private function makeFile(string $name, string $bytes): array
    {
        $tmp = $this->tmpDir . '/incoming-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $bytes);

        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    public function testDisabledByDefault(): void
    {
        $handler = new UploadHandler($this->platform->fileStore(), $this->platform->uploadsDir(), ['enabled' => false]);
        $this->assertFalse($handler->enabled());
        $res = $handler->handle($this->makeFile('a.pdf', '%PDF-1.4 test'));
        $this->assertFalse($res['ok']);
        $this->assertSame('uploads_disabled', $res['error']);
    }

    public function testValidPdfStoredOutsideWebrootWithMetadata(): void
    {
        $handler = new UploadHandler($this->platform->fileStore(), $this->platform->uploadsDir(), ['enabled' => true, 'maxBytes' => 1048576]);
        $res = $handler->handle($this->makeFile('brief.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n"));

        $this->assertTrue($res['ok'], json_encode($res));
        $this->assertSame('application/pdf', $res['upload']['mime']);
        // Stored under the uploads dir (outside public/), with a regenerated name.
        $stored = $this->platform->uploadsDir() . '/' . $res['upload']['stored_name'];
        $this->assertFileExists($stored);
        $this->assertStringNotContainsString('brief.pdf', $res['upload']['stored_name']);

        $row = $this->platform->fileStore()->find('uploads', (string) $res['upload']['uuid']);
        $this->assertSame('brief.pdf', $row['original_name']);
        $this->assertSame('skipped', $row['scan_status']);
    }

    public function testDisallowedTypeRejected(): void
    {
        $handler = new UploadHandler($this->platform->fileStore(), $this->platform->uploadsDir(), ['enabled' => true]);
        // An executable/script disguised as .pdf — MIME sniffing catches it.
        $res = $handler->handle($this->makeFile('evil.pdf', "<?php system('rm -rf /'); ?>"));
        $this->assertFalse($res['ok']);
        $this->assertContains($res['error'], ['disallowed_type', 'extension_mismatch']);
    }

    public function testOversizeRejected(): void
    {
        $handler = new UploadHandler($this->platform->fileStore(), $this->platform->uploadsDir(), ['enabled' => true, 'maxBytes' => 8]);
        $res = $handler->handle($this->makeFile('big.pdf', str_repeat('%PDF-1.4 ', 100)));
        $this->assertFalse($res['ok']);
        $this->assertSame('file_too_large', $res['error']);
    }

    public function testInfectedFileRejectedByScanner(): void
    {
        // Scanner command that always reports infected (exit non-zero).
        $handler = new UploadHandler($this->platform->fileStore(), $this->platform->uploadsDir(), [
            'enabled' => true,
            'scannerCmd' => 'false', // /bin/false → non-zero exit → infected
        ]);
        $res = $handler->handle($this->makeFile('brief.pdf', "%PDF-1.4\ntest\n"));
        $this->assertFalse($res['ok']);
        $this->assertSame('file_rejected', $res['error']);
    }
}
