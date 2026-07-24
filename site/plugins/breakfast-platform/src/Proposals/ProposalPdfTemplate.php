<?php

declare(strict_types=1);

namespace Breakfast\Platform\Proposals;

/**
 * Renders an immutable proposal snapshot to print-ready HTML for Dompdf.
 *
 * Dompdf targets CSS 2.1, so the layout is table-based and uses light
 * backgrounds legible in black and white, with @page A4 margins + page numbers.
 * Nothing here reads live data — only the frozen snapshot.
 */
final class ProposalPdfTemplate
{
    private const INK = '#1c1a17';
    private const MUTED = '#6b6459';
    private const LINE = '#d9d2c2';
    private const BUTTER = '#fdc800';
    private const PURPLE = '#5b3df5';

    /** @param array<string,mixed> $s snapshot */
    public function html(array $s, bool $draft = false): string
    {
        $currency = (string) ($s['currency'] ?? 'GBP');
        $sym   = $currency === 'GBP' ? '£' : '';
        $money = fn ($p): string => $sym . number_format(((int) $p) / 100, 2);
        $qty   = fn ($q): string => rtrim(rtrim(number_format(((int) $q) / 1000, 3), '0'), '.');
        $e     = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rich  = fn ($v): string => nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));

        $ink = self::INK;
        $muted = self::MUTED;
        $line = self::LINE;
        $butter = self::BUTTER;
        $purple = self::PURPLE;

        $seller   = is_array($s['seller'] ?? null) ? $s['seller'] : [];
        $client   = is_array($s['client'] ?? null) ? $s['client'] : [];
        $sections = is_array($s['sections'] ?? null) ? $s['sections'] : [];
        $items    = is_array($s['items'] ?? null) ? $s['items'] : [];
        $vatReg   = !empty($s['vat_registered']);

        // Narrative sections in reading order.
        $labels = [
            'introduction' => 'Introduction', 'client_problem' => 'The challenge',
            'recommended_solution' => 'Our recommendation', 'scope' => 'Scope',
            'deliverables' => 'Deliverables', 'exclusions' => 'Exclusions',
            'assumptions' => 'Assumptions', 'timeline' => 'Timeline',
            'payment_schedule' => 'Payment schedule', 'terms' => 'Terms',
        ];
        $narrative = '';
        foreach ($labels as $key => $label) {
            $val = trim((string) ($sections[$key] ?? ''));
            if ($val !== '') {
                $narrative .= '<h2 style="font-size:13px;margin:18px 0 6px;color:' . $ink . ';">' . $e($label) . '</h2>'
                    . '<div style="font-size:11px;color:' . $muted . ';line-height:1.5;">' . $rich($val) . '</div>';
            }
        }

        // Items grouped by kind.
        $group = function (string $kind, string $heading) use ($items, $e, $qty, $money, $vatReg, $line, $ink): string {
            $rows = '';
            foreach ($items as $it) {
                if ((string) ($it['kind'] ?? 'fixed') !== $kind) {
                    continue;
                }
                $suffix = $kind === 'recurring' && ($it['recurrence'] ?? '') !== '' ? ' / ' . $e($it['recurrence']) : '';
                $sel = $kind === 'optional' ? ((int) ($it['is_selected'] ?? 0) === 1 ? ' ✓' : '') : '';
                $rows .= '<tr>'
                    . '<td style="padding:7px 6px;border-bottom:1px solid ' . $line . ';">' . $e($it['description'] ?? '') . $sel . '</td>'
                    . '<td style="padding:7px 6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . $e($qty($it['quantity'] ?? 0)) . '</td>'
                    . '<td style="padding:7px 6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . $e($money($it['unit_price'] ?? 0)) . '</td>'
                    . ($vatReg ? '<td style="padding:7px 6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . number_format(((int) ($it['tax_rate'] ?? 0)) / 100, 0) . '%</td>' : '')
                    . '<td style="padding:7px 6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . $e($money($it['line_total'] ?? 0)) . $suffix . '</td>'
                    . '</tr>';
            }
            if ($rows === '') {
                return '';
            }
            $cols = $vatReg ? 5 : 4;

            return '<tr><td colspan="' . $cols . '" style="padding:12px 6px 4px;font-size:11px;font-weight:bold;color:' . $ink . ';">' . $e($heading) . '</td></tr>' . $rows;
        };

        $itemsBody = $group('fixed', 'Included') . $group('optional', 'Optional extras') . $group('recurring', 'Ongoing (per period)');
        $headCols = '<th style="text-align:left;padding:6px;border-bottom:2px solid ' . $ink . ';font-size:10px;color:' . $muted . ';">DESCRIPTION</th>'
            . '<th style="text-align:right;padding:6px;border-bottom:2px solid ' . $ink . ';font-size:10px;color:' . $muted . ';">QTY</th>'
            . '<th style="text-align:right;padding:6px;border-bottom:2px solid ' . $ink . ';font-size:10px;color:' . $muted . ';">UNIT</th>'
            . ($vatReg ? '<th style="text-align:right;padding:6px;border-bottom:2px solid ' . $ink . ';font-size:10px;color:' . $muted . ';">VAT</th>' : '')
            . '<th style="text-align:right;padding:6px;border-bottom:2px solid ' . $ink . ';font-size:10px;color:' . $muted . ';">AMOUNT</th>';

        $deposit = (int) ($s['deposit_amount'] ?? 0);
        $recurring = (int) ($s['recurring_total'] ?? 0);
        $watermark = $draft
            ? '<div style="position:fixed;top:44%;left:0;width:100%;text-align:center;color:rgba(91,61,245,0.08);font-size:120px;font-weight:bold;">DRAFT</div>'
            : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . '@page { margin: 120px 48px 90px; }'
            . 'body { font-family: "DejaVu Sans", sans-serif; color: ' . $ink . '; font-size: 11px; }'
            . '.head { position: fixed; top: -90px; left: 0; right: 0; }'
            . '.foot { position: fixed; bottom: -60px; left: 0; right: 0; font-size: 9px; color: ' . $muted . '; }'
            . '.pnum:after { content: counter(page) " / " counter(pages); }'
            . '</style></head><body>'
            . $watermark
            . '<div class="head"><table style="width:100%;"><tr>'
            . '<td style="vertical-align:top;"><span style="display:inline-block;width:18px;height:18px;background:' . $butter . ';border-radius:3px;"></span> '
            . '<strong style="font-size:16px;">' . $e($seller['name'] ?? 'Breakfast') . '</strong></td>'
            . '<td style="text-align:right;vertical-align:top;"><div style="font-size:18px;letter-spacing:1px;color:' . $purple . ';">PROPOSAL</div>'
            . '<div style="font-weight:bold;">' . $e($s['number'] ?? '') . '</div></td>'
            . '</tr></table></div>'
            . '<div class="foot"><table style="width:100%;"><tr><td>' . $e($seller['email'] ?? '') . '</td>'
            . '<td style="text-align:right;" class="pnum"></td></tr></table></div>'

            . '<h1 style="font-size:20px;margin:0 0 4px;">' . $e($s['title'] ?? 'Proposal') . '</h1>'
            . '<div style="font-size:11px;color:' . $muted . ';margin-bottom:6px;">Prepared for ' . $e($client['name'] ?? '') . '</div>'
            . ($s['expiry_date'] ? '<div style="font-size:10px;color:' . $muted . ';">Valid until ' . $e($s['expiry_date']) . '</div>' : '')

            . $narrative

            . '<h2 style="font-size:13px;margin:20px 0 6px;">Investment</h2>'
            . '<table style="width:100%;border-collapse:collapse;"><tr>' . $headCols . '</tr>' . $itemsBody . '</table>'

            . '<table style="width:45%;margin-left:55%;margin-top:12px;border-collapse:collapse;">'
            . '<tr><td style="padding:4px 6px;color:' . $muted . ';">Subtotal</td><td style="padding:4px 6px;text-align:right;">' . $e($money($s['subtotal'] ?? 0)) . '</td></tr>'
            . ($vatReg ? '<tr><td style="padding:4px 6px;color:' . $muted . ';">VAT</td><td style="padding:4px 6px;text-align:right;">' . $e($money($s['tax_total'] ?? 0)) . '</td></tr>' : '')
            . '<tr><td style="padding:6px;border-top:2px solid ' . $ink . ';font-weight:bold;">Total</td><td style="padding:6px;border-top:2px solid ' . $ink . ';text-align:right;font-weight:bold;">' . $e($money($s['total'] ?? 0)) . '</td></tr>'
            . ($deposit > 0 ? '<tr><td style="padding:6px;background:' . $butter . ';font-weight:bold;">Deposit to start</td><td style="padding:6px;background:' . $butter . ';text-align:right;font-weight:bold;">' . $e($money($deposit)) . '</td></tr>' : '')
            . ($recurring > 0 ? '<tr><td style="padding:4px 6px;color:' . $muted . ';">Then per period</td><td style="padding:4px 6px;text-align:right;">' . $e($money($recurring)) . '</td></tr>' : '')
            . '</table>'
            . '</body></html>';
    }
}
