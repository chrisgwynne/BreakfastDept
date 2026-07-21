<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Support\Clock;

/**
 * The decision engine for the preview origin. Given a slug, a request path and a
 * minimal request context (session token, IP, UA, referer), it decides what the
 * visitor may see and resolves the exact file to stream — without ever executing
 * anything, leaking the existence of hidden previews, or serving from the admin
 * origin.
 *
 * All disk access goes through PreviewPathGuard::resolveServed, which guarantees
 * the resolved path stays inside the published version's files directory. This
 * class is Kirby-free; the HTTP responder wires cookies/headers around it.
 *
 * @phpstan-type Decision array{type:string,status:int,file?:string,mime?:string,indexable?:bool,preview_uuid?:string,version_uuid?:string,is_entry?:bool}
 */
final class PreviewAccessService
{
    /**
     * @param array<string,mixed> $config previews config block
     */
    public function __construct(
        private readonly PreviewRepository $previews,
        private readonly PreviewVersionRepository $versions,
        private readonly PreviewStorage $storage,
        private readonly PreviewPasswordService $passwords,
        private readonly PreviewActivityRepository $activity,
        private readonly array $config,
    ) {
    }

    /**
     * @param array{token?:?string,ip?:?string,ua?:?string,referer_host?:?string} $context
     * @return Decision
     */
    public function resolve(string $slug, string $requestPath, array $context): array
    {
        $preview = $this->previews->findBySlug($slug);
        if ($preview === null) {
            return ['type' => 'not_found', 'status' => 404];
        }

        $previewUuid = (string) $preview['uuid'];
        $status      = (string) $preview['status'];

        // Neutral status pages — never reveal client/CRM detail.
        if ($status === PreviewStatus::DISABLED) {
            $this->record($previewUuid, null, 'disabled', $requestPath, 403, $context, (int) ($preview['analytics_enabled'] ?? 1));

            return ['type' => 'disabled', 'status' => 403];
        }
        if ($status === PreviewStatus::EXPIRED || $this->isExpired($preview)) {
            $this->record($previewUuid, null, 'expired', $requestPath, 410, $context, (int) ($preview['analytics_enabled'] ?? 1));

            return ['type' => 'expired', 'status' => 410];
        }
        if (PreviewStatus::isServable($status) === false) {
            // draft/processing/failed/archived — do not confirm existence.
            return ['type' => 'not_found', 'status' => 404];
        }

        // Password gate.
        if ((string) $preview['visibility'] === PreviewStatus::VISIBILITY_PASSWORD) {
            $token = $context['token'] ?? null;
            if ($token === null || $this->passwords->verifyToken($preview, $token) === false) {
                return ['type' => 'password', 'status' => 401, 'preview_uuid' => $previewUuid];
            }
        }

        // Resolve the current published version's files.
        $versionUuid = (string) ($preview['current_version_uuid'] ?? '');
        $version     = $versionUuid !== '' ? $this->versions->find($versionUuid) : null;
        if ($version === null) {
            return ['type' => 'not_found', 'status' => 404];
        }

        $filesDir = $this->storage->absolute((string) $version['storage_path']) . '/files';
        $target   = $requestPath === '' || $requestPath === '/' ? (string) $preview['entry_file'] : $requestPath;
        $file     = PreviewPathGuard::resolveServed($filesDir, $target);
        if ($file === null) {
            // Try the entry file for extensionless / directory requests.
            $file = PreviewPathGuard::resolveServed($filesDir, rtrim($target, '/') . '/index.html');
        }
        if ($file === null) {
            $this->record($previewUuid, $versionUuid, 'view', $requestPath, 404, $context, (int) ($preview['analytics_enabled'] ?? 1));

            return ['type' => 'not_found', 'status' => 404];
        }

        $mime      = (string) PreviewPathGuard::mimeFor($file, (bool) ($this->config['allowVideo'] ?? false));
        $isEntry   = basename($file) === (string) $preview['entry_file'] || str_ends_with(strtolower($file), '.html') || str_ends_with(strtolower($file), '.htm');
        $eventType = $isEntry ? 'view' : 'asset';

        $this->record($previewUuid, $versionUuid, $eventType, $requestPath, 200, $context, (int) ($preview['analytics_enabled'] ?? 1));
        if ($isEntry) {
            $this->previews->recordView($previewUuid);
        }

        return [
            'type'         => 'file',
            'status'       => 200,
            'file'         => $file,
            'mime'         => $mime,
            'indexable'    => ((int) $preview['indexable'] === 1) && (string) $preview['visibility'] === PreviewStatus::VISIBILITY_PUBLIC,
            'preview_uuid' => $previewUuid,
            'version_uuid' => $versionUuid,
            'is_entry'     => $isEntry,
        ];
    }

    /**
     * @param array<string,mixed> $preview
     */
    private function isExpired(array $preview): bool
    {
        $expiresAt = $preview['expires_at'] ?? null;

        return is_string($expiresAt) && $expiresAt !== '' && $expiresAt < Clock::nowIso();
    }

    /**
     * @param array{token?:?string,ip?:?string,ua?:?string,referer_host?:?string} $context
     */
    private function record(string $previewUuid, ?string $versionUuid, string $type, string $path, int $status, array $context, int $analyticsEnabled): void
    {
        // Skip view/asset logging when the preview opted out of analytics, but
        // always record security-relevant events (disabled/expired/blocked).
        if ($analyticsEnabled === 0 && in_array($type, ['view', 'asset'], true)) {
            return;
        }

        $this->activity->recordAccess(
            $previewUuid,
            $versionUuid,
            $type,
            $path,
            $status,
            $context['ip'] ?? null,
            $context['ua'] ?? null,
            $context['referer_host'] ?? null,
            ($context['ip'] ?? '') . '|' . ($context['ua'] ?? '')
        );
    }
}
