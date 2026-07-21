<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Support\Clock;

/**
 * Orchestrates an upload into a new, unpublished version:
 *   create version → validate into scratch → on success promote the validated
 *   files into the immutable version directory and mark it "ready"; on failure
 *   mark it "validation_failed" and keep the report.
 *
 * Publishing is a SEPARATE, human step (PreviewPublisher) — a fresh upload is
 * never automatically made live.
 */
final class PreviewUploadService
{
    /**
     * @param array<string,mixed> $config previews config block
     */
    public function __construct(
        private readonly PreviewRepository $previews,
        private readonly PreviewVersionRepository $versions,
        private readonly PreviewStorage $storage,
        private readonly PreviewArchiveValidator $validator,
        private readonly PreviewManifestBuilder $manifests,
        private readonly array $config,
    ) {
    }

    /**
     * @return array{ok:bool,error?:string,version?:array<string,mixed>,report?:array<string,mixed>}
     */
    public function uploadZip(string $previewUuid, string $zipPath, string $originalFilename, ?string $actor): array
    {
        return $this->run($previewUuid, 'zip', $originalFilename, $actor, function (string $filesDest) use ($zipPath): array {
            return $this->validator->validateZip($zipPath, $filesDest, $this->limits());
        }, is_file($zipPath) ? (string) hash_file('sha256', $zipPath) : null);
    }

    /**
     * @param list<array{name:string,tmp:string,size:int}> $uploads
     * @return array{ok:bool,error?:string,version?:array<string,mixed>,report?:array<string,mixed>}
     */
    public function uploadFiles(string $previewUuid, array $uploads, ?string $actor): array
    {
        return $this->run($previewUuid, 'files', null, $actor, function (string $filesDest) use ($uploads): array {
            return $this->validator->validateFiles($uploads, $filesDest, $this->limits());
        }, null);
    }

    /**
     * @param callable(string):array{ok:bool,errors:list<string>,warnings:list<string>,entry_file:string,bytes:int,file_count:int,files:list<array{path:string,bytes:int,mime:string}>} $validate
     * @return array{ok:bool,error?:string,version?:array<string,mixed>,report?:array<string,mixed>}
     */
    private function run(string $previewUuid, string $sourceType, ?string $originalFilename, ?string $actor, callable $validate, ?string $checksum): array
    {
        $preview = $this->previews->find($previewUuid);
        if ($preview === null) {
            return ['ok' => false, 'error' => 'preview_not_found'];
        }

        $this->storage->ensureRoot();

        $version = $this->versions->create($previewUuid, [
            'state'             => PreviewStatus::V_VALIDATING,
            'source_type'       => $sourceType,
            'original_filename' => $originalFilename,
            'checksum'          => $checksum,
            'storage_path'      => $this->storage->relativeVersionDir($previewUuid, 'pending'),
            'created_by'        => $actor,
        ]);
        $versionUuid = (string) $version['uuid'];

        $temp      = $this->storage->createTempDir($versionUuid . '-' . bin2hex(random_bytes(4)));
        $filesDest = $temp . '/files';
        @mkdir($filesDest, 0755, true);

        $report = $validate($filesDest);

        if ($report['ok'] === false) {
            $this->storage->deleteTree($temp);
            $this->versions->update($versionUuid, [
                'state'             => PreviewStatus::V_VALIDATION_FAILED,
                'validation_report' => ['errors' => $report['errors'], 'warnings' => $report['warnings']],
            ]);

            return ['ok' => false, 'error' => 'validation_failed', 'version' => $this->versions->find($versionUuid), 'report' => $report];
        }

        // Promote validated files into the immutable version directory.
        $this->storage->promote($filesDest, $previewUuid, $versionUuid);
        $this->storage->deleteTree($temp);

        $manifestJson = $this->manifests->build(
            $previewUuid,
            $versionUuid,
            (int) $version['version_number'],
            $report['entry_file'],
            $report['files'],
            Clock::nowIso()
        );
        $manifestPath = $this->storage->writeManifest($previewUuid, $versionUuid, $manifestJson);

        $this->versions->update($versionUuid, [
            'state'             => PreviewStatus::V_READY,
            'entry_file'        => $report['entry_file'],
            'bytes'             => $report['bytes'],
            'file_count'        => $report['file_count'],
            'storage_path'      => $this->storage->relativeVersionDir($previewUuid, $versionUuid),
            'manifest_path'     => basename($manifestPath),
            'validation_report' => ['errors' => [], 'warnings' => $report['warnings']],
        ]);

        return ['ok' => true, 'version' => $this->versions->find($versionUuid), 'report' => $report];
    }

    /**
     * @return array{maxZipMb:int,maxExtractedMb:int,maxFileMb:int,maxFiles:int,maxDepth:int,allowVideo:bool}
     */
    private function limits(): array
    {
        $limits = is_array($this->config['limits'] ?? null) ? $this->config['limits'] : [];

        return [
            'maxZipMb'       => (int) ($limits['maxZipMb'] ?? 50),
            'maxExtractedMb' => (int) ($limits['maxExtractedMb'] ?? 250),
            'maxFileMb'      => (int) ($limits['maxFileMb'] ?? 25),
            'maxFiles'       => (int) ($limits['maxFiles'] ?? 3000),
            'maxDepth'       => (int) ($limits['maxDepth'] ?? 20),
            'allowVideo'     => (bool) ($this->config['allowVideo'] ?? false),
        ];
    }
}
