<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Secure storage + metadata for generated invoice PDFs.
 *
 * Files live OUTSIDE the public webroot under an opaque random key (never a
 * predictable public path); each is served only through the authenticated
 * download route. Every stored document records its hash, size, renderer and
 * version so integrity can be verified and issued originals preserved. Draft
 * documents are replaceable; issued documents are immutable and kept.
 */
final class InvoiceDocumentStore
{
    public function __construct(
        private readonly Database $db,
        private readonly string $baseDir,
    ) {
    }

    /**
     * Persist a PDF binary and record its metadata. Draft docs replace any prior
     * draft; issued docs are appended as an immutable new version.
     *
     * @return array<string,mixed>
     */
    public function store(string $invoiceUuid, string $bytes, string $filename, string $kind, string $actor, int $snapshotVersion, string $rendererVersion): array
    {
        $dir = $this->dirFor($invoiceUuid);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new InvoiceException(500, 'Unable to store the invoice document.');
        }

        if ($kind === 'draft') {
            $this->deleteDrafts($invoiceUuid);
        }

        $storageKey = $invoiceUuid . '/' . bin2hex(random_bytes(16)) . '.pdf';
        $path = $this->baseDir . '/' . $storageKey;
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $bytes) === false) {
            throw new InvoiceException(500, 'Unable to write the invoice document.');
        }
        @chmod($tmp, 0600);
        rename($tmp, $path);
        @chmod($path, 0600);

        $version = 1 + (int) $this->db->scalar(
            'SELECT COALESCE(MAX(version), 0) FROM invoice_documents WHERE invoice_uuid = :i AND kind = :k',
            ['i' => $invoiceUuid, 'k' => $kind]
        );
        $uuid = Uuid::v4();
        $this->db->run(
            'INSERT INTO invoice_documents
                (uuid, invoice_uuid, version, kind, storage_key, filename, mime, byte_size, sha256,
                 renderer, renderer_version, state, snapshot_version, created_by, created_at)
             VALUES
                (:uuid, :inv, :ver, :kind, :key, :file, :mime, :size, :hash,
                 :renderer, :rver, \'generated\', :sver, :actor, :now)',
            [
                'uuid' => $uuid, 'inv' => $invoiceUuid, 'ver' => $version, 'kind' => $kind,
                'key' => $storageKey, 'file' => $filename, 'mime' => 'application/pdf',
                'size' => strlen($bytes), 'hash' => hash('sha256', $bytes),
                'renderer' => InvoicePdfRenderer::RENDERER, 'rver' => $rendererVersion,
                'sver' => $snapshotVersion, 'actor' => $actor, 'now' => Clock::nowIso(),
            ]
        );

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM invoice_documents WHERE uuid = :u', ['u' => $uuid]);
    }

    /**
     * The document ordinary users download: the ORIGINAL issued file (lowest version).
     *
     * @return array<string,mixed>|null
     */
    public function currentIssued(string $invoiceUuid): ?array
    {
        return $this->db->one(
            "SELECT * FROM invoice_documents WHERE invoice_uuid = :i AND kind = 'issued' AND state = 'generated' ORDER BY version ASC LIMIT 1",
            ['i' => $invoiceUuid]
        );
    }

    /** @return array<string,mixed>|null */
    public function currentDraft(string $invoiceUuid): ?array
    {
        return $this->db->one(
            "SELECT * FROM invoice_documents WHERE invoice_uuid = :i AND kind = 'draft' ORDER BY version DESC LIMIT 1",
            ['i' => $invoiceUuid]
        );
    }

    /** @return list<array<string,mixed>> */
    public function versions(string $invoiceUuid): array
    {
        return $this->db->all('SELECT * FROM invoice_documents WHERE invoice_uuid = :i ORDER BY created_at DESC', ['i' => $invoiceUuid]);
    }

    /**
     * Read a document's bytes with strict path containment.
     *
     * @param array<string,mixed> $doc
     */
    public function read(array $doc): string
    {
        $key = (string) ($doc['storage_key'] ?? '');
        $path = $this->baseDir . '/' . $key;
        $real = realpath($path);
        $baseReal = realpath($this->baseDir);
        if ($real === false || $baseReal === false || strncmp($real, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) !== 0) {
            throw new InvoiceException(404, 'Document not found.');
        }
        $bytes = file_get_contents($real);
        if ($bytes === false) {
            throw new InvoiceException(404, 'Document not found.');
        }

        return $bytes;
    }

    /** @param array<string,mixed> $doc */
    public function verify(array $doc): bool
    {
        try {
            return hash('sha256', $this->read($doc)) === (string) ($doc['sha256'] ?? '');
        } catch (\Throwable) {
            return false;
        }
    }

    private function deleteDrafts(string $invoiceUuid): void
    {
        foreach ($this->db->all("SELECT * FROM invoice_documents WHERE invoice_uuid = :i AND kind = 'draft'", ['i' => $invoiceUuid]) as $doc) {
            $path = $this->baseDir . '/' . (string) $doc['storage_key'];
            $real = realpath($path);
            $baseReal = realpath($this->baseDir);
            if ($real !== false && $baseReal !== false && strncmp($real, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) === 0) {
                @unlink($real);
            }
        }
        $this->db->run("DELETE FROM invoice_documents WHERE invoice_uuid = :i AND kind = 'draft'", ['i' => $invoiceUuid]);
    }

    private function dirFor(string $invoiceUuid): string
    {
        return $this->baseDir . '/' . $invoiceUuid;
    }
}
