<?php

/**
 * Standalone, print-ready branded invoice document.
 *
 * Rendered for the signed client link (/invoice/<token>) and the admin preview.
 * Self-contained (inline CSS, @media print) so it prints/saves-as-PDF cleanly.
 * All monetary values arrive as integer pence.
 *
 * @var array<string,mixed> $invoice
 */
$money = static function ($pence) use ($invoice): string {
    $symbol = ($invoice['currency'] ?? 'GBP') === 'GBP' ? '£' : '';
    return $symbol . number_format(((int) $pence) / 100, 2);
};
$qty = static fn ($q): string => rtrim(rtrim(number_format(((int) $q) / 1000, 3), '0'), '.');
$e   = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$date = static function ($d): string {
    if (!$d) {
        return '—';
    }
    $t = strtotime((string) $d);
    return $t ? date('j F Y', $t) : (string) $d;
};

$items    = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];
$payments = is_array($invoice['payments'] ?? null) ? $invoice['payments'] : [];
$due      = (int) ($invoice['total'] ?? 0) - (int) ($invoice['amount_paid'] ?? 0);
$vatReg   = !empty($invoice['vat_registered']);
$statusLabels = ['draft' => 'Draft', 'issued' => 'Issued', 'sent' => 'Sent', 'viewed' => 'Viewed', 'partial' => 'Part paid', 'paid' => 'Paid', 'void' => 'Void'];
$status   = (string) ($invoice['status'] ?? 'draft');
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Invoice <?= $e($invoice['number'] ?? 'draft') ?> — <?= $e($invoice['seller_name'] ?? 'Breakfast') ?></title>
<style>
  :root { --ink:#1c1a17; --muted:#6b6459; --line:#e7dfcd; --butter:#fdc800; --paper:#fffdf7; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f6f1e6; color:var(--ink); font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; }
  .sheet { max-width:800px; margin:32px auto; background:var(--paper); border:1px solid var(--line); border-radius:14px; padding:48px; box-shadow:0 8px 30px rgba(28,26,23,.06); }
  .top { display:flex; justify-content:space-between; align-items:flex-start; gap:24px; margin-bottom:36px; }
  .brand { display:flex; align-items:center; gap:12px; }
  .egg { width:40px; height:40px; border-radius:50%; background:#fff; border:3px solid var(--ink); display:grid; place-items:center; }
  .egg::after { content:""; width:16px; height:16px; border-radius:50%; background:var(--butter); }
  .brand h1 { font-size:22px; margin:0; letter-spacing:-.01em; }
  .doc { text-align:right; }
  .doc h2 { margin:0 0 4px; font-size:26px; letter-spacing:.02em; }
  .badge { display:inline-block; font-size:12px; font-weight:700; padding:3px 10px; border-radius:99px; background:#efe9db; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .badge--paid { background:#e5f4ec; color:#1c7a4a; } .badge--void { background:#f6e5e5; color:#a33; }
  .badge--overdue { background:#f9ecd9; color:#9a6a00; }
  .parties { display:flex; gap:40px; margin-bottom:32px; }
  .parties > div { flex:1; }
  .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin-bottom:6px; }
  .parties p { margin:0; white-space:pre-line; }
  .meta { display:flex; gap:32px; margin-bottom:28px; font-size:14px; }
  .meta .lbl { margin-bottom:2px; }
  table { width:100%; border-collapse:collapse; margin-bottom:24px; }
  th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); border-bottom:2px solid var(--line); padding:0 8px 8px; }
  th.r, td.r { text-align:right; }
  td { padding:12px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
  .totals { margin-left:auto; width:280px; }
  .totals div { display:flex; justify-content:space-between; padding:6px 8px; }
  .totals .grand { border-top:2px solid var(--ink); margin-top:6px; padding-top:12px; font-weight:700; font-size:18px; }
  .totals .due { background:var(--butter); border-radius:8px; margin-top:8px; font-weight:700; }
  .foot { margin-top:36px; padding-top:24px; border-top:1px solid var(--line); display:flex; gap:40px; font-size:14px; }
  .foot > div { flex:1; }
  .foot p { margin:6px 0 0; white-space:pre-line; }
  .actions { max-width:800px; margin:0 auto 24px; text-align:right; }
  .btn { display:inline-block; background:var(--ink); color:#fff; text-decoration:none; padding:10px 18px; border-radius:99px; font-weight:600; font-size:14px; border:none; cursor:pointer; }
  @media print { body { background:#fff; } .sheet { margin:0; border:none; box-shadow:none; border-radius:0; padding:0; } .actions { display:none; } }
</style>
</head>
<body>
  <div class="actions"><button class="btn" onclick="window.print()">Download / print PDF</button></div>
  <div class="sheet">
    <div class="top">
      <div class="brand">
        <span class="egg"></span>
        <div><h1><?= $e($invoice['seller_name'] ?? 'Breakfast') ?></h1></div>
      </div>
      <div class="doc">
        <h2>Invoice</h2>
        <div><strong><?= $e($invoice['number'] ?? 'Draft') ?></strong></div>
        <?php $bmod = $invoice['overdue'] ?? false ? 'overdue' : ($status === 'paid' ? 'paid' : ($status === 'void' ? 'void' : '')); ?>
        <div style="margin-top:6px"><span class="badge badge--<?= $e($bmod) ?>"><?= $e($invoice['overdue'] ?? false ? 'Overdue' : ($statusLabels[$status] ?? $status)) ?></span></div>
      </div>
    </div>

    <div class="parties">
      <div>
        <div class="lbl">From</div>
        <p><strong><?= $e($invoice['seller_name'] ?? '') ?></strong>
<?= $e($invoice['seller_address'] ?? '') ?><?php if ($vatReg && !empty($invoice['seller_vat_number'])): ?>

VAT: <?= $e($invoice['seller_vat_number']) ?><?php endif; ?></p>
      </div>
      <div>
        <div class="lbl">Bill to</div>
        <p><strong><?= $e($invoice['bill_to_name'] ?? '') ?></strong>
<?= $e($invoice['bill_to_address'] ?? '') ?></p>
      </div>
    </div>

    <div class="meta">
      <div><div class="lbl">Issued</div><?= $e($date($invoice['issue_date'] ?? null)) ?></div>
      <div><div class="lbl">Due</div><?= $e($date($invoice['due_date'] ?? null)) ?></div>
      <?php if (!empty($invoice['project'])): ?><div><div class="lbl">Project</div><?= $e($invoice['project']) ?></div><?php endif; ?>
    </div>

    <table>
      <thead>
        <tr><th>Description</th><th class="r">Qty</th><th class="r">Unit</th><?php if ($vatReg): ?><th class="r">VAT</th><?php endif; ?><th class="r">Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
        <tr>
          <td><?= $e($it['description'] ?? '') ?></td>
          <td class="r"><?= $e($qty($it['quantity'] ?? 0)) ?></td>
          <td class="r"><?= $e($money($it['unit_price'] ?? 0)) ?></td>
          <?php if ($vatReg): ?><td class="r"><?= number_format(((int) ($it['tax_rate'] ?? 0)) / 100, 0) ?>%</td><?php endif; ?>
          <td class="r"><?= $e($money($it['line_total'] ?? 0)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?><tr><td colspan="5" style="color:var(--muted)">No line items.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <div class="totals">
      <div><span>Subtotal</span><span><?= $e($money($invoice['subtotal'] ?? 0)) ?></span></div>
      <?php if ($vatReg): ?><div><span>VAT</span><span><?= $e($money($invoice['tax_total'] ?? 0)) ?></span></div><?php endif; ?>
      <div class="grand"><span>Total</span><span><?= $e($money($invoice['total'] ?? 0)) ?></span></div>
      <?php if ((int) ($invoice['amount_paid'] ?? 0) > 0): ?><div><span>Paid</span><span>−<?= $e($money($invoice['amount_paid'] ?? 0)) ?></span></div><?php endif; ?>
      <div class="due"><span>Amount due</span><span><?= $e($money($due)) ?></span></div>
    </div>

    <div class="foot">
      <?php if (!empty($invoice['payment_details'])): ?>
      <div><div class="lbl">Payment</div><p><?= $e($invoice['payment_details']) ?></p></div>
      <?php endif; ?>
      <?php if (!empty($invoice['notes']) || !empty($invoice['terms'])): ?>
      <div>
        <?php if (!empty($invoice['notes'])): ?><div class="lbl">Notes</div><p><?= $e($invoice['notes']) ?></p><?php endif; ?>
        <?php if (!empty($invoice['terms'])): ?><div class="lbl" style="margin-top:12px">Terms</div><p><?= $e($invoice['terms']) ?></p><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
