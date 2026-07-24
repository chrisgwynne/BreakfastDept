<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

/**
 * Renders a payment receipt to print-ready HTML for Dompdf. A receipt is issued
 * only after a payment has been VERIFIED and reconciled against its invoice — it
 * confirms money actually received, showing the receipt number, the amount, the
 * method (card via Stripe), the linked invoice and any remaining balance.
 */
final class ReceiptPdfTemplate
{
    private const INK = '#1c1a17';
    private const MUTED = '#6b6459';
    private const LINE = '#d9d2c2';
    private const BUTTER = '#fdc800';
    private const GREEN = '#1f8a52';

    /**
     * @param array<string,mixed> $r
     */
    public function html(array $r): string
    {
        $e = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $currency = strtoupper((string) ($r['currency'] ?? 'GBP'));
        $money = function ($pence) use ($currency): string {
            $sym = $currency === 'GBP' ? '£' : '';

            return $sym . number_format(((int) $pence) / 100, 2);
        };
        $ink = self::INK;
        $muted = self::MUTED;
        $line = self::LINE;
        $butter = self::BUTTER;
        $green = self::GREEN;

        $remaining = (int) ($r['amount_remaining'] ?? 0);
        $remainingRow = $remaining > 0
            ? '<tr><td style="padding:6px 0;color:' . $muted . ';">Balance remaining on invoice</td>'
                . '<td style="padding:6px 0;text-align:right;">' . $e($money($remaining)) . '</td></tr>'
            : '<tr><td style="padding:6px 0;color:' . $green . ';">Invoice paid in full</td><td></td></tr>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . '@page { margin: 90px 48px 70px; }'
            . 'body { font-family: "DejaVu Sans", sans-serif; color: ' . $ink . '; font-size: 12px; }'
            . '.head { position: fixed; top: -64px; left: 0; right: 0; }'
            . '.foot { position: fixed; bottom: -46px; left: 0; right: 0; font-size: 9px; color: ' . $muted . '; }'
            . '</style></head><body>'
            . '<div class="head"><table style="width:100%;"><tr>'
            . '<td><span style="display:inline-block;width:16px;height:16px;background:' . $butter . ';border-radius:3px;"></span> <strong>' . $e($r['seller'] ?? 'Breakfast') . '</strong></td>'
            . '<td style="text-align:right;"><span style="color:' . $green . ';letter-spacing:1px;">RECEIPT</span> <strong>' . $e($r['number'] ?? '') . '</strong></td>'
            . '</tr></table></div>'
            . '<div class="foot">This receipt confirms payment received. Retain for your records.</div>'
            . '<h1 style="font-size:22px;margin:0 0 2px;">Payment received</h1>'
            . '<div style="font-size:12px;color:' . $muted . ';">Receipt ' . $e($r['number'] ?? '') . ' &middot; ' . $e(self::when($r['paid_at'] ?? '')) . '</div>'
            . '<div style="margin:22px 0;padding:18px 20px;border:1px solid ' . $line . ';border-radius:8px;background:#fbf9f4;">'
            . '<div style="font-size:11px;color:' . $muted . ';text-transform:uppercase;letter-spacing:1px;">Amount paid</div>'
            . '<div style="font-size:32px;font-weight:bold;color:' . $green . ';">' . $e($money($r['amount'] ?? 0)) . '</div>'
            . '</div>'
            . '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
            . '<tr><td style="padding:6px 0;color:' . $muted . ';width:45%;">Billed to</td><td style="padding:6px 0;text-align:right;">' . $e($r['client'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:' . $muted . ';">Invoice</td><td style="padding:6px 0;text-align:right;">' . $e($r['invoice_number'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:' . $muted . ';">Payment method</td><td style="padding:6px 0;text-align:right;">' . $e($r['method'] ?? 'Card (Stripe)') . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:' . $muted . ';">Date received</td><td style="padding:6px 0;text-align:right;">' . $e(self::when($r['paid_at'] ?? '')) . '</td></tr>'
            . '<tr><td colspan="2"><div style="border-top:1px solid ' . $line . ';margin:6px 0;"></div></td></tr>'
            . $remainingRow
            . '</table>'
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
