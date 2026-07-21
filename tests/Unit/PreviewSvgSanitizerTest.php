<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\ClientPreviews\PreviewSvgSanitizer;
use PHPUnit\Framework\TestCase;

final class PreviewSvgSanitizerTest extends TestCase
{
    public function testStripsScriptsAndEventHandlers(): void
    {
        $r = PreviewSvgSanitizer::sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect onload="x()" width="1" height="1"/></svg>'
        );
        $this->assertTrue($r['ok']);
        $this->assertStringNotContainsStringIgnoringCase('<script', $r['svg']);
        $this->assertStringNotContainsStringIgnoringCase('onload', $r['svg']);
    }

    public function testRejectsExternalEntityXxe(): void
    {
        $tmp = sys_get_temp_dir() . '/xxe-probe-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($tmp, 'TOP-SECRET-XXE');
        try {
            $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xx SYSTEM "file://' . $tmp . '">]>'
                . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xx;</text></svg>';
            $r = PreviewSvgSanitizer::sanitize($svg);

            $this->assertFalse($r['ok'], 'a DOCTYPE/entity SVG must be rejected');
            $this->assertSame('doctype_forbidden', $r['error']);
            // The secret must never appear anywhere in a returned document.
            $this->assertStringNotContainsString('TOP-SECRET-XXE', $r['svg'] ?? '');
        } finally {
            @unlink($tmp);
        }
    }

    public function testRejectsEntityExpansionBomb(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "AAAA"><!ENTITY b "&a;&a;&a;">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>';
        $r = PreviewSvgSanitizer::sanitize($svg);
        $this->assertFalse($r['ok']);
        $this->assertSame('doctype_forbidden', $r['error']);
    }
}
