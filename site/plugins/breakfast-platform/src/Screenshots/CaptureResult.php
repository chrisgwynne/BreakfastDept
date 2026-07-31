<?php

declare(strict_types=1);

namespace Breakfast\Platform\Screenshots;

/** The image bytes and metadata a driver returns for one capture. */
final class CaptureResult
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly int $width,
        public readonly int $height,
        public readonly string $engine,
        /** True when the image is a synthesised stub (no live browser ran). */
        public readonly bool $stub = false,
    ) {
    }
}
