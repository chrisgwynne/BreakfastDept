<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Validates and safely materialises an uploaded preview package (a ZIP or a set
 * of individual files) into a scratch `files/` directory.
 *
 * Security posture: DENY BY DEFAULT and NEVER trust archive metadata.
 *  - Paths are normalised and traversal/absolute/backslash/null-byte forms are
 *    rejected before any byte is written.
 *  - Only allow-listed extensions are accepted; executable/server types are
 *    rejected explicitly; dotfiles and server-config names are rejected.
 *  - Total and per-file uncompressed sizes, file counts and directory depth are
 *    capped; suspicious compression ratios (zip bombs) are rejected.
 *  - Nothing is extracted with ZipArchive::extractTo — each entry's bytes are
 *    read and written to a path WE construct from the normalised name, so
 *    "zip-slip" is impossible and symlinks can never be created.
 *  - SVGs are sanitised. HTML/CSS/JS are scanned for non-blocking quality
 *    warnings (external scripts, trackers, external forms, source maps, likely
 *    secrets, missing title/viewport/favicon).
 *
 * Blocking errors cannot be overridden by the operator; warnings can.
 *
 * @phpstan-type PreviewFile array{path:string,bytes:int,mime:string}
 * @phpstan-type ValidationReport array{ok:bool,errors:list<string>,warnings:list<string>,entry_file:string,bytes:int,file_count:int,files:list<PreviewFile>}
 */
final class PreviewArchiveValidator
{
    private const ZIP_BOMB_RATIO = 200;

    /**
     * @param array{maxZipMb:int,maxExtractedMb:int,maxFileMb:int,maxFiles:int,maxDepth:int,allowVideo:bool} $limits
     * @return ValidationReport
     */
    public function validateZip(string $zipPath, string $filesDestDir, array $limits): array
    {
        $errors   = [];
        $warnings = [];

        $maxZip = $limits['maxZipMb'] * 1024 * 1024;
        $zipSize = is_file($zipPath) ? (int) filesize($zipPath) : 0;
        if ($zipSize === 0) {
            return $this->fail(['empty_upload']);
        }
        if ($zipSize > $maxZip) {
            return $this->fail(['zip_too_large']);
        }

        $za = new \ZipArchive();
        if ($za->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            return $this->fail(['unreadable_or_corrupt_zip']);
        }

        $count = $za->numFiles;
        if ($count > $limits['maxFiles']) {
            $za->close();

            return $this->fail(['too_many_files']);
        }

        // Phase 1 — metadata scan (no extraction).
        $plan  = [];
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $za->statIndex($i);
            if ($stat === false) {
                $errors[] = 'unreadable_entry';
                continue;
            }
            $name = (string) $stat['name'];
            if (str_ends_with($name, '/')) {
                continue; // directory entry
            }

            $rel = PreviewPathGuard::normaliseRelative($name);
            if ($rel === null) {
                $errors[] = 'unsafe_path:' . $this->label($name);
                continue;
            }
            if (substr_count($rel, '/') + 1 > $limits['maxDepth']) {
                $errors[] = 'too_deep:' . $rel;
                continue;
            }
            if (PreviewPathGuard::isAllowedFile($rel, $limits['allowVideo']) === false) {
                $errors[] = $this->rejectReason($rel);
                continue;
            }

            $size  = (int) $stat['size'];
            $csize = (int) $stat['comp_size'];
            if ($size > $limits['maxFileMb'] * 1024 * 1024) {
                $errors[] = 'file_too_large:' . $rel;
                continue;
            }
            if ($size > 1024 * 1024 && $csize > 0 && ($size / $csize) > self::ZIP_BOMB_RATIO) {
                $errors[] = 'suspicious_compression:' . $rel;
                continue;
            }
            $total += $size;
            $plan[] = ['index' => $i, 'rel' => $rel, 'size' => $size];
        }

        if ($total > $limits['maxExtractedMb'] * 1024 * 1024) {
            $errors[] = 'extracted_too_large';
        }
        if ($errors !== []) {
            $za->close();

            return $this->fail($errors);
        }

