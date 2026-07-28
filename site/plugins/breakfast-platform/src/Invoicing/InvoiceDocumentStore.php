<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
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
        private readonly FileStore $store,
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

        $version = 1;
        foreach ($this->store->all('invoice_documents') as $doc) {
            if ((string) ($doc['invoice_uuid'] ?? '') === $invoiceUuid && (string) ($doc['kind'] ?? '') === $kind) {
                $version = max($version, (int) ($doc['version'] ?? 0) + 1);
            }
        }
        $uuid = Uuid::v4();
        $this->store->put('invoice_documents', [
            'uuid'             => $uuid,
            'invoice_uuid'     => $invoiceUuid,
            'version'          => $version,
            'kind'             => $kind,
            'storage_key'      => $storageKey,
            'filename'         => $filename,
            'mime'             => 'application/pdf',
            'byte_size'        => strlen($bytes),
            'sha256'           => hash('sha256', $bytes),
            'renderer'         => InvoicePdfRenderer::RENDERER,
            'renderer_version' => $rendererVersion,
            'state'            => 'generated',
            'snapshot_version' => $snapshotVersion,
            'created_by'       => $actor,
            'created_at'       => Clock::nowIso(),
        ]);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->store->find('invoice_documents', $uuid);
    }

    /**
     * The document ordinary users download: the ORIGINAL issued file (lowest version).
     *
     * @return array<string,mixed>|null
     */
    public function currentIssued(string $invoiceUuid): ?array
    {
        $docs = array_filter($this->store->all('invoice_documents'), static fn (array $d): bool => (string) ($d['invoice_uuid'] ?? '') === $invoiceUuid
            && (string) ($d['kind'] ?? '') === 'issued'
            && (string) ($d['state'] ?? '') === 'generated');
        usort($docs, static fn ($a, $b) => (int) ($a['version'] ?? 0) <=> (int) ($b['version'] ?? 0));

        return $docs[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function currentDraft(string $invoiceUuid): ?array
    {
        $docs = array_filter($this->store->all('invoice_documents'), static fn (array $d): bool => (string) ($d['invoice_uuid'] ?? '') === $invoiceUuid
            && (string) ($d['kind'] ?? '') === 'draft');
        usort($docs, static fn ($a, $b) => (int) ($b['version'] ?? 0) <=> (int) ($a['version'] ?? 0));

        return $docs[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function versions(string $invoiceUuid): array
    {
        $docs = array_values(array_filter($this->store->all('invoice_documents'), static fn (array $d): bool => (string) ($d['invoice_uuid'] ?? '') === $invoiceUuid));
        usort($docs, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $docs;
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
        foreach ($this->store->all('invoice_documents') as $doc) {
            if ((string) ($doc['invoice_uuid'] ?? '') !== $invoiceUuid || (string) ($doc['kind'] ?? '') !== 'draft') {
                continue;
            }
            $path = $this->baseDir . '/' . (string) $doc['storage_key'];
            $real = realpath($path);
            $baseReal = realpath($this->baseDir);
            if ($real !== false && $baseReal !== false && strncmp($real, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) === 0) {
                @unlink($real);
            }
            $this->store->delete('invoice_documents', (string) ($doc['uuid'] ?? ''));
        }
    }

    private function dirFor(string $invoiceUuid): string
    {
        return $this->baseDir . '/' . $invoiceUuid;
    }
}
