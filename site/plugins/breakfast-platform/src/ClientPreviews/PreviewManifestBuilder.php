<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Builds the immutable per-version manifest.json written alongside a version's
 * files. The responder can use it to enumerate a version and to resolve an
 * allow-listed MIME type without touching arbitrary bytes.
 */
final class PreviewManifestBuilder
{
    /**
     * @param list<array{path:string,bytes:int,mime:string}> $files
     */
    public function build(
        string $previewUuid,
        string $versionUuid,
        int $versionNumber,
        string $entryFile,
        array $files,
        string $generatedAt
    ): string {
        $manifest = [
            'preview_uuid'   => $previewUuid,
            'version_uuid'   => $versionUuid,
            'version_number' => $versionNumber,
            'entry_file'     => $entryFile,
            'generated_at'   => $generatedAt,
            'file_count'     => count($files),
            'bytes'          => array_sum(array_map(static fn (array $f): int => $f['bytes'], $files)),
            'files'          => $files,
        ];

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
