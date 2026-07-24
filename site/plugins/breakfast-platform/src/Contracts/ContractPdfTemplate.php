<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

/**
 * Renders a frozen contract snapshot to print-ready HTML for Dompdf. Produces
 * the unsigned contract body, and — when signatures are supplied — appends a
 * signature certificate page (signer names, roles, timestamps, method, document
 * hash and version) so the signed PDF is a self-contained legal record.
 */
final class ContractPdfTemplate
{
    private const INK = '#1c1a17';
    private const MUTED = '#6b6459';
    private const LINE = '#d9d2c2';
    private const BUTTER = '#fdc800';
    private const PURPLE = '#5b3df5';

    /**
     * @param array<string,mixed> $s snapshot
     * @param list<array<string,mixed>> $signatures when non-empty, appends the certificate
     */
    public function html(array $s, bool $draft = false, array $signatures = []): string
    {
        $e    = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rich = fn ($v): string => nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        $money = function ($p) use ($s): string {
            $sym = ($s['currency'] ?? 'GBP') === 'GBP' ? '£' : '';
            return $sym . number_format(((int) $p) / 100, 2);
        };
        $ink = self::INK;
        $muted = self::MUTED;
        $line = self::LINE;
        $butter = self::BUTTER;
        $purple = self::PURPLE;

        $sections = is_array($s['sections'] ?? null) ? $s['sections'] : [];
        $parties  = is_array($s['parties'] ?? null) ? $s['parties'] : [];

        $body = '';
        $n = 1;
        foreach ($sections as $sec) {
            $body .= '<h2 style="font-size:13px;margin:16px 0 5px;color:' . $ink . ';">' . $n++ . '. ' . $e($sec['heading'] ?? '') . '</h2>'
                . '<div style="font-size:11px;color:#3a352d;line-height:1.55;">' . $rich($sec['body'] ?? '') . '</div>';
        }

        // Signature blocks (unsigned: lines to sign; signed: rendered from evidence).
        $signedBy = [];
        foreach ($signatures as $sig) {
            $signedBy[(string) ($sig['signer_role'] ?? '')] = $sig;
        }
        $sigBlocks = '';
        foreach ($parties as $party) {
            $role = (string) ($party['role'] ?? 'client');
            $label = $role === 'breakfast' ? 'For the Supplier' : 'For the Client';
            $sig = $signedBy[$role] ?? null;
            $sigBlocks .= '<td style="width:50%;vertical-align:top;padding:14px 10px 0;">'
                . '<div style="font-size:10px;color:' . $muted . ';text-transform:uppercase;">' . $e($label) . '</div>';
            if ($sig !== null) {
                $sigBlocks .= '<div style="font-family:cursive;font-size:20px;margin:6px 0 2px;color:' . $ink . ';">' . $e($sig['signer_name'] ?? '') . '</div>'
                    . '<div style="font-size:10px;color:' . $muted . ';">Signed ' . $e(self::when($sig['signed_at'] ?? '')) . '</div>';
            } else {
                $sigBlocks .= '<div style="border-bottom:1px solid ' . $ink . ';height:34px;margin:6px 0 2px;"></div>'
                    . '<div style="font-size:10px;color:' . $muted . ';">' . $e($party['name'] ?? '') . '</div>';
            }
            $sigBlocks .= '</td>';
        }

        $watermark = $draft
            ? '<div style="position:fixed;top:44%;left:0;width:100%;text-align:center;color:rgba(91,61,245,0.08);font-size:120px;font-weight:bold;">DRAFT</div>'
            : '';

        $certificate = '';
        if ($signatures !== []) {
            $rows = '';
            foreach ($signatures as $sig) {
                $rows .= '<tr>'
                    . '<td style="padding:6px;border-bottom:1px solid ' . $line . ';">' . $e($sig['signer_name'] ?? '') . '</td>'
                    . '<td style="padding:6px;border-bottom:1px solid ' . $line . ';">' . $e(ucfirst((string) ($sig['signer_role'] ?? ''))) . '</td>'
                    . '<td style="padding:6px;border-bottom:1px solid ' . $line . ';">' . $e($sig['signer_email'] ?? '') . '</td>'
                    . '<td style="padding:6px;border-bottom:1px solid ' . $line . ';">' . $e(self::when($sig['signed_at'] ?? '')) . '</td>'
                    . '<td style="padding:6px;border-bottom:1px solid ' . $line . ';">' . $e($sig['method'] ?? 'typed') . '</td>'
                    . '</tr>';
            }
            $hash = (string) ($signatures[0]['document_hash'] ?? '');
            $certificate = '<div style="page-break-before:always;"></div>'
                . '<h2 style="font-size:14px;color:' . $purple . ';">Signature certificate</h2>'
                . '<p style="font-size:11px;color:' . $muted . ';">This certificate records the electronic signatures applied to contract '
                . $e($s['number'] ?? '') . ' (version ' . (int) ($s['snapshot_version'] ?? 1) . ').</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:11px;margin-top:8px;">'
                . '<tr><th style="text-align:left;padding:6px;border-bottom:2px solid ' . $ink . ';">Name</th>'
                . '<th style="text-align:left;padding:6px;border-bottom:2px solid ' . $ink . ';">Role</th>'
                . '<th style="text-align:left;padding:6px;border-bottom:2px solid ' . $ink . ';">Email</th>'
                . '<th style="text-align:left;padding:6px;border-bottom:2px solid ' . $ink . ';">Signed</th>'
                . '<th style="text-align:left;padding:6px;border-bottom:2px solid ' . $ink . ';">Method</th></tr>'
                . $rows . '</table>'
                . '<p style="font-size:9px;color:' . $muted . ';margin-top:10px;word-break:break-all;">Document SHA-256: ' . $e($hash) . '</p>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . '@page { margin: 110px 48px 80px; }'
            . 'body { font-family: "DejaVu Sans", sans-serif; color: ' . $ink . '; font-size: 11px; }'
            . '.head { position: fixed; top: -80px; left: 0; right: 0; }'
            . '.foot { position: fixed; bottom: -55px; left: 0; right: 0; font-size: 9px; color: ' . $muted . '; }'
            . '.pnum:after { content: counter(page) " / " counter(pages); }'
            . '</style></head><body>'
            . $watermark
            . '<div class="head"><table style="width:100%;"><tr>'
            . '<td><span style="display:inline-block;width:16px;height:16px;background:' . $butter . ';border-radius:3px;"></span> <strong>' . $e($s['seller_name'] ?? 'Breakfast') . '</strong></td>'
            . '<td style="text-align:right;"><span style="color:' . $purple . ';letter-spacing:1px;">CONTRACT</span> <strong>' . $e($s['number'] ?? '') . '</strong></td>'
            . '</tr></table></div>'
            . '<div class="foot"><table style="width:100%;"><tr><td>' . $e($s['title'] ?? '') . '</td><td style="text-align:right;" class="pnum"></td></tr></table></div>'
            . '<h1 style="font-size:20px;margin:0 0 4px;">' . $e($s['title'] ?? 'Contract') . '</h1>'
            . '<div style="font-size:11px;color:' . $muted . ';">Between ' . $e($s['seller_name'] ?? 'Breakfast') . ' and ' . $e($s['client_name'] ?? '') . '</div>'
            . ($s['effective_date'] ? '<div style="font-size:10px;color:' . $muted . ';">Effective ' . $e($s['effective_date']) . '</div>' : '')
            . $body
            . '<div style="margin-top:18px;font-size:11px;color:' . $muted . ';">' . $e($s['signature_wording'] ?? '') . '</div>'
            . '<table style="width:100%;margin-top:8px;"><tr>' . $sigBlocks . '</tr></table>'
            . $certificate
            . '</body></html>';
    }

    private static function when(mixed $d): string
    {
        if (!$d) {
            return '—';
        }
        $t = strtotime((string) $d);

        return $t ? date('j M Y, H:i', $t) . ' UTC' : (string) $d;
    }
}
