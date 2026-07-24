<?php

declare(strict_types=1);

namespace Breakfast\Platform\ChangeRequests;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a change-request snapshot to a real PDF binary with Dompdf — the same
 * pure-PHP engine used for proposals/contracts/invoices/receipts. A DRAFT
 * watermark is applied to unsent drafts; output is validated to start with %PDF.
 */
final class ChangeRequestPdfRenderer
{
    public function available(): bool
    {
        return class_exists(Dompdf::class);
    }

    /** @param array<string,mixed> $snapshot */
    public function render(array $snapshot, bool $draft = false): string
    {
        if (!$this->available()) {
            throw new ChangeRequestException(503, 'The PDF engine is unavailable.');
        }
        $html = (new ChangeRequestPdfTemplate())->html($snapshot, $draft);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $out = $dompdf->output();
        if (!is_string($out) || strncmp($out, '%PDF', 4) !== 0) {
            throw new ChangeRequestException(500, 'The change-request PDF could not be generated.');
        }

        return $out;
    }
}