        // Phase 2 — read each entry's bytes, process, write to our own path.
        // Accumulate the ACTUAL decompressed size and enforce the extracted cap
        // against real bytes (phase 1's total trusts archive metadata; a bomb can
        // understate declared sizes, so this is the authoritative check).
        $files      = [];
        $actualMax  = $limits['maxExtractedMb'] * 1024 * 1024;
        $actualUsed = 0;
        foreach ($plan as $item) {
            $bytes = $za->getFromIndex($item['index']);
            if ($bytes === false) {
                $errors[] = 'read_failed:' . $item['rel'];
                continue;
            }
            $actualUsed += strlen($bytes);
            if ($actualUsed > $actualMax) {
                $errors[] = 'extracted_too_large';
                break;
            }
            $processed = $this->processFile($item['rel'], $bytes, $warnings, $errors, $limits['allowVideo']);
            if ($processed === null) {
                continue; // a blocking error was recorded
            }
            $this->writeFile($filesDestDir, $item['rel'], $processed);
            $files[] = [
                'path'  => $item['rel'],
                'bytes' => strlen($processed),
                'mime'  => (string) PreviewPathGuard::mimeFor($item['rel'], $limits['allowVideo']),
            ];
        }
        $za->close();

        return $this->finish($filesDestDir, $files, $errors, $warnings);
    }

    /**
     * @param list<array{name:string,tmp:string,size:int}> $uploads
     * @param array{maxZipMb:int,maxExtractedMb:int,maxFileMb:int,maxFiles:int,maxDepth:int,allowVideo:bool} $limits
     * @return ValidationReport
     */
    public function validateFiles(array $uploads, string $filesDestDir, array $limits): array
    {
        $errors   = [];
        $warnings = [];

        if (count($uploads) > $limits['maxFiles']) {
            return $this->fail(['too_many_files']);
        }

        $files = [];
        $total = 0;
        foreach ($uploads as $u) {
            $rel = PreviewPathGuard::normaliseRelative($u['name']);
            if ($rel === null) {
                $errors[] = 'unsafe_path:' . $this->label($u['name']);
                continue;
            }
            if (substr_count($rel, '/') + 1 > $limits['maxDepth']) {
                $errors[] = 'too_deep:' . $rel;
                continue;
            }
            if (PreviewPathGuard::isAllowedFile($rel, $limits['allowVideo']) === false) {
                $errors[] = $this->rejectReason($rel);
                continue;
            }
            if ($u['size'] > $limits['maxFileMb'] * 1024 * 1024) {
                $errors[] = 'file_too_large:' . $rel;
                continue;
            }
            $total += $u['size'];
        }
        if ($total > $limits['maxExtractedMb'] * 1024 * 1024) {
            $errors[] = 'extracted_too_large';
        }
        if ($errors !== []) {
            return $this->fail($errors);
        }

        foreach ($uploads as $u) {
            $rel   = (string) PreviewPathGuard::normaliseRelative($u['name']);
            $bytes = is_file($u['tmp']) ? (string) file_get_contents($u['tmp']) : '';
            $processed = $this->processFile($rel, $bytes, $warnings, $errors, $limits['allowVideo']);
            if ($processed === null) {
                continue;
            }
            $this->writeFile($filesDestDir, $rel, $processed);
            $files[] = [
                'path'  => $rel,
                'bytes' => strlen($processed),
                'mime'  => (string) PreviewPathGuard::mimeFor($rel, $limits['allowVideo']),
            ];
        }

        return $this->finish($filesDestDir, $files, $errors, $warnings);
    }

    /**
     * Process one file's bytes: sanitise SVG (blocking on failure) and collect
     * content warnings. Returns the bytes to write, or null if a blocking error
     * was recorded.
     *
     * @param list<string> $warnings
     * @param list<string> $errors
     */
    private function processFile(string $rel, string $bytes, array &$warnings, array &$errors, bool $allowVideo): ?string
    {
        $ext = PreviewPathGuard::extension($rel);

        if ($ext === 'svg') {
            $result = PreviewSvgSanitizer::sanitize($bytes);
            if ($result['ok'] === false) {
                $errors[] = 'svg_sanitise_failed:' . $rel;

                return null;
            }
            $bytes = $result['svg'] ?? $bytes;
        }

        if (PreviewPathGuard::isServiceWorkerName($rel)) {
            $warnings[] = 'service_worker_present:' . $rel;
        }
        if ($ext === 'map') {
            $warnings[] = 'source_map_present:' . $rel;
        }

        if (in_array($ext, ['html', 'htm'], true)) {
            $this->scanHtml($rel, $bytes, $warnings);
        }
        if (in_array($ext, ['html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'xml'], true)) {
            $this->scanForSecrets($rel, $bytes, $warnings);
        }

        return $bytes;
    }

    /** @param list<string> $warnings */
    private function scanHtml(string $rel, string $html, array &$warnings): void
    {
        if (preg_match('/<script[^>]+src=["\']https?:\/\//i', $html) === 1) {
            $warnings[] = 'external_script:' . $rel;
        }
        foreach (['google-analytics', 'googletagmanager', 'gtag(', 'connect.facebook', 'hotjar', 'segment.com', 'mixpanel', 'fbq('] as $tracker) {
            if (stripos($html, $tracker) !== false) {
                $warnings[] = 'tracker:' . $rel;
                break;
            }
        }
        if (preg_match('/<form[^>]+action=["\']https?:\/\//i', $html) === 1) {
            $warnings[] = 'external_form:' . $rel;
        }
        if (preg_match('/(src|href)=["\']http:\/\//i', $html) === 1) {
            $warnings[] = 'mixed_content:' . $rel;
        }
        // Basic quality signals on the entry-ish documents.
        if (stripos($html, '<title') === false) {
            $warnings[] = 'missing_title:' . $rel;
        }
        if (stripos($html, 'name="viewport"') === false && stripos($html, "name='viewport'") === false) {
            $warnings[] = 'missing_viewport:' . $rel;
        }
    }

    /** @param list<string> $warnings */
    private function scanForSecrets(string $rel, string $content, array &$warnings): void
    {
        $patterns = [
            '/AKIA[0-9A-Z]{16}/',                       // AWS access key id
            '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
            '/xox[baprs]-[0-9A-Za-z-]{10,}/',           // Slack token
            '/AIza[0-9A-Za-z_\-]{35}/',                 // Google API key
            '/(?:secret|api[_-]?key|password)\s*[:=]\s*["\'][^"\']{12,}["\']/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $warnings[] = 'possible_secret:' . $rel;

                return;
            }
        }
    }

    /**
     * Finalise: require an entry document, compute totals, clean up on any
     * blocking error so no partial tree is ever promoted.
     *
     * @param list<PreviewFile> $files
     * @param list<string> $errors
     * @param list<string> $warnings
     * @return ValidationReport
     */
    private function finish(string $filesDestDir, array $files, array $errors, array $warnings): array
    {
        $entry = $this->detectEntry($files, $warnings);
        if ($entry === null) {
            $errors[] = 'no_entry_html';
        }
        if ($errors !== []) {
            (new PreviewStorage(dirname($filesDestDir)))->deleteTree($filesDestDir);

            return $this->fail($errors, $warnings);
        }

        $bytes = (int) array_sum(array_map(static fn (array $f): int => $f['bytes'], $files));

        return [
            'ok'         => true,
            'errors'     => [],
            'warnings'   => array_values(array_unique($warnings)),
            'entry_file' => (string) $entry,
            'bytes'      => $bytes,
            'file_count' => count($files),
            'files'      => $files,
        ];
    }

    /**
     * @param list<PreviewFile> $files
     * @param list<string> $warnings
     */
    private function detectEntry(array $files, array &$warnings): ?string
    {
        $paths = array_column($files, 'path');
        if (in_array('index.html', $paths, true)) {
            return 'index.html';
        }
        if (in_array('index.htm', $paths, true)) {
            return 'index.htm';
        }
        foreach ($paths as $path) {
            if (in_array(PreviewPathGuard::extension($path), ['html', 'htm'], true)) {
                $warnings[] = 'no_root_index:' . $path;

                return $path;
            }
        }

        return null;
    }

    private function writeFile(string $filesDestDir, string $rel, string $bytes): void
    {
        $full = rtrim($filesDestDir, '/') . '/' . $rel;
        $dir  = dirname($full);
        if (is_dir($dir) === false) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($full, $bytes);
        @chmod($full, 0644);
    }

    private function rejectReason(string $rel): string
    {
        $ext = PreviewPathGuard::extension($rel);
        if (in_array($ext, PreviewPathGuard::FORBIDDEN_EXT, true)) {
            return 'executable_forbidden:' . $rel;
        }
        foreach (explode('/', strtolower($rel)) as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return 'dotfile_forbidden:' . $rel;
            }
            if (in_array($segment, PreviewPathGuard::FORBIDDEN_NAMES, true)) {
                return 'server_config_forbidden:' . $rel;
            }
        }

        return 'disallowed_type:' . $rel;
    }

    private function label(string $name): string
    {
        return mb_substr(str_replace(["\0", "\n", "\r"], '', $name), 0, 80);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @return ValidationReport
     */
    private function fail(array $errors, array $warnings = []): array
    {
        return [
            'ok'         => false,
            'errors'     => array_values(array_unique($errors)),
            'warnings'   => array_values(array_unique($warnings)),
            'entry_file' => '',
            'bytes'      => 0,
            'file_count' => 0,
            'files'      => [],
        ];
    }
}
