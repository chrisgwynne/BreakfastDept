<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Portfolio\PreviewInject;
use PHPUnit\Framework\TestCase;

final class PreviewInjectTest extends TestCase
{
    public function testInjectsReducedMotionStyleBeforeHead(): void
    {
        $html = '<!doctype html><html><head><title>x</title></head><body>hi</body></html>';
        $out = PreviewInject::reducedMotion($html);
        $this->assertStringContainsString('bf-force-rm', $out);
        // Injected before </head>.
        $this->assertLessThan(strpos($out, '</head>'), strpos($out, 'bf-force-rm'));
        $this->assertStringContainsString('animation:none', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testHandlesMissingHead(): void
    {
        $out = PreviewInject::reducedMotion('<body>no head</body>');
        $this->assertStringContainsString('bf-force-rm', $out);
    }
}
