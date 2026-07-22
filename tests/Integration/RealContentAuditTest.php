<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\ContentAudit;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real, published site content: it must never regress to placeholder
 * text or false ("British") positioning. Warnings (details not yet supplied) are
 * allowed — this asserts only that there are zero structural / false-claim
 * ERRORS, i.e. that `content:audit --strict` would pass on the live content.
 */
final class RealContentAuditTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testRealSiteContentHasNoAuditErrors(): void
    {
        $base = dirname(__DIR__, 2);

        $app = new App([
            'roots' => [
                'index'   => $base . '/public',
                'base'    => $base,
                'site'    => $base . '/site',
                'content' => $base . '/content',
            ],
            'options' => ['debug' => false, 'whoops' => false],
        ]);

        $findings = (new ContentAudit($app))->run();
        $errors = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['severity'] === ContentAudit::ERROR
        ));

        $this->assertSame(
            [],
            $errors,
            "Published content contains placeholder text or false positioning:\n"
            . implode("\n", array_map(
                static fn (array $f): string => '  - ' . $f['where'] . ': ' . $f['message'],
                $errors
            ))
        );
    }
}
