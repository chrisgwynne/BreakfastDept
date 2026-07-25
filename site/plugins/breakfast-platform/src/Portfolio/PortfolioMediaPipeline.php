<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

use Breakfast\Platform\Files\FileValidator;

/**
 * Portfolio image pipeline. Validates an uploaded image, keeps the original
 * securely outside the webroot, and generates optimised, EXIF-stripped public
 * variants (WebP, plus a same-format fallback, and AVIF where the runtime
 * supports it). Re-encoding through GD inherently drops EXIF/GPS/device/author
 * metadata, so nothing sensitive rides along into the public files.
 *
 * Never upscales: a variant wider than the source is skipped, and the caller is
 * warned when the source is too small for a chosen layout.
 */
final class PortfolioMediaPipeline
{
    /** Public variant widths (px). Focal crops (social) are a fixed box. */
    private const WIDTHS = ['thumb' => 400, 'card' => 800, 'hero' => 1600];
    private const SOCIAL = [1200, 630];
    /** Below this width an image is too small for a full-width/hero layout. */
    public const MIN_HERO_WIDTH = 1200;

    public function __construct(
        private readonly string $storageDir,   // secure originals live here (outside webroot)
        private readonly string $publicDir,    // generated variants live here (web-served)
        private readonly FileValidator $validator = new FileValidator()
    ) {
    }

    /**
     * Process one uploaded image for a media item.
     *
     * @return array{variants:array<string,string>, width:int, height:int, bytes:int, warnings:list<string>}
     */
    public function process(string $tmpPath, string $originalName, string $mediaUuid, string $declaredMime = ''): array
    {
        try {
            $meta = $this->validator->validate($tmpPath, $originalName, $declaredMime);
        } catch (\Breakfast\Platform\Files\FileException $e) {
            throw new PortfolioException(422, $e->getMessage(), 'invalid_image');
        }
        if (!in_array($meta['extension'], ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new PortfolioException(422, 'Portfolio images must be JPEG, PNG or WebP.', 'unsupported_image');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new PortfolioException(500, 'Image processing is unavailable on this server.', 'no_gd');
        }
        $raw = @file_get_contents($tmpPath);
        $src = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($src === false) {
            throw new PortfolioException(422, 'That image could not be read.', 'unreadable');
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Secure original (outside the webroot), re-encoded to strip metadata.
        $origDir = rtrim($this->storageDir, '/') . '/portfolio/originals';
        @mkdir($origDir, 0700, true);
        $this->encode($src, $meta['extension'], $origDir . '/' . $mediaUuid . '.' . $meta['extension'], $srcW, $srcH);
        @chmod($origDir . '/' . $mediaUuid . '.' . $meta['extension'], 0600);

        // Public variants.
        $outDir = rtrim($this->publicDir, '/') . '/portfolio/' . $mediaUuid;
        @mkdir($outDir, 0755, true);
        $variants = [];
        $warnings = [];

        foreach (self::WIDTHS as $name => $w) {
            if ($w > $srcW) {
                // No upscaling — reuse the largest available for this and wider.
                $variants[$name] = $variants['card'] ?? $variants['thumb'] ?? null;
                continue;
            }
            $h = (int) round($srcH * ($w / $srcW));
            $resized = $this->resample($src, $srcW, $srcH, 0, 0, $srcW, $srcH, $w, $h);
            $variants[$name] = $this->writeVariants($resized, $outDir, $name, $mediaUuid);
            imagedestroy($resized);
        }
        // Focal-cropped social image (fixed box), centred (focal applied at crop time by caller data if needed).
        [$sw, $sh] = self::SOCIAL;
        if ($srcW >= 600) {
            $social = $this->cropTo($src, $srcW, $srcH, $sw, $sh);
            $variants['social'] = $this->writeVariants($social, $outDir, 'social', $mediaUuid);
            imagedestroy($social);
        }
        // 'original' points at the largest public variant (hero or card).
        $variants['original'] = $variants['hero'] ?? $variants['card'] ?? $variants['thumb'] ?? null;
        $variants = array_filter($variants, static fn ($v) => $v !== null);

        if ($srcW < self::MIN_HERO_WIDTH) {
            $warnings[] = sprintf('This image is %dpx wide — below the %dpx recommended for a full-width or hero layout. It may look soft when enlarged.', $srcW, self::MIN_HERO_WIDTH);
        }

        imagedestroy($src);

        return [
            'variants' => $variants,
            'width'    => $srcW,
            'height'   => $srcH,
            'bytes'    => (int) $meta['byte_size'],
            'warnings' => $warnings,
        ];
    }

    /**
     * Write WebP (+ AVIF where supported, + a same-name jpeg fallback) for a
     * resampled image and return the public path to prefer (WebP).
     *
     * @param \GdImage $im
     */
    private function writeVariants($im, string $outDir, string $name, string $mediaUuid): string
    {
        $webpPath = $outDir . '/' . $name . '.webp';
        imagewebp($im, $webpPath, 82);
        @chmod($webpPath, 0644);
        // JPEG fallback for very old clients.
        $jpgPath = $outDir . '/' . $name . '.jpg';
        imagejpeg($im, $jpgPath, 82);
        @chmod($jpgPath, 0644);
        // AVIF when the runtime provides it.
        if (function_exists('imageavif')) {
            @imageavif($im, $outDir . '/' . $name . '.avif', 60);
        }

        return '/media/portfolio/' . $mediaUuid . '/' . $name . '.webp';
    }

    /** @param \GdImage $src */
    private function encode($src, string $ext, string $path, int $w, int $h): void
    {
        // Copy onto a fresh truecolor canvas (drops palette/metadata) then encode.
        $canvas = $this->resample($src, $w, $h, 0, 0, $w, $h, $w, $h);
        match ($ext) {
            'png'        => imagepng($canvas, $path, 6),
            'webp'       => imagewebp($canvas, $path, 90),
            default      => imagejpeg($canvas, $path, 90),
        };
        imagedestroy($canvas);
    }

    /**
     * @param \GdImage $src
     * @return \GdImage
     */
    private function resample($src, int $srcW, int $srcH, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh)
    {
        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        return $dst;
    }

    /**
     * Centre-crop to a fixed box (cover).
     *
     * @param \GdImage $src
     * @return \GdImage
     */
    private function cropTo($src, int $srcW, int $srcH, int $boxW, int $boxH)
    {
        $scale = max($boxW / $srcW, $boxH / $srcH);
        $cropW = (int) round($boxW / $scale);
        $cropH = (int) round($boxH / $scale);
        $sx = (int) round(($srcW - $cropW) / 2);
        $sy = (int) round(($srcH - $cropH) / 2);

        return $this->resample($src, $srcW, $srcH, $sx, $sy, $cropW, $cropH, $boxW, $boxH);
    }
}
