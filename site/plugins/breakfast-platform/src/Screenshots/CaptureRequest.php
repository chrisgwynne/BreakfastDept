<?php

declare(strict_types=1);

namespace Breakfast\Platform\Screenshots;

/**
 * A validated request to capture one screenshot. Curated viewport keys and
 * bounded options only — the driver never receives free-form geometry.
 */
final class CaptureRequest
{
    /** @var array<string,array{0:int,1:int}> width×height per viewport. */
    public const VIEWPORTS = [
        'desktop' => [1440, 900],
        'laptop'  => [1280, 800],
        'tablet'  => [834, 1112],
        'mobile'  => [390, 844],
    ];

    public const ENGINES = ['chromium'];

    /** Hard cap on a full-page capture height (px) — long pages are segmented, not rendered whole. */
    public const MAX_FULLPAGE_HEIGHT = 12000;

    /**
     * @param list<string> $dismissSelectors explicitly-configured cookie/consent dismiss selectors
     */
    public function __construct(
        public readonly string $url,
        public readonly string $pageLabel = 'homepage',
        public readonly string $viewport = 'desktop',
        public readonly bool $fullPage = false,
        public readonly bool $dark = false,
        public readonly int $delayMs = 400,
        public readonly array $dismissSelectors = [],
        public readonly string $engine = 'chromium',
    ) {
    }

    public function viewportKey(): string
    {
        return array_key_exists($this->viewport, self::VIEWPORTS) ? $this->viewport : 'desktop';
    }

    /** @return array{0:int,1:int} */
    public function dimensions(): array
    {
        return self::VIEWPORTS[$this->viewportKey()];
    }

    public function engineKey(): string
    {
        return in_array($this->engine, self::ENGINES, true) ? $this->engine : 'chromium';
    }

    public function delayMsBounded(): int
    {
        return max(0, min(5000, $this->delayMs));
    }

    /** @return array<string,mixed> serialisable config recorded with the capture. */
    public function toConfig(): array
    {
        return [
            'viewport'  => $this->viewportKey(),
            'full_page' => $this->fullPage,
            'dark'      => $this->dark,
            'delay_ms'  => $this->delayMsBounded(),
            'engine'    => $this->engineKey(),
            'dismiss'   => array_values($this->dismissSelectors),
        ];
    }
}
