<?php

declare(strict_types=1);

namespace Breakfast\Platform\Forms;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Secure file-upload handling for the start-a-project form.
 *
 * Uploads are OFF by default and, when enabled, are tightly constrained:
 * explicit MIME allow-list, extension cross-check, small size limit,
 * regenerated filenames, storage OUTSIDE the web root, no execution, and a
 * pluggable malware-scanner interface (no-op by default). File contents are
 * NEVER embedded in HTML emails — only referenced by a secure admin download.
 */
final class UploadHandler
{
    /** MIME => allowed extension. Deliberately narrow. */
    private const ALLOWED = [
        'application/pdf'  => 'pdf',
        'image/png'        => 'png',
        'image/jpeg'       => 'jpg',
        'image/webp'       => 'webp',
    ];

    /**
     * @param array{enabled?:bool,maxBytes?:int,scannerCmd?:?string} $config
     */
    public function __construct(
        private readonly Database $db,
        private readonly string $uploadsDir,
        private readonly array $config = []
    ) {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * Validate and store a single uploaded file (PHP $_FILES entry shape).
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool,error?:string,upload?:array<string,mixed>}
     */
    public function handle(array $file, ?string $enquiryUuid = null): array
    {
        if ($this->enabled() === false) {
            return ['ok' => false, 'error' => 'uploads_disabled'];
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true]; // No file is fine — the field is optional.
        }

        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'upload_error'];
        }

        $tmp  = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $max  = (int) ($this->config['maxBytes'] ?? 5_242_880);

        if ($tmp === '' || is_uploaded_file($tmp) === false && is_file($tmp) === false) {
            return ['ok' => false, 'error' => 'invalid_upload'];
        }

        if ($size <= 0 || $size > $max) {
            return ['ok' => false, 'error' => 'file_too_large'];
        }

        // Determine the true MIME from content, not the client-supplied type.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        if (isset(self::ALLOWED[$mime]) === false) {
            return ['ok' => false, 'error' => 'disallowed_type'];
        }

        // Cross-check the claimed extension against the detected MIME.
        $ext         = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $expectedExt = self::ALLOWED[$mime];
        if ($ext !== $expectedExt && !($mime === 'image/jpeg' && in_array($ext, ['jpg', 'jpeg'], true))) {
            return ['ok' => false, 'error' => 'extension_mismatch'];
        }

        // Regenerate the filename entirely; never trust the original.
        $storedName = Uuid::v4() . '.' . $expectedExt;
        $target     = rtrim($this->uploadsDir, '/') . '/' . $storedName;

        if (is_dir($this->uploadsDir) === false) {
            @mkdir($this->uploadsDir, 0770, true);
        }

        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $target) : @rename($tmp, $target);
        if ($moved === false) {
            return ['ok' => false, 'error' => 'store_failed'];
        }

        // Non-executable permissions.
        @chmod($target, 0640);

        $sha = hash_file('sha256', $target) ?: '';

        // Malware scan (interface; default scanner is a no-op → 'skipped').
        $scanStatus = $this->scan($target);
        if ($scanStatus === 'infected') {
            @unlink($target);

            return ['ok' => false, 'error' => 'file_rejected'];
        }

        $uuid = Uuid::v4();
        $this->db->run(
            'INSERT INTO uploads (uuid, enquiry_uuid, original_name, stored_name, mime, size_bytes, sha256, scan_status, created_at)
             VALUES (:uuid, :enquiry, :orig, :stored, :mime, :size, :sha, :scan, :created)',
            [
                'uuid'    => $uuid,
                'enquiry' => $enquiryUuid,
                'orig'    => mb_substr((string) ($file['name'] ?? 'file'), 0, 200),
                'stored'  => $storedName,
                'mime'    => $mime,
                'size'    => $size,
                'sha'     => $sha,
                'scan'    => $scanStatus,
                'created' => Clock::nowIso(),
            ]
        );

        return ['ok' => true, 'upload' => [
            'uuid'        => $uuid,
            'stored_name' => $storedName,
            'mime'        => $mime,
            'size'        => $size,
            'scan_status' => $scanStatus,
        ]];
    }

    public function pathFor(string $storedName): string
    {
        return rtrim($this->uploadsDir, '/') . '/' . basename($storedName);
    }

    /**
     * Malware scan interface. Runs an external command if configured; otherwise
     * returns 'skipped'. The command receives the file path as argv[1] and must
     * exit 0 for clean, non-zero for infected.
     */
    private function scan(string $path): string
    {
        $cmd = $this->config['scannerCmd'] ?? null;

        if ($cmd === null || $cmd === '') {
            return 'skipped';
        }

        $full = escapeshellcmd($cmd) . ' ' . escapeshellarg($path);
        exec($full, $out, $code);

        return $code === 0 ? 'clean' : 'infected';
    }
}
