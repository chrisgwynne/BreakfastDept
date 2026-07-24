<?php

declare(strict_types=1);

namespace Breakfast\Platform\Proposals;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a proposal snapshot to a real PDF binary with Dompdf — the same
 * pure-PHP engine used for invoices (the only one viable on the FTP shared
 * host). Remote fetches and inline PHP are disabled; output is validated.
 */
final class ProposalPdfRenderer
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
            throw new ProposalException(503, 'The PDF engine is unavailable.');
        }
        $html = (new ProposalPdfTemplate())->html($snapshot, $draft);

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
            throw new ProposalException(500, 'The proposal PDF could not be generated.');
        }

        return $out;
    }
}
