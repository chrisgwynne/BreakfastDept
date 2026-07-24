<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Files\FileException;
use Breakfast\Platform\Files\FileValidator;
use PHPUnit\Framework\TestCase;

/**
 * Security regression (audit): the upload validator must not blanket-accept
 * every `application/*` content type for document/design extensions. An
 * executable (ELF / Windows PE) renamed .pdf/.doc previously slipped through
 * and would then be stored and served to clients as a download. The validator
 * now checks the detected MIME against an explicit per-extension allow-list and
 * hard-denies known executable/script content types.
 */
final class FileValidatorMimeTest extends TestCase
{
    private FileValidator $validator;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FileValidator();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fv-mime-');
        file_put_contents($path, $bytes);
        $this->tmpFiles[] = $path;

        return $path;
    }

    public function testElfExecutableRenamedPdfIsRejected(): void
    {
        // A 64-bit ELF header — finfo detects application/x-executable.
        $elf = "\x7fELF\x02\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            . "\x02\x00\x3e\x00\x01\x00\x00\x00" . str_repeat("\x00", 40);
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('does not match its extension');
        $this->validator->validate($this->file($elf), 'invoice.pdf', 'application/pdf');
    }

    public function testWindowsExecutableRenamedDocIsRejected(): void
    {
        $pe = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00"
            . str_repeat("\x00", 48) . 'This program cannot be run in DOS mode.';
        $this->expectException(FileException::class);
        $this->validator->validate($this->file($pe), 'brief.doc', 'application/msword');
    }

    public function testGenuinePdfIsAccepted(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< >>\n%%EOF\n";
        $result = $this->validator->validate($this->file($pdf), 'quote.pdf', 'application/pdf');
        $this->assertSame('pdf', $result['extension']);
        $this->assertSame('application/pdf', $result['detected_mime']);
    }

    public function testGenuineLegacyWordDocIsAccepted(): void
    {
        // OLE2 compound-document signature — finfo detects application/x-ole-storage,
        // which is the real detected type for legacy .doc/.xls/.ppt.
        $ole = "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" . str_repeat("\x00", 512);
        $result = $this->validator->validate($this->file($ole), 'contract.doc', 'application/msword');
        $this->assertSame('doc', $result['extension']);
    }
}
