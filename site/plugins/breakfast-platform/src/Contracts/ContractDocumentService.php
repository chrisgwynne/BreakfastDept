<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

/**
 * Generates, stores and serves the real PDFs for a contract: the immutable
 * unsigned version (frozen at send) and the final signed version (body + a
 * signature certificate). Both are written OUTSIDE the webroot under opaque
 * 0600 keys with their sha256 recorded, integrity-checked on download; the
 * unsigned original is always preserved alongside the signed document. A draft
 * preview renders live data with a DRAFT watermark and is never stored.
 */
final class ContractDocumentService
{
    public function __construct(
        private readonly Contracts $contracts,
        private readonly ContractPdfRenderer $renderer,
        private readonly string $baseDir,
    ) {
    }

    /**
     * Render + store the immutable UNSIGNED PDF from the frozen snapshot.
     *
     * @return array<string,mixed>
     */
    public function generateUnsigned(string $uuid, string $actor): array
    {
        $snapshot = $this->contracts->snapshot($uuid);
        if ($snapshot === null) {
            throw new ContractException(409, 'Send the contract before generating its PDF.');
        }
        try {
            $bytes = $this->renderer->render($snapshot, false, []);
            $key   = $this->store($uuid, $bytes, 'unsigned');
        } catch (\Throwable $e) {
            $this->contracts->setDocumentStatus($uuid, 'failed');
            throw $e instanceof ContractException ? $e : new ContractException(500, 'The contract PDF could not be generated.');
        }
        $this->contracts->setDocument($uuid, 'unsigned', $key, hash('sha256', $bytes), 'unsigned');
        $this->contracts->logEvent($uuid, 'document_generated', 'Unsigned PDF generated', $actor);

        return $this->contracts->find($uuid) ?? [];
    }

    /**
     * Render + store the final SIGNED PDF (body + certificate) once all required
     * parties have signed. Preserves the unsigned original.
     *
     * @return array<string,mixed>
     */
    public function generateSigned(string $uuid, string $actor): array
    {
        $c = $this->contracts->find($uuid);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        if (($c['completed_at'] ?? '') === '' || ($c['completed_at'] ?? null) === null) {
            throw new ContractException(409, 'All required parties must sign before the signed PDF is generated.');
        }
        $snapshot   = $this->contracts->snapshot($uuid);
        $signatures = is_array($c['signatures'] ?? null) ? $c['signatures'] : [];
        if ($snapshot === null) {
            throw new ContractException(409, 'This contract has no frozen version.');
        }
        $bytes = $this->renderer->render($snapshot, false, $signatures);
        $key   = $this->store($uuid, $bytes, 'signed');
        $this->contracts->setDocument($uuid, 'signed', $key, hash('sha256', $bytes), 'signed');
        $this->contracts->logEvent($uuid, 'signed_document_generated', 'Signed PDF generated', $actor);

        return $this->contracts->find($uuid) ?? [];
    }

    /** A replaceable DRAFT-watermarked preview from the frozen or live snapshot. */
    public function draft(string $uuid): string
    {
        $c = $this->contracts->find($uuid);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        $snapshot = $this->contracts->snapshot($uuid);
        if ($snapshot === null) {
            // Build a preview snapshot from the live draft sections.
            $snapshot = ContractSnapshot::build($c, is_array($c['sections_list'] ?? null) ? $c['sections_list'] : [], [], is_array($c['parties'] ?? null) ? $c['parties'] : []);
        }

        return $this->renderer->render($snapshot, true, []);
    }

    /**
     * Read a stored PDF (which = unsigned|signed) with an integrity check.
     *
     * @return array{filename:string,bytes:string}
     */
    public function download(string $uuid, string $which): array
    {
        $c = $this->contracts->find($uuid);
        if ($c === null) {
            throw new ContractException(404, 'Contract not found.');
        }
        $col  = $which === 'signed' ? 'signed' : 'unsigned';
        $key  = (string) ($c[$col . '_key'] ?? '');
        $hash = (string) ($c[$col . '_hash'] ?? '');
        if ($key === '') {
            throw new ContractException(404, 'No ' . $col . ' document available.');
        }
        $real     = realpath($this->baseDir . '/' . $key);
        $baseReal = realpath($this->baseDir);
        if ($real === false || $baseReal === false || strncmp($real, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) !== 0) {
            throw new ContractException(404, 'Document not found.');
        }
        $bytes = file_get_contents($real);
        if ($bytes === false || hash('sha256', $bytes) !== $hash) {
            throw new ContractException(409, 'The stored document failed its integrity check.');
        }
        $suffix = $col === 'signed' ? '-SIGNED' : '';

        return ['filename' => 'BREAKFAST-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($c['number'] ?? 'contract')) . $suffix . '.pdf', 'bytes' => $bytes];
    }

    private function store(string $uuid, string $bytes, string $which): string
    {
        $dir = $this->baseDir . '/' . $uuid;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ContractException(500, 'Unable to store the contract document.');
        }
        $key  = $uuid . '/' . $which . '-' . bin2hex(random_bytes(12)) . '.pdf';
        $path = $this->baseDir . '/' . $key;
        $tmp  = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $bytes) === false) {
            throw new ContractException(500, 'Unable to write the contract document.');
        }
        @chmod($tmp, 0600);
        rename($tmp, $path);
        @chmod($path, 0600);

        return $key;
    }
}
