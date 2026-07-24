<?php

declare(strict_types=1);

namespace Breakfast\Platform\ChangeRequests;

/** Renders a frozen change-request snapshot to print-ready HTML for Dompdf. */
final class ChangeRequestPdfTemplate
{
    private const INK = '#1c1a17';
    private const MUTED = '#6b6459';
    private const LINE = '#d9d2c2';
    private const BUTTER = '#fdc800';
    private const PURPLE = '#5b3df5';

    /** @param array<string,mixed> $s */
    public function html(array $s, bool $draft = false): string
    {
        $e = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
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

        $items = is_array($s['items'] ?? null) ? $s['items'] : [];
        $rows = '';
        foreach ($items as $it) {
            $rows .= '<tr>'
                . '<td style="padding:6px;border-bottom:1px solid ' . $line . ';">' . $e($it['description'] ?? '') . ($it['kind'] === 'optional' ? ' <em style="color:' . $muted . ';">(optional)</em>' : '') . '</td>'
                . '<td style="padding:6px;border-bottom:1px solid ' . $line . ';text-align:right;">' . $e($money($it['line_total'] ?? 0)) . '</td>'
                . '</tr>';
        }

        $watermark = $draft
            ? '<div style="position:fixed;top:44%;left:0;width:100%;text-align:center;color:rgba(91,61,245,0.08);font-size:120px;font-weight:bold;">DRAFT</div>'
            : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . '@page { margin: 96px 48px 70px; }'
            . 'body { font-family: "DejaVu Sans", sans-serif; color: ' . $ink . '; font-size: 12px; }'
            . '.head { position: fixed; top: -70px; left: 0; right: 0; }'
            . '.foot { position: fixed; bottom: -46px; left: 0; right: 0; font-size: 9px; color: ' . $muted . '; }'
            . '</style></head><body>'
            . $watermark
            . '<div class="head"><table style="width:100%;"><tr>'
            . '<td><span style="display:inline-block;width:16px;height:16px;background:' . $butter . ';border-radius:3px;"></span> <strong>' . $e($s['seller_name'] ?? 'Breakfast') . '</strong></td>'
            . '<td style="text-align:right;"><span style="color:' . $purple . ';letter-spacing:1px;">CHANGE REQUEST</span> <strong>' . $e($s['number'] ?? '') . '</strong></td>'
            . '</tr></table></div>'
            . '<div class="foot">' . $e($s['title'] ?? '') . '</div>'
            . '<h1 style="font-size:20px;margin:0 0 4px;">' . $e($s['title'] ?? 'Change request') . '</h1>'
            . '<div style="font-size:11px;color:' . $muted . ';">For project ' . $e($s['project_name'] ?? '') . ' &middot; ' . $e($s['client_name'] ?? '') . '</div>'
            . ($s['description'] ? '<h2 style="font-size:13px;margin:16px 0 4px;">What is changing</h2><div style="font-size:11px;line-height:1.55;">' . $rich($s['description']) . '</div>' : '')
            . ($s['scope_impact'] ? '<h2 style="font-size:13px;margin:16px 0 4px;">Scope impact</h2><div style="font-size:11px;line-height:1.55;">' . $rich($s['scope_impact']) . '</div>' : '')
            . ($s['time_impact'] ? '<h2 style="font-size:13px;margin:16px 0 4px;">Timeline impact</h2><div style="font-size:11px;line-height:1.55;">' . $rich($s['time_impact']) . '</div>' : '')
            . '<h2 style="font-size:13px;margin:16px 0 4px;">Pricing</h2>'
            . '<table style="width:100%;border-collapse:collapse;font-size:11px;">' . $rows . '</table>'
            . '<table style="width:260px;margin-left:auto;margin-top:8px;font-size:12px;">'
            . '<tr><td style="padding:3px 6px;">Subtotal</td><td style="padding:3px 6px;text-align:right;">' . $e($money($s['subtotal'] ?? 0)) . '</td></tr>'
            . ((int) ($s['tax_total'] ?? 0) > 0 ? '<tr><td style="padding:3px 6px;">Tax</td><td style="padding:3px 6px;text-align:right;">' . $e($money($s['tax_total'] ?? 0)) . '</td></tr>' : '')
            . '<tr><td style="padding:6px;border-top:2px solid ' . $ink . ';font-weight:bold;">Total</td><td style="padding:6px;border-top:2px solid ' . $ink . ';text-align:right;font-weight:bold;">' . $e($money($s['total'] ?? 0)) . '</td></tr>'
            . '</table>'
            . ($s['target_date_impact'] ? '<p style="font-size:11px;margin-top:12px;color:' . $muted . ';">Revised target date: ' . $e($s['target_date_impact']) . '</p>' : '')
            . '</body></html>';
    }
}
