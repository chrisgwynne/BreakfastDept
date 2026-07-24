<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders receipt HTML to a real PDF binary with Dompdf — the same pure-PHP
 * engine used for proposals, contracts and invoices. Remote fetches + inline PHP
 * disabled; output validated to start with %PDF.
 */
final class ReceiptPdfRenderer
{
    public const RENDERER = 'dompdf';

    public function available(): bool
    {
        return class_exists(Dompdf::class);
    }

    public function render(string $html): string
    {
        if (!$this->available()) {
            throw new PaymentException(503, 'The PDF engine is unavailable.');
        }

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
            throw new PaymentException(500, 'The receipt PDF could not be generated.');
        }

        return $out;
    }
}
