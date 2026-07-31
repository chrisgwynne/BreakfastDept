<?php

declare(strict_types=1);

namespace Breakfast\Platform\Screenshots;

/**
 * A driver that performs NO network access. It synthesises a deterministic
 * placeholder PNG at the requested viewport dimensions and marks the result as a
 * stub. Used in tests and anywhere a live headless browser is not available
 * (e.g. sandboxes with no egress). Selecting it can never leak data or hit a URL.
 */
final class NullCaptureDriver implements CaptureDriver
{
    public function capture(CaptureRequest $request, array $allowedHosts): CaptureResult
    {
        [$w, $h] = $request->dimensions();
        if ($request->fullPage) {
            $h = min(CaptureRequest::MAX_FULLPAGE_HEIGHT, $h * 3);
        }
        $png = self::solidPng($w, $h, $request->dark);

        return new CaptureResult($png, 'image/png', $w, $h, $request->engineKey(), true);
    }

    /** Minimal solid-colour PNG via GD if available, else a 1×1 transparent pixel. */
    private static function solidPng(int $w, int $h, bool $dark): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(max(1, $w), max(1, $h));
            $bg = $dark ? imagecolorallocate($im, 24, 26, 30) : imagecolorallocate($im, 236, 236, 238);
            imagefilledrectangle($im, 0, 0, $w, $h, $bg);
            ob_start();
            imagepng($im);
            $bytes = (string) ob_get_clean();
            imagedestroy($im);

            return $bytes;
        }

        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true
        ) ?: '';
    }
}
