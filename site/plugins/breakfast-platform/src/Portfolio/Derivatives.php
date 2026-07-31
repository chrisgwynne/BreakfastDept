<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Nondestructive image derivatives for case studies (crops, focal zoom, source
 * rotation). The ORIGINAL is never modified — a derivative is a new file plus a
 * metadata record capturing exactly how it was made (source version, crop
 * coordinates, dimensions, format, quality, creator, time, usage references).
 *
 * If a source image changes, its derivatives are not silently invalidated: the
 * stored source version no longer matches, so {@see stale()} flags them for human
 * review rather than serving something the crop was never checked against.
 */
final class Derivatives
{
    private const COLLECTION = 'case_study_derivatives';

    /** Named crop kinds → target aspect (w/h); 'detail' is free-form. */
    public const KINDS = ['card', 'hero', 'social', 'mobile', 'detail'];

    /** Output is capped so a derivative can never be enormous. */
    private const MAX_DIM = 2200;

    public function __construct(
        private readonly FileStore $store,
        private readonly string $storageDir,
    ) {
    }

    /**
     * Create a derivative from a source image on disk.
     *
     * @param array{
     *   project_uuid:string, source_path:string, source_name?:string, kind?:string,
     *   crop?:array{x:float,y:float,w:float,h:float}, focal?:array{x:float,y:float},
     *   rotate90?:int, quality?:int, format?:string, creator?:?string, label?:string
     * } $params
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        $src = $params['source_path'];
        if (!is_file($src)) {
            throw new \RuntimeException('source_not_found');
        }
        $info = @getimagesize($src);
        if ($info === false) {
            throw new \RuntimeException('source_not_image');
        }
        [$sw, $sh] = [$info[0], $info[1]];

        // Normalised crop rectangle, clamped inside the image.
        $crop = $params['crop'] ?? ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];
        $cx = $this->clamp01($crop['x'] ?? 0);
        $cy = $this->clamp01($crop['y'] ?? 0);
        $cw = $this->clamp01($crop['w'] ?? 1);
        $ch = $this->clamp01($crop['h'] ?? 1);
        if ($cx + $cw > 1) {
            $cw = 1 - $cx;
        }
        if ($cy + $ch > 1) {
            $ch = 1 - $cy;
        }
        if ($cw <= 0 || $ch <= 0) {
            throw new \RuntimeException('invalid_crop');
        }

        $rotateRaw = (int) ($params['rotate90'] ?? 0);
        $rotate = in_array($rotateRaw, [0, 90, 180, 270], true) ? $rotateRaw : 0;
        $kindRaw = (string) ($params['kind'] ?? 'detail');
        $kind = in_array($kindRaw, self::KINDS, true) ? $kindRaw : 'detail';
        $quality = max(40, min(95, (int) ($params['quality'] ?? 82)));
        $formatRaw = (string) ($params['format'] ?? 'jpg');
        $format = in_array($formatRaw, ['jpg', 'png', 'webp'], true) ? $formatRaw : 'jpg';

        $uuid = Uuid::v4();
        $rel = 'derivatives/' . $params['project_uuid'] . '/' . $uuid . '.' . $format;
        $abs = rtrim($this->storageDir, '/') . '/' . $rel;
        [$outW, $outH] = $this->render($src, $info[2], (int) ($cx * $sw), (int) ($cy * $sh), (int) ($cw * $sw), (int) ($ch * $sh), $rotate, $abs, $format, $quality);

        $now = Clock::nowIso();
        $record = [
            'uuid'           => $uuid,
            'project_uuid'   => (string) $params['project_uuid'],
            'source_name'    => (string) ($params['source_name'] ?? basename($src)),
            'source_version' => (string) (@sha1_file($src) ?: ''),
            'kind'           => $kind,
            'label'          => (string) ($params['label'] ?? $kind),
            'crop'           => ['x' => $cx, 'y' => $cy, 'w' => $cw, 'h' => $ch],
            'focal'          => ['x' => $this->clamp01($params['focal']['x'] ?? 0.5), 'y' => $this->clamp01($params['focal']['y'] ?? 0.5)],
            'rotate90'       => $rotate,
            'width'          => $outW,
            'height'         => $outH,
            'format'         => $format,
            'quality'        => $quality,
            'file'           => $rel,
            'bytes'          => is_file($abs) ? (int) filesize($abs) : 0,
            'creator'        => $params['creator'] ?? null,
            'usage'          => [],
            'needs_review'   => false,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];
        $this->store->put(self::COLLECTION, $record);

        return $record;
    }

    /** @return array<string,mixed>|null */
    public function get(string $uuid): ?array
    {
        return $this->store->find(self::COLLECTION, $uuid);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(string $projectUuid): array
    {
        $rows = array_filter($this->store->all(self::COLLECTION), static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === $projectUuid);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $rows;
    }

    /**
     * Record a place this derivative is used (e.g. a block id).
     *
     * @return array<string,mixed>|null
     */
    public function addUsage(string $uuid, string $ref): ?array
    {
        return $this->store->update(self::COLLECTION, $uuid, static function (array $r) use ($ref): array {
            $usage = is_array($r['usage'] ?? null) ? $r['usage'] : [];
            if (!in_array($ref, $usage, true)) {
                $usage[] = $ref;
            }
            $r['usage'] = array_values($usage);
            $r['updated_at'] = Clock::nowIso();

            return $r;
        });
    }

    /**
     * True if the current source content no longer matches the version this
     * derivative was cut from — i.e. the crop should be re-reviewed. Also flips
     * the stored `needs_review` flag so it surfaces in listings.
     */
    public function stale(string $uuid, string $currentSourcePath): bool
    {
        $rec = $this->get($uuid);
        if ($rec === null) {
            return false;
        }
        $current = is_file($currentSourcePath) ? (string) (@sha1_file($currentSourcePath) ?: '') : '';
        $stale = $current !== '' && $current !== (string) ($rec['source_version'] ?? '');
        if ($stale && ($rec['needs_review'] ?? false) !== true) {
            $this->store->update(self::COLLECTION, $uuid, static function (array $r): array {
                $r['needs_review'] = true;
                $r['updated_at'] = Clock::nowIso();

                return $r;
            });
        }

        return $stale;
    }

    /** @return array<string,mixed>|null */
    public function markReviewed(string $uuid, string $currentSourcePath, ?string $actor = null): ?array
    {
        return $this->store->update(self::COLLECTION, $uuid, static function (array $r) use ($currentSourcePath, $actor): array {
            $r['needs_review'] = false;
            $r['source_version'] = is_file($currentSourcePath) ? (string) (@sha1_file($currentSourcePath) ?: $r['source_version']) : $r['source_version'];
            $r['reviewed_by'] = $actor;
            $r['updated_at'] = Clock::nowIso();

            return $r;
        });
    }

    private function clamp01(mixed $v): float
    {
        $n = is_numeric($v) ? (float) $v : 0.0;

        return round(max(0.0, min(1.0, $n)), 4);
    }

    /**
     * Crop + optional 90° rotation + resample to the output file. Returns the
     * final [w,h]. Reads only from the source; never writes to it.
     *
     * @return array{0:int,1:int}
     */
    private function render(string $src, int $type, int $x, int $y, int $w, int $h, int $rotate, string $abs, string $format, int $quality): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('gd_unavailable');
        }
        $im = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            default        => false,
        };
        if ($im === false) {
            throw new \RuntimeException('source_decode_failed');
        }
        $w = max(1, $w);
        $h = max(1, $h);
        $crop = imagecreatetruecolor($w, $h);
        imagecopy($crop, $im, 0, 0, $x, $y, $w, $h);
        imagedestroy($im);
        if ($rotate !== 0) {
            $rot = imagerotate($crop, -$rotate, 0);
            if ($rot !== false) {
                imagedestroy($crop);
                $crop = $rot;
                $w = imagesx($crop);
                $h = imagesy($crop);
            }
        }
        // Cap output dimensions.
        if ($w > self::MAX_DIM || $h > self::MAX_DIM) {
            $scale = self::MAX_DIM / max($w, $h);
            $nw = max(1, (int) ($w * $scale));
            $nh = max(1, (int) ($h * $scale));
            $resized = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($resized, $crop, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($crop);
            $crop = $resized;
            $w = $nw;
            $h = $nh;
        }
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        match ($format) {
            'png'  => imagepng($crop, $abs),
            'webp' => imagewebp($crop, $abs, $quality),
            default => imagejpeg($crop, $abs, $quality),
        };
        imagedestroy($crop);
        @chmod($abs, 0640);

        return [$w, $h];
    }
}
