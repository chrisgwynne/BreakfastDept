<?php

declare(strict_types=1);

namespace Breakfast\Platform\Proposals;

use Breakfast\Platform\Support\Database;

/**
 * Generates, stores and serves the real PDF for a proposal.
 *
 * The document is rendered from the proposal's frozen snapshot and written
 * OUTSIDE the webroot under an opaque random key at 0600; its sha256 is recorded
 * on the proposal so downloads can be integrity-checked. Because the snapshot is
 * immutable once sent, regeneration is byte-stable, so a single current document
 * per proposal is sufficient. A draft preview renders live data with a DRAFT
 * watermark and is never stored.
 */
final class ProposalDocumentService
{
    public function __construct(
        private readonly Proposals $proposals,
        private readonly ProposalPdfRenderer $renderer,
        private readonly Database $db,
        private readonly string $baseDir,
    ) {
    }

    /**
     * Render + store the PDF for a sent proposal from its frozen snapshot.
     *
     * @return array<string,mixed> the proposal detail
     */
    public function generate(string $uuid, string $actor): array
    {
        $proposal = $this->proposals->find($uuid);
        if ($proposal === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        $snapshot = $this->proposals->snapshot($uuid);
        if ($snapshot === null) {
            throw new ProposalException(409, 'Send the proposal before generating its PDF.');
        }

        try {
            $bytes = $this->renderer->render($snapshot, false);
            $key   = $this->store($uuid, $bytes);
        } catch (\Throwable $e) {
            $this->proposals->setDocumentStatus($uuid, 'failed');
            throw $e instanceof ProposalException ? $e : new ProposalException(500, 'The proposal PDF could not be generated.');
        }

        $this->db->run(
            'UPDATE proposals SET document_key = :k, document_hash = :h, document_bytes = :b, document_status = \'generated\' WHERE uuid = :u',
            ['k' => $key, 'h' => hash('sha256', $bytes), 'b' => strlen($bytes), 'u' => $uuid]
        );
        $this->proposals->logEvent($uuid, 'document_generated', 'PDF generated', $actor);

        return $this->proposals->find($uuid) ?? [];
    }

    /** A replaceable DRAFT-watermarked preview from live data (never stored). */
    public function draft(string $uuid): string
    {
        $proposal = $this->proposals->find($uuid);
        if ($proposal === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        $snapshot = $this->proposals->snapshot($uuid) ?? ProposalSnapshot::build($proposal, []);

        return $this->renderer->render($snapshot, true);
    }

    /**
     * Read the stored PDF, verifying its integrity.
     *
     * @return array{filename:string,bytes:string}
     */
    public function download(string $uuid): array
    {
        $proposal = $this->proposals->find($uuid);
        if ($proposal === null || (string) ($proposal['document_key'] ?? '') === '') {
            throw new ProposalException(404, 'No document available for this proposal.');
        }
        $path     = $this->baseDir . '/' . (string) $proposal['document_key'];
        $real     = realpath($path);
        $baseReal = realpath($this->baseDir);
        if ($real === false || $baseReal === false || strncmp($real, $baseReal . DIRECTORY_SEPARATOR, strlen($baseReal) + 1) !== 0) {
            throw new ProposalException(404, 'Document not found.');
        }
        $bytes = file_get_contents($real);
        if ($bytes === false || hash('sha256', $bytes) !== (string) $proposal['document_hash']) {
            throw new ProposalException(409, 'The stored document failed its integrity check.');
        }

        return ['filename' => 'BREAKFAST-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($proposal['number'] ?? 'proposal')) . '.pdf', 'bytes' => $bytes];
    }

    private function store(string $uuid, string $bytes): string
    {
        $dir = $this->baseDir . '/' . $uuid;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ProposalException(500, 'Unable to store the proposal document.');
        }
        $key  = $uuid . '/' . bin2hex(random_bytes(16)) . '.pdf';
        $path = $this->baseDir . '/' . $key;
        $tmp  = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $bytes) === false) {
            throw new ProposalException(500, 'Unable to write the proposal document.');
        }
        @chmod($tmp, 0600);
        rename($tmp, $path);
        @chmod($path, 0600);

        return $key;
    }
}
