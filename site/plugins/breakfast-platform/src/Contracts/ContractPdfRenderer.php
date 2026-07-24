<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a contract snapshot to a real PDF binary with Dompdf — the same
 * pure-PHP engine used for proposals and invoices. Remote fetches + inline PHP
 * disabled; output validated to start with %PDF.
 */
final class ContractPdfRenderer
{
    public const RENDERER = 'dompdf';

    public function available(): bool
    {
        return class_exists(Dompdf::class);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param list<array<string,mixed>> $signatures
     */
    public function render(array $snapshot, bool $draft = false, array $signatures = []): string
    {
        if (!$this->available()) {
            throw new ContractException(503, 'The PDF engine is unavailable.');
        }
        $html = (new ContractPdfTemplate())->html($snapshot, $draft, $signatures);

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
            throw new ContractException(500, 'The contract PDF could not be generated.');
        }

        return $out;
    }
}
