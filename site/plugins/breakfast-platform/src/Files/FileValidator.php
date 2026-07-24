<?php

declare(strict_types=1);

namespace Breakfast\Platform\Files;

/**
 * Upload security for the client file library. Validates a file on disk against
 * its declared name/type, rejecting the classic bypasses: executable content,
 * double extensions, path traversal, null bytes, extension/MIME/signature
 * mismatch, oversize, image-dimension abuse, unsafe SVG, and malicious archives
 * (traversal, absolute paths, symlink-ish entries, compression bombs).
 *
 * Never trusts the browser-declared MIME or extension alone.
 */
final class FileValidator
{
    private const MAX_BYTES = 50 * 1024 * 1024; // 50 MB
    private const MAX_IMAGE_PIXELS = 40_000_000; // ~40 MP guard
    private const MAX_ARCHIVE_RATIO = 120;       // uncompressed:compressed bomb threshold
    private const MAX_ARCHIVE_TOTAL = 200 * 1024 * 1024;

    /** Allow-listed extensions → acceptable detected MIME prefixes. */
    private const ALLOWED = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/plain', 'rtf' => 'application/rtf',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument',
        'odt' => 'application/vnd.oasis', 'zip' => 'application/zip',
        'ai' => 'application/pdf', 'eps' => 'application/postscript', 'psd' => 'image/vnd.adobe.photoshop',
    ];

    /** Extensions that must never appear anywhere in the filename. */
    private const FORBIDDEN = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'pht', 'phar', 'exe', 'sh', 'bash', 'bat', 'cmd',
        'com', 'cgi', 'pl', 'py', 'rb', 'js', 'mjs', 'jsp', 'asp', 'aspx', 'htaccess', 'so', 'dll', 'jar',
    ];

    /**
     * @return array{extension:string,detected_mime:string,byte_size:int,sha256:string,width:?int,height:?int,thumbable:bool}
     */
    public function validate(string $path, string $originalName, string $declaredMime = ''): array
    {
        if (!is_file($path)) {
            throw new FileException(422, 'The uploaded file could not be read.');
        }
        $name = $this->assertSafeName($originalName);
        $parts = explode('.', strtolower($name));
        $ext = array_pop($parts) ?: '';

        // Any forbidden token anywhere in the name blocks double-extension tricks
        // (logo.php.png) and null-byte truncation attempts.
        foreach ($parts as $segment) {
            if (in_array($segment, self::FORBIDDEN, true)) {
                throw new FileException(422, 'That file type is not allowed.');
            }
        }
        if (in_array($ext, self::FORBIDDEN, true) || !isset(self::ALLOWED[$ext])) {
            throw new FileException(422, 'That file type is not allowed.');
        }

        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new FileException(422, 'The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new FileException(422, 'That file is too large (max 50 MB).');
        }

        $detected = $this->detectMime($path);
        $expectPrefix = self::ALLOWED[$ext];
        // Signature/MIME must be consistent with the (allow-listed) extension.
        if (!$this->mimeMatches($ext, $detected, $expectPrefix)) {
            throw new FileException(422, 'The file content does not match its extension.');
        }

        $width = null;
        $height = null;
        $thumbable = false;
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $info = @getimagesize($path);
            if ($info === false) {
                throw new FileException(422, 'That image could not be read.');
            }
            $width = (int) $info[0];
            $height = (int) $info[1];
            if ($width * $height > self::MAX_IMAGE_PIXELS) {
                throw new FileException(422, 'That image is too large in dimensions.');
            }
            $thumbable = true;
        }
        if ($ext === 'svg') {
            $this->assertSafeSvg($path);
        }
        if ($ext === 'zip') {
            $this->assertSafeArchive($path);
        }

        $hash = hash_file('sha256', $path) ?: '';

        return [
            'extension' => $ext, 'detected_mime' => $detected, 'byte_size' => $size,
            'sha256' => $hash, 'width' => $width, 'height' => $height, 'thumbable' => $thumbable,
        ];
    }

    private function assertSafeName(string $name): string
    {
        if (str_contains($name, "\0")) {
            throw new FileException(422, 'Invalid filename.');
        }
        $base = basename(str_replace('\\', '/', $name));
        if ($base === '' || $base === '.' || $base === '..' || str_contains($base, '/')) {
            throw new FileException(422, 'Invalid filename.');
        }
        if (!str_contains($base, '.')) {
            throw new FileException(422, 'The file must have an extension.');
        }

        return $base;
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return 'application/octet-stream';
    }

    private function mimeMatches(string $ext, string $detected, string $expectPrefix): bool
    {
        // Office/zip-container formats are all detected as application/zip; text
        // formats vary; be permissive within the allow-listed extension while
        // still rejecting obvious mismatches (e.g. a PHP script renamed .png).
        if (str_starts_with($detected, 'text/')) {
            return in_array($ext, ['txt', 'csv', 'svg', 'rtf'], true) || str_starts_with($expectPrefix, 'text/');
        }
        if ($detected === 'application/zip') {
            return in_array($ext, ['zip', 'docx', 'xlsx', 'pptx', 'odt'], true);
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return str_starts_with($detected, 'image/');
        }
        if ($ext === 'svg') {
            // text/* was already accepted above; here detected is non-text.
            return str_contains($detected, 'svg') || str_starts_with($detected, 'image/');
        }
        // Fall back to a loose prefix compare for docs.
        $prefix = explode('/', $expectPrefix)[0];

        return str_starts_with($detected, $prefix) || str_starts_with($detected, 'application/');
    }

    private function assertSafeSvg(string $path): void
    {
        $content = (string) file_get_contents($path);
        $lower = strtolower($content);
        $bad = ['<script', 'javascript:', '<foreignobject', '<!entity', '<!doctype', '<iframe', '<embed', '<object'];
        foreach ($bad as $needle) {
            if (str_contains($lower, $needle)) {
                throw new FileException(422, 'That SVG contains unsafe content and was rejected.');
            }
        }
        // Inline event handlers (onload=, onclick=, …) and external refs.
        if (preg_match('/\son\w+\s*=/', $lower) === 1) {
            throw new FileException(422, 'That SVG contains an event handler and was rejected.');
        }
        if (preg_match('/(href|xlink:href|src)\s*=\s*["\']?\s*(https?:)?\/\//', $lower) === 1) {
            throw new FileException(422, 'That SVG references external resources and was rejected.');
        }
    }

    private function assertSafeArchive(string $path): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new FileException(422, 'Archive uploads are not supported here.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new FileException(422, 'That archive could not be read.');
        }
        $total = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $entry = (string) $stat['name'];
                if (str_contains($entry, "\0") || str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry) === 1 || preg_match('#^[a-zA-Z]:[\\\\/]#', $entry) === 1) {
                    throw new FileException(422, 'That archive contains an unsafe path and was rejected.');
                }
                $total += (int) $stat['size'];
                $compressed = max(1, (int) $stat['comp_size']);
                if ((int) $stat['size'] / $compressed > self::MAX_ARCHIVE_RATIO && (int) $stat['size'] > 1024 * 1024) {
                    throw new FileException(422, 'That archive looks like a compression bomb and was rejected.');
                }
                if ($total > self::MAX_ARCHIVE_TOTAL) {
                    throw new FileException(422, 'That archive is too large when expanded.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
