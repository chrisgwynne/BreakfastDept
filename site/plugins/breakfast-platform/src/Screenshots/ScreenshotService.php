<?php

declare(strict_types=1);

namespace Breakfast\Platform\Screenshots;

use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\SafeUrl;
use Breakfast\Platform\Support\Uuid;

/**
 * The case-study screenshot library.
 *
 * Orchestrates safe capture (SSRF + host allow-list via {@see SafeUrl}), stores
 * image bytes on disk, and keeps a versioned metadata record per capture in the
 * flat-file store. Captures are NEVER publishable until a human has both
 * approved them for public use AND confirmed a privacy review — the two gates
 * are independent and both required. Recapture creates a new version and
 * supersedes (never overwrites) the previous one. Every action is audited.
 */
final class ScreenshotService
{
    private const COLLECTION = 'case_study_screenshots';

    /**
     * @param array{allowHosts?:list<string>,allowHttp?:bool} $config
     */
    public function __construct(
        private readonly FileStore $store,
        private readonly CaptureDriver $driver,
        private readonly AuditLog $audit,
        private readonly string $storageDir,
        private readonly array $config = [],
    ) {
    }

    /**
     * Capture a screenshot for a project from an approved live URL.
     *
     * @param list<string> $projectAllowedHosts hosts belonging to this project's linked site
     * @return array<string,mixed> the stored record
     * @throws \RuntimeException with a machine-readable reason on rejection/failure
     */
    public function capture(string $projectUuid, array $projectAllowedHosts, CaptureRequest $request, ?string $actor = null): array
    {
        $allowed = $this->allowedHosts($projectAllowedHosts);
        $check = SafeUrl::validate($request->url, $allowed, (bool) ($this->config['allowHttp'] ?? false));
        if ($check['ok'] !== true) {
            $this->audit->event('screenshot.rejected', 'project', $projectUuid, $actor, ['url' => $request->url, 'reason' => $check['error'] ?? 'unknown']);
            throw new \RuntimeException('url_rejected:' . ($check['error'] ?? 'unknown'));
        }

        $result = $this->driver->capture($request, $allowed);

        return $this->store(
            $projectUuid,
            $result,
            [
                'source'     => 'capture',
                'page_label' => $request->pageLabel,
                'url_path'   => (string) (parse_url($request->url, PHP_URL_PATH) ?: '/'),
                'source_url' => $request->url,
                'config'     => $request->toConfig(),
            ],
            null,
            $actor
        );
    }

