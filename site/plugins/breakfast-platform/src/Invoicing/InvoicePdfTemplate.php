<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

/**
 * Renders an immutable invoice snapshot to print-ready HTML for the PDF engine.
 *
 * Dompdf targets CSS 2.1, so the layout is table-based (no flexbox/grid), uses
 * light backgrounds that stay legible in black and white, and relies on @page
 * for A4 margins + page numbers. All monetary values arrive as integer pence and
 * quantities as thousandths; nothing here reads live data — only the snapshot.
 */
final class InvoicePdfTemplate
{
    private const INK = '#1c1a17';
    private const MUTED = '#6b6459';
    private const LINE = '#d9d2c2';
    private const BUTTER = '#fdc800';
    private const PURPLE = '#5b3df5';

    /**
     * @param array<string,mixed> $s snapshot
     */
    public function html(array $s, bool $draft = false): string
    {
        $currency = (string) ($s['currency'] ?? 'GBP');
        $sym = $currency === 'GBP' ? '£' : '';
        $money = fn ($p): string => $sym . number_format(((int) $p) / 100, 2);
        $qty = fn ($q): string => rtrim(rtrim(number_format(((int) $q) / 1000, 3), '0'), '.');
        $e = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $date = function ($d): string {
            if (!$d) {
                return '—';
            }
            $t = strtotime((string) $d);
            return $t ? date('j F Y', $t) : (string) $d;
        };

        $seller  = is_array($s['seller'] ?? null) ? $s['seller'] : [];
        $billTo  = is_array($s['bill_to'] ?? null) ? $s['bill_to'] : [];
        $items   = is_array($s['items'] ?? null) ? $s['items'] : [];
        $vatReg  = !empty($s['vat_registered']);
        $due     = (int) ($s['amount_due'] ?? ((int) ($s['total'] ?? 0) - (int) ($s['amount_paid'] ?? 0)));

        $ink = self::INK;
        $muted = self::MUTED;
        $line = self::LINE;
        $butter = self::BUTTER;
        $purple = self::PURPLE;

        $rows = '';
        foreach ($items as $it) {
            $rows .= '<tr>'
                . '<td style="padding:8px 6px;border-bottom:1px solid ' . $line . ';">' . $e($it['description'] ?? '') . '</td>'
                . '<td style="padding:8px 6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . $e($qty($it['quantity'] ?? 0)) . '</td>'
                . '<td style="padding:8px 6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . $e($money($it['unit_price'] ?? 0)) . '</td>'
                . ($vatReg ? '<td style="padding:8px 6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . number_format(((int) ($it['tax_rate'] ?? 0)) / 100, 0) . '%</td>' : '')
                . '<td style="padding:8px 6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . $e($money($it['line_total'] ?? 0)) . '</td>'
                . '</tr>';
        }
        if ($items === []) {
            $rows = '<tr><td colspan="5" style="padding:8px 6px;color:' . $muted . ';">No line items.</td></tr>';
        }

        $watermark = $draft
            ? '<div style="position:fixed;top:44%;left:0;width:100%;text-align:center;color:rgba(91,61,245,0.10);font-size:120px;font-weight:bold;letter-spacing:8px;">DRAFT</div>'
            : '';

        $sellerLines = trim((string) ($seller['name'] ?? ''));
        $sellerMeta = [];
        if (!empty($seller['address'])) {
            $sellerMeta[] = nl2br($e($seller['address']));
        }
        if (!empty($seller['email'])) {
            $sellerMeta[] = $e($seller['email']);
        }
        if (!empty($seller['phone'])) {
            $sellerMeta[] = $e($seller['phone']);
        }
        if (!empty($seller['company_number'])) {
            $sellerMeta[] = 'Company no. ' . $e($seller['company_number']);
        }
        if ($vatReg && !empty($seller['vat_number'])) {
            $sellerMeta[] = 'VAT ' . $e($seller['vat_number']);
        }

        $statusLabel = ucfirst((string) ($s['status'] ?? 'issued'));

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . '@page { margin: 26mm 18mm 22mm 18mm; }'
            . 'body { font-family: DejaVu Sans, sans-serif; color: ' . $ink . '; font-size: 11px; line-height: 1.5; }'
            . '.muted { color: ' . $muted . '; }'
            . 'h1 { font-size: 20px; margin: 0; }'
            . 'table { width: 100%; border-collapse: collapse; }'
            . '.foot { position: fixed; bottom: -12mm; left: 0; width: 100%; text-align: center; color: ' . $muted . '; font-size: 9px; }'
            . '.pnum:after { content: counter(page) " / " counter(pages); }'
            . '</style></head><body>'
            . $watermark
            . '<div class="foot">' . $e($seller['name'] ?? 'Breakfast') . ' · Invoice ' . $e($s['number'] ?? '') . ' · Page <span class="pnum"></span></div>'

            // Header
            . '<table style="margin-bottom:18px;"><tr>'
            . '<td style="vertical-align:top;">'
            . '<table><tr>'
            . '<td style="width:30px;vertical-align:middle;"><div style="width:22px;height:22px;border:3px solid ' . $ink . ';background:' . $butter . ';">&nbsp;</div></td>'
            . '<td style="vertical-align:middle;"><h1>' . $e($sellerLines ?: 'Breakfast') . '</h1></td>'
            . '</tr></table></td>'
            . '<td style="vertical-align:top;text-align:right;">'
            . '<div style="font-size:22px;letter-spacing:1px;">INVOICE</div>'
            . '<div style="font-weight:bold;">' . $e($s['number'] ?? 'Draft') . '</div>'
            . '<div style="display:inline-block;margin-top:4px;padding:2px 8px;background:#efe9db;color:' . $muted . ';font-size:9px;text-transform:uppercase;letter-spacing:1px;">' . $e($draft ? 'Draft' : $statusLabel) . '</div>'
            . '</td></tr></table>'

            // Parties
            . '<table style="margin-bottom:16px;"><tr>'
            . '<td style="width:50%;vertical-align:top;">'
            . '<div class="muted" style="text-transform:uppercase;letter-spacing:1px;font-size:9px;margin-bottom:3px;">From</div>'
            . '<div>' . implode('<br>', array_filter([$e($seller['name'] ?? '')] + $sellerMeta)) . '</div>'
            . '</td>'
            . '<td style="width:50%;vertical-align:top;">'
            . '<div class="muted" style="text-transform:uppercase;letter-spacing:1px;font-size:9px;margin-bottom:3px;">Bill to</div>'
            . '<div><strong>' . $e($billTo['name'] ?? '') . '</strong><br>' . nl2br($e($billTo['address'] ?? '')) . ($billTo['email'] ?? '' ? '<br>' . $e($billTo['email']) : '') . '</div>'
            . '</td></tr></table>'

            // Meta
            . '<table style="margin-bottom:14px;"><tr>'
            . '<td style="width:25%;"><div class="muted" style="font-size:9px;text-transform:uppercase;">Issued</div>' . $e($date($s['issue_date'] ?? null)) . '</td>'
            . '<td style="width:25%;"><div class="muted" style="font-size:9px;text-transform:uppercase;">Due</div>' . $e($date($s['due_date'] ?? null)) . '</td>'
            . ($s['project'] ?? '' ? '<td style="width:50%;"><div class="muted" style="font-size:9px;text-transform:uppercase;">Project</div>' . $e($s['project']) . '</td>' : '<td></td>')
            . '</tr></table>'

            // Items
            . '<table style="margin-bottom:10px;"><thead><tr style="border-bottom:2px solid ' . $ink . ';">'
            . '<th style="text-align:left;padding:6px;font-size:9px;text-transform:uppercase;color:' . $muted . ';">Description</th>'
            . '<th style="text-align:right;padding:6px;font-size:9px;text-transform:uppercase;color:' . $muted . ';">Qty</th>'
            . '<th style="text-align:right;padding:6px;font-size:9px;text-transform:uppercase;color:' . $muted . ';">Unit</th>'
            . ($vatReg ? '<th style="text-align:right;padding:6px;font-size:9px;text-transform:uppercase;color:' . $muted . ';">VAT</th>' : '')
            . '<th style="text-align:right;padding:6px;font-size:9px;text-transform:uppercase;color:' . $muted . ';">Amount</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'

            // Totals
            . '<table><tr><td style="width:58%;">&nbsp;</td><td style="width:42%;">'
            . '<table>'
            . '<tr><td style="padding:3px 6px;">Subtotal</td><td style="padding:3px 6px;text-align:right;">' . $e($money($s['subtotal'] ?? 0)) . '</td></tr>'
            . ((int) ($s['discount_total'] ?? 0) > 0 ? '<tr><td style="padding:3px 6px;">Discount</td><td style="padding:3px 6px;text-align:right;">−' . $e($money($s['discount_total'])) . '</td></tr>' : '')
            . ($vatReg ? '<tr><td style="padding:3px 6px;">VAT</td><td style="padding:3px 6px;text-align:right;">' . $e($money($s['tax_total'] ?? 0)) . '</td></tr>' : '')
            . '<tr style="border-top:2px solid ' . $ink . ';"><td style="padding:6px;font-weight:bold;">Total</td><td style="padding:6px;text-align:right;font-weight:bold;">' . $e($money($s['total'] ?? 0)) . '</td></tr>'
            . ((int) ($s['amount_paid'] ?? 0) > 0 ? '<tr><td style="padding:3px 6px;">Paid</td><td style="padding:3px 6px;text-align:right;">−' . $e($money($s['amount_paid'])) . '</td></tr>' : '')
            . '<tr style="background:' . $butter . ';"><td style="padding:6px;font-weight:bold;">Amount due</td><td style="padding:6px;text-align:right;font-weight:bold;">' . $e($money($due)) . '</td></tr>'
            . '</table></td></tr></table>'

            // Footer blocks
            . ((!empty($seller['payment_details']) || !empty($s['notes']) || !empty($s['terms']))
                ? '<table style="margin-top:22px;border-top:1px solid ' . $line . ';"><tr>'
                    . (!empty($seller['payment_details']) ? '<td style="width:50%;vertical-align:top;padding-top:10px;"><div class="muted" style="font-size:9px;text-transform:uppercase;">Payment</div>' . nl2br($e($seller['payment_details'])) . '</td>' : '<td style="width:50%;"></td>')
                    . '<td style="width:50%;vertical-align:top;padding-top:10px;">'
                    . (!empty($s['notes']) ? '<div class="muted" style="font-size:9px;text-transform:uppercase;">Notes</div>' . nl2br($e($s['notes'])) : '')
                    . (!empty($s['terms']) ? '<div class="muted" style="font-size:9px;text-transform:uppercase;margin-top:8px;">Terms</div>' . nl2br($e($s['terms'])) : '')
                    . '</td></tr></table>'
                : '')
            . '</body></html>';
    }
}
