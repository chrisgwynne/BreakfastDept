<?php

declare(strict_types=1);

namespace Breakfast\Platform\Website;

use Breakfast\Platform\ClientPreviews\PreviewSvgSanitizer;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;

/**
 * Media library over Kirby's native file model. Files are attached to a page
 * (or the site) exactly as Kirby stores them, so they are first-class content
 * objects — referenceable in fields, resizable, and served through Kirby.
 *
 * Uploads are validated hard (extension + sniffed MIME whitelist, size cap,
 * SVG sanitised to strip scripts/entities), replaced in place to keep existing
 * references valid, and only deleted when not in use — usage is tracked by
 * scanning content for the file's name and UUID, so a referenced asset can't be
 * removed out from under a live page by accident.
 */
final class WebsiteMedia
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
    private const DOC_EXT   = ['pdf'];

    /** Sniffed MIME must be in this set for the declared extension. */
    private const MIME = [
        'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'], 'gif' => ['image/gif'],
        'webp' => ['image/webp'], 'avif' => ['image/avif'],
        'svg'  => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
        'pdf'  => ['application/pdf'],
    ];

    private const MAX_BYTES = 12_582_912; // 12 MB

    public function __construct(
        private readonly App $kirby,
        private readonly Platform $platform,
    ) {
    }

    /**
     * List the media attached to a model, with usage counts.
     *
     * @return array<string,mixed>
     */
    public function list(string $modelId): array
    {
        $model = $this->model($modelId);
        $items = [];
        foreach ($model->files() as $file) {
            $items[] = $this->fileRow($file, $model);
        }

        return ['items' => $items, 'total' => count($items), 'model' => $modelId, 'max_bytes' => self::MAX_BYTES];
    }

    /**
     * Validate and store an uploaded file against the model.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload
     * @return array<string,mixed>
     */
    public function upload(string $modelId, array $upload, string $actor): array
    {
        $model = $this->model($modelId);
        $source = $this->validate($upload);

        $filename = $this->safeFilename((string) ($upload['name'] ?? 'file'));
        try {
            $file = $model->createFile([
                'source'   => $source['path'],
                'filename' => $filename,
                'template' => null,
            ]);
        } catch (\Throwable $e) {
            $this->cleanup($source);
            throw new WebsiteException(422, 'That file could not be stored: ' . $this->reason($e));
        }
        $this->cleanup($source);

        $this->audit('website.media_uploaded', $modelId, $actor, ['filename' => (string) $file->filename()]);

        return ['file' => $this->fileRow($file, $model)];
    }

    /**
     * Replace an existing file's contents in place (references stay valid).
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload
     * @return array<string,mixed>
     */
    public function replace(string $modelId, string $filename, array $upload, string $actor): array
    {
        $model = $this->model($modelId);
        $file  = $model->file($filename);
        if ($file === null) {
            throw new WebsiteException(404, 'That file could not be found.');
        }
        $source = $this->validate($upload, $file->extension());
        try {
            $file = $file->replace($source['path']);
        } catch (\Throwable $e) {
            $this->cleanup($source);
            throw new WebsiteException(422, 'That file could not be replaced: ' . $this->reason($e));
        }
        $this->cleanup($source);

        $this->audit('website.media_replaced', $modelId, $actor, ['filename' => $filename]);

        return ['file' => $this->fileRow($file, $model)];
    }

    /**
     * Delete a file. Blocked (409) when it is still referenced in content unless
     * $force is set — the caller must confirm after seeing the usage count.
     *
     * @return array<string,mixed>
     */
    public function delete(string $modelId, string $filename, string $actor, bool $force = false): array
    {
        $model = $this->model($modelId);
        $file  = $model->file($filename);
        if ($file === null) {
            throw new WebsiteException(404, 'That file could not be found.');
        }
        $uses = $this->usageCount($model, $file);
        if ($uses > 0 && !$force) {
            throw new WebsiteException(409, 'This file is used in ' . $uses . ' place' . ($uses === 1 ? '' : 's') . '. Remove those references first, or confirm to delete anyway.');
        }
        $file->delete();

        $this->audit('website.media_deleted', $modelId, $actor, ['filename' => $filename, 'forced' => $force]);

        return ['ok' => true, 'deleted' => $filename];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function model(string $id): ModelWithContent
    {
        if ($id === 'site') {
            return $this->kirby->site();
        }
        $page = $this->kirby->page($id);
        if ($page === null) {
            throw new WebsiteException(404, 'That page could not be found.');
        }

        return $page;
    }

    /**
     * Hard validation: presence, size, extension whitelist and a sniffed MIME
     * that matches the extension. SVGs are sanitised (scripts/entities stripped)
     * and rewritten to a temp file. Returns the path to store from.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload
     * @return array{path:string,temp:bool}
     */
    private function validate(array $upload, ?string $mustMatchExt = null): array
    {
        if ((int) ($upload['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            throw new WebsiteException(422, 'No file was uploaded.');
        }
        $tmp  = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp)) {
            throw new WebsiteException(422, 'No file was uploaded.');
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new WebsiteException(422, 'Files must be between 1 byte and ' . (int) (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }

        $ext = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, array_merge(self::IMAGE_EXT, self::DOC_EXT), true)) {
            throw new WebsiteException(422, 'Only images (JPG, PNG, GIF, WebP, AVIF, SVG) and PDFs are allowed.');
        }
        if ($mustMatchExt !== null && $ext !== strtolower($mustMatchExt)) {
            throw new WebsiteException(422, 'A replacement must be the same file type (.' . strtolower($mustMatchExt) . ').');
        }

        $mime = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::MIME[$ext] ?? [], true)) {
            throw new WebsiteException(422, 'That file’s contents don’t match its type.');
        }

        // SVGs are executable content — sanitise before it ever lands in the tree.
        if ($ext === 'svg') {
            $clean = PreviewSvgSanitizer::sanitize((string) file_get_contents($tmp));
            if (($clean['ok'] ?? false) !== true) {
                throw new WebsiteException(422, 'That SVG could not be safely processed.');
            }
            $out = tempnam(sys_get_temp_dir(), 'bfsvg') . '.svg';
            file_put_contents($out, (string) $clean['svg']);

            return ['path' => $out, 'temp' => true];
        }

        return ['path' => $tmp, 'temp' => false];
    }

    /** @param array{path:string,temp:bool} $source */
    private function cleanup(array $source): void
    {
        if ($source['temp'] && is_file($source['path'])) {
            @unlink($source['path']);
        }
    }

    private function safeFilename(string $name): string
    {
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME);
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($base)) ?? '';
        $slug = trim($slug, '-') ?: 'file';

        return substr($slug, 0, 80) . '.' . $ext;
    }

    /**
     * Count references to a file across the model's content (live + draft) —
     * by filename and by Kirby file UUID. Belt-and-braces for delete safety.
     */
    private function usageCount(ModelWithContent $model, File $file): int
    {
        $needles = [(string) $file->filename()];
        $uuid = $file->uuid()?->toString();
        if ($uuid !== null && $uuid !== '') {
            $needles[] = $uuid;
        }

        $haystack = '';
        foreach (['latest', 'changes'] as $vid) {
            $version = $model->version($vid === 'latest' ? \Kirby\Content\VersionId::latest() : \Kirby\Content\VersionId::changes());
            if ($version->exists('default')) {
                $haystack .= "\n" . json_encode($version->read('default'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $count = 0;
        foreach ($needles as $needle) {
            $count += substr_count($haystack, $needle);
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    private function fileRow(File $file, ModelWithContent $model): array
    {
        $isImage = in_array(strtolower($file->extension()), self::IMAGE_EXT, true);

        return [
            'filename'  => (string) $file->filename(),
            'url'       => (string) $file->url(),
            'extension' => strtolower((string) $file->extension()),
            'type'      => (string) $file->type(),
            'mime'      => (string) $file->mime(),
            'size'      => (int) $file->size(),
            'nice_size' => (string) $file->niceSize(),
            'is_image'  => $isImage,
            'width'     => $isImage && $file->type() === 'image' ? (int) $file->dimensions()->width() : 0,
            'height'    => $isImage && $file->type() === 'image' ? (int) $file->dimensions()->height() : 0,
            'uuid'      => (string) ($file->uuid()?->toString() ?? ''),
            'usage'     => $this->usageCount($model, $file),
            'model'     => $model instanceof Page ? (string) $model->id() : 'site',
        ];
    }

    private function reason(\Throwable $e): string
    {
        $msg = $e->getMessage();

        return $msg !== '' ? strip_tags($msg) : 'unknown error';
    }

    /** @param array<string,mixed> $meta */
    private function audit(string $event, string $modelId, string $actor, array $meta): void
    {
        $this->platform->audit()->event($event, 'website', $modelId, $actor, $meta);
    }
}