    /**
     * Store a manually-uploaded screenshot (no capture, no network).
     *
     * @return array<string,mixed>
     */
    public function uploadManual(string $projectUuid, string $bytes, string $pageLabel, ?string $actor = null): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new \RuntimeException('invalid_image');
        }
        $result = new CaptureResult($bytes, (string) $info['mime'], (int) $info[0], (int) $info[1], 'upload', false);

        return $this->store($projectUuid, $result, [
            'source'     => 'upload',
            'page_label' => $pageLabel,
            'url_path'   => null,
            'source_url' => null,
            'config'     => [],
        ], null, $actor);
    }

    /**
     * Recapture: produce a NEW version that supersedes an existing capture. The
     * previous record is retained (status → superseded) — nothing is overwritten.
     *
     * @param list<string> $projectAllowedHosts
     * @return array<string,mixed>
     */
    public function recapture(string $uuid, CaptureRequest $request, array $projectAllowedHosts, ?string $actor = null): array
    {
        $prev = $this->get($uuid);
        if ($prev === null) {
            throw new \RuntimeException('not_found');
        }
        $allowed = $this->allowedHosts($projectAllowedHosts);
        $check = SafeUrl::validate($request->url, $allowed, (bool) ($this->config['allowHttp'] ?? false));
        if ($check['ok'] !== true) {
            throw new \RuntimeException('url_rejected:' . ($check['error'] ?? 'unknown'));
        }
        $result = $this->driver->capture($request, $allowed);
        $new = $this->store((string) $prev['project_uuid'], $result, [
            'source'     => 'capture',
            'page_label' => $request->pageLabel !== '' ? $request->pageLabel : (string) $prev['page_label'],
            'url_path'   => (string) (parse_url($request->url, PHP_URL_PATH) ?: '/'),
            'source_url' => $request->url,
            'config'     => $request->toConfig(),
        ], $prev, $actor);

        $this->store->update(self::COLLECTION, $uuid, static function (array $r): array {
            $r['status'] = 'superseded';
            $r['updated_at'] = Clock::nowIso();

            return $r;
        });

        return $new;
    }

    /**
     * Approve a capture for public use (independent of privacy review).
     *
     * @return array<string,mixed>|null
     */
    public function approvePublicUse(string $uuid, ?string $actor = null): ?array
    {
        $rec = $this->store->update(self::COLLECTION, $uuid, static function (array $r) use ($actor): array {
            $r['public_use_approved'] = true;
            $r['approved_by'] = $actor;
            $r['approval_date'] = Clock::nowIso();
            $r['updated_at'] = Clock::nowIso();

            return $r;
        });
        if ($rec !== null) {
            $this->audit->event('screenshot.public_use_approved', 'screenshot', $uuid, $actor, []);
        }

        return $rec;
    }

    /**
     * Record that a human has completed the privacy review for a capture.
     *
     * @return array<string,mixed>|null
     */
    public function markPrivacyReviewed(string $uuid, ?string $actor = null, string $notes = ''): ?array
    {
        $rec = $this->store->update(self::COLLECTION, $uuid, static function (array $r) use ($actor, $notes): array {
            $r['privacy_reviewed'] = true;
            $r['privacy_reviewed_by'] = $actor;
            $r['privacy_reviewed_at'] = Clock::nowIso();
            $r['privacy_notes'] = mb_substr($notes, 0, 500);
            $r['updated_at'] = Clock::nowIso();

            return $r;
        });
        if ($rec !== null) {
            $this->audit->event('screenshot.privacy_reviewed', 'screenshot', $uuid, $actor, []);
        }

        return $rec;
    }

    /** @return array<string,mixed>|null */
    public function archive(string $uuid, ?string $actor = null): ?array
    {
        $rec = $this->store->update(self::COLLECTION, $uuid, static function (array $r): array {
            $r['status'] = 'archived';
            $r['updated_at'] = Clock::nowIso();

            return $r;
        });
        if ($rec !== null) {
            $this->audit->event('screenshot.archived', 'screenshot', $uuid, $actor, []);
        }

        return $rec;
    }

    /** @return array<string,mixed>|null */
    public function restore(string $uuid, ?string $actor = null): ?array
    {
        return $this->store->update(self::COLLECTION, $uuid, static function (array $r): array {
            $r['status'] = 'captured';
            $r['updated_at'] = Clock::nowIso();

            return $r;
        });
    }

    /** @return array<string,mixed>|null */
    public function get(string $uuid): ?array
    {
        return $this->store->find(self::COLLECTION, $uuid);
    }

    /**
     * All non-archived captures for a project, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $projectUuid, bool $includeArchived = false): array
    {
        $rows = array_filter($this->store->all(self::COLLECTION), static function (array $r) use ($projectUuid, $includeArchived): bool {
            if ((string) ($r['project_uuid'] ?? '') !== $projectUuid) {
                return false;
            }

            return $includeArchived || ($r['status'] ?? '') !== 'archived';
        });
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $rows;
    }

    /**
     * The version chain (this capture and everything it replaced), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $uuid): array
    {
        $chain = [];
        $cur = $this->get($uuid);
        $guard = 0;
        while ($cur !== null && $guard++ < 100) {
            $chain[] = $cur;
            $replaces = (string) ($cur['replaces'] ?? '');
            $cur = $replaces !== '' ? $this->get($replaces) : null;
        }

        return $chain;
    }

    /**
     * A capture may be published only when it is live, approved for public use,
     * AND privacy-reviewed. Both human gates are mandatory.
     *
     * @param array<string,mixed> $record
     */
    public function publishable(array $record): bool
    {
        return ($record['status'] ?? '') === 'captured'
            && ($record['public_use_approved'] ?? false) === true
            && ($record['privacy_reviewed'] ?? false) === true;
    }

    /**
     * @param array{source:string,page_label:string,url_path:?string,source_url:?string,config:array<string,mixed>} $meta
     * @param array<string,mixed>|null $replaces
     * @return array<string,mixed>
     */
    private function store(string $projectUuid, CaptureResult $result, array $meta, ?array $replaces, ?string $actor): array
    {
        $uuid = Uuid::v4();
        $ext = match ($result->mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default      => 'png',
        };
        $dir = rtrim($this->storageDir, '/') . '/screenshots/' . $projectUuid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $rel = 'screenshots/' . $projectUuid . '/' . $uuid . '.' . $ext;
        $abs = rtrim($this->storageDir, '/') . '/' . $rel;
        if (@file_put_contents($abs, $result->bytes) === false) {
            throw new \RuntimeException('storage_failed');
        }
        @chmod($abs, 0640);

        $now = Clock::nowIso();
        $record = [
            'uuid'                 => $uuid,
            'project_uuid'         => $projectUuid,
            'page_label'           => $meta['page_label'],
            'url_path'             => $meta['url_path'],
            'source_url'           => $meta['source_url'],
            'source'               => $meta['source'],
            'viewport'             => (string) ($meta['config']['viewport'] ?? 'desktop'),
            'width'                => $result->width,
            'height'               => $result->height,
            'engine'               => $result->engine,
            'config'               => $meta['config'],
            'is_stub'              => $result->stub,
            'file'                 => $rel,
            'mime'                 => $result->mime,
            'bytes'                => strlen($result->bytes),
            'status'               => 'captured',
            'version'              => $replaces !== null ? ((int) ($replaces['version'] ?? 1) + 1) : 1,
            'replaces'             => $replaces !== null ? (string) $replaces['uuid'] : null,
            'public_use_approved'  => false,
            'approved_by'          => null,
            'approval_date'        => null,
            'privacy_reviewed'     => false,
            'privacy_reviewed_by'  => null,
            'privacy_reviewed_at'  => null,
            'privacy_notes'        => null,
            'captured_at'          => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
        $this->store->put(self::COLLECTION, $record);
        $this->audit->event('screenshot.capture', 'project', $projectUuid, $actor, [
            'screenshot' => $uuid,
            'source'     => $meta['source'],
            'viewport'   => $record['viewport'],
            'stub'       => $result->stub,
        ]);

        return $record;
    }

    /**
     * @param list<string> $projectAllowedHosts
     * @return list<string>
     */
    private function allowedHosts(array $projectAllowedHosts): array
    {
        $global = $this->config['allowHosts'] ?? [];
        $out = [];
        foreach ([...$projectAllowedHosts, ...$global] as $h) {
            $h = strtolower(trim((string) $h));
            if ($h !== '' && !in_array($h, $out, true)) {
                $out[] = $h;
            }
        }

        return $out;
    }
}
