<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Turns an immutable invoice snapshot into a real PDF binary using Dompdf — a
 * pure-PHP engine (no shell/binary), the only renderer viable on the FTP
 * shared-hosting production target. Remote content is disabled so a malicious
 * invoice field can never trigger an outbound fetch.
 */
final class InvoicePdfRenderer
{
    public const RENDERER = 'dompdf';

    public function available(): bool
    {
        return class_exists(Dompdf::class);
    }

    public function version(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                return (string) \Composer\InstalledVersions::getPrettyVersion('dompdf/dompdf');
            } catch (\Throwable) {
                // fall through
            }
        }

        return 'dompdf';
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public function render(array $snapshot, bool $draft = false): string
    {
        if (!$this->available()) {
            throw new InvoiceException(503, 'The PDF engine is unavailable.');
        }
        $html = (new InvoicePdfTemplate())->html($snapshot, $draft);

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
            throw new InvoiceException(500, 'The invoice PDF could not be generated.');
        }

        return $out;
    }
}
