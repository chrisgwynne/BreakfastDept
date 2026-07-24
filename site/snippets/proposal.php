<?php

/**
 * Hosted client proposal page (/proposal/<token>). Self-contained + branded.
 * Shows the narrative, priced items (with selectable optional extras), totals,
 * and a genuine acceptance form. Acceptance posts name/email and is recorded
 * server-side as an evidenced act — a page visit alone is never acceptance.
 *
 * @var array<string,mixed> $proposal
 * @var string $token
 */
$p = $proposal;
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$rich = static fn ($v): string => nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
$money = static function ($pence) use ($p): string {
    $sym = ($p['currency'] ?? 'GBP') === 'GBP' ? '£' : '';
    return $sym . number_format(((int) $pence) / 100, 2);
};
$qtyF = static fn ($q): string => rtrim(rtrim(number_format(((int) $q) / 1000, 3), '0'), '.');
$items = is_array($p['items'] ?? null) ? $p['items'] : [];
$vatReg = !empty($p['vat_registered']);
$status = (string) ($p['status'] ?? 'sent');
$accepted = $status === 'accepted';
$closed = in_array($status, ['accepted', 'rejected', 'withdrawn', 'superseded', 'expired'], true);
$base = rtrim((string) kirby()->site()->url(), '/') . '/proposal/' . $e($token);

$labels = [
    'introduction' => 'Introduction', 'client_problem' => 'The challenge',
    'recommended_solution' => 'Our recommendation', 'scope' => 'Scope',
    'deliverables' => 'Deliverables', 'exclusions' => 'Exclusions',
    'assumptions' => 'Assumptions', 'timeline' => 'Timeline',
    'payment_schedule' => 'Payment schedule', 'terms' => 'Terms',
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($p['title'] ?? 'Proposal') ?></title>
<style>
  :root { --ink:#1c1a17; --muted:#6b6459; --line:#e5ded0; --butter:#fdc800; --purple:#5b3df5; --paper:#fbf7ee; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:var(--paper); line-height:1.55; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 32px 20px 80px; }
  .brand { display:flex; align-items:center; gap:10px; margin-bottom:24px; }
  .brand__mark { width:22px; height:22px; background:var(--butter); border-radius:5px; }
  .eyebrow { color:var(--purple); letter-spacing:1px; font-size:13px; font-weight:600; }
  h1 { font-size:28px; margin:6px 0; }
  .meta { color:var(--muted); font-size:14px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:22px; margin-top:20px; }
  h2 { font-size:16px; margin:0 0 6px; } .sec + .sec { margin-top:16px; }
  .sec p { margin:0; color:#3a352d; font-size:15px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th { text-align:left; color:var(--muted); font-size:11px; text-transform:uppercase; padding:6px; border-bottom:2px solid var(--ink); }
  td { padding:9px 6px; border-bottom:1px solid var(--line); }
  .num { text-align:right; }
  .grp { font-weight:700; padding-top:14px; }
  .totrow td { border:0; padding:4px 6px; }
  .totrow--total td { border-top:2px solid var(--ink); font-weight:700; }
  .dep td { background:var(--butter); font-weight:700; }
  .btn { display:inline-block; border:0; border-radius:10px; padding:12px 20px; font-size:15px; font-weight:600; cursor:pointer; }
  .btn--primary { background:var(--purple); color:#fff; } .btn--ghost { background:#efe9db; color:var(--ink); }
  .field { margin-bottom:12px; } .field label { display:block; font-size:13px; color:var(--muted); margin-bottom:4px; }
  .input { width:100%; padding:11px; border:1px solid var(--line); border-radius:8px; font-size:15px; }
  .status { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; background:#efe9db; }
  .status--accepted { background:#d8f3df; color:#1c6b34; }
  .opt { display:flex; align-items:center; gap:8px; }
  .banner { padding:14px 18px; border-radius:10px; margin-top:20px; font-weight:600; }
  .banner--ok { background:#d8f3df; color:#1c6b34; }
  .banner--closed { background:#efe9db; color:var(--muted); }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><span class="brand__mark"></span><strong><?= $e($p['seller_name'] ?: 'Breakfast') ?></strong></div>
  <div class="eyebrow">PROPOSAL <?= $e($p['number'] ?? '') ?></div>
  <h1><?= $e($p['title'] ?? 'Proposal') ?></h1>
  <div class="meta">Prepared for <?= $e($p['client_name'] ?? '') ?>
    <?php if (!empty($p['expiry_date'])): ?> · valid until <?= $e($p['expiry_date']) ?><?php endif ?>
    · <span class="status status--<?= $e($status) ?>"><?= $e(ucfirst($status)) ?></span></div>

  <div class="card">
    <?php foreach ($labels as $key => $label): $val = trim((string) ($p[$key] ?? '')); ?>
      <?php if ($val !== ''): ?>
        <div class="sec"><h2><?= $e($label) ?></h2><p><?= $rich($val) ?></p></div>
      <?php endif ?>
    <?php endforeach ?>
  </div>

  <div class="card">
    <h2>Investment</h2>
    <form method="post" action="<?= $base ?>/select">
      <table>
        <tr><th>Description</th><th class="num">Qty</th><th class="num">Unit</th><?php if ($vatReg): ?><th class="num">VAT</th><?php endif ?><th class="num">Amount</th></tr>
        <?php
        $render = static function (string $kind, string $heading) use ($items, $e, $qtyF, $money, $vatReg, $closed) {
            $rows = array_filter($items, static fn ($i) => (string) ($i['kind'] ?? 'fixed') === $kind);
            if (!$rows) {
                return;
            }
            $cols = $vatReg ? 5 : 4;
            echo '<tr><td class="grp" colspan="' . $cols . '">' . $e($heading) . '</td></tr>';
            foreach ($rows as $i) {
                echo '<tr>';
                if ($kind === 'optional') {
                    $checked = (int) ($i['is_selected'] ?? 0) === 1 ? 'checked' : '';
                    $dis = $closed ? 'disabled' : '';
                    echo '<td><label class="opt"><input type="checkbox" name="options[]" value="' . $e($i['uuid'] ?? '') . '" ' . $checked . ' ' . $dis . '> ' . $e($i['description'] ?? '') . '</label></td>';
                } else {
                    echo '<td>' . $e($i['description'] ?? '') . '</td>';
                }
                echo '<td class="num">' . $e($qtyF($i['quantity'] ?? 0)) . '</td>';
                echo '<td class="num">' . $e($money($i['unit_price'] ?? 0)) . '</td>';
                if ($vatReg) {
                    echo '<td class="num">' . number_format(((int) ($i['tax_rate'] ?? 0)) / 100, 0) . '%</td>';
                }
                $suffix = $kind === 'recurring' && ($i['recurrence'] ?? '') !== '' ? ' / ' . $e($i['recurrence']) : '';
                echo '<td class="num">' . $e($money($i['line_total'] ?? 0)) . $suffix . '</td>';
                echo '</tr>';
            }
        };
        $render('fixed', 'Included');
        $render('optional', 'Optional extras');
        $render('recurring', 'Ongoing (per period)');
        ?>
      </table>
      <table style="margin-top:12px;">
        <tr class="totrow"><td>Subtotal</td><td class="num"><?= $money($p['subtotal'] ?? 0) ?></td></tr>
        <?php if ($vatReg): ?><tr class="totrow"><td>VAT</td><td class="num"><?= $money($p['tax_total'] ?? 0) ?></td></tr><?php endif ?>
        <tr class="totrow totrow--total"><td>Total</td><td class="num"><?= $money($p['total'] ?? 0) ?></td></tr>
        <?php if ((int) ($p['deposit_amount'] ?? 0) > 0): ?><tr class="totrow dep"><td>Deposit to start</td><td class="num"><?= $money($p['deposit_amount']) ?></td></tr><?php endif ?>
        <?php if ((int) ($p['recurring_total'] ?? 0) > 0): ?><tr class="totrow"><td>Then per period</td><td class="num"><?= $money($p['recurring_total']) ?></td></tr><?php endif ?>
      </table>
      <?php if (!$closed): ?><p style="margin-top:12px;"><button class="btn btn--ghost" type="submit">Update options</button></p><?php endif ?>
    </form>
  </div>

  <?php if ($accepted): ?>
    <div class="banner banner--ok">✓ Accepted — thank you. We’ll be in touch to get started.</div>
  <?php elseif ($closed): ?>
    <div class="banner banner--closed">This proposal is <?= $e($status) ?> and can no longer be accepted online.</div>
  <?php else: ?>
    <div class="card">
      <h2>Accept this proposal</h2>
      <p class="meta">Accepting confirms the scope and total above (including any selected extras).</p>
      <form method="post" action="<?= $base ?>/accept">
        <div class="field"><label>Your name</label><input class="input" name="name" required></div>
        <div class="field"><label>Your email</label><input class="input" name="email" type="email"></div>
        <button class="btn btn--primary" type="submit">Accept proposal</button>
        <button class="btn btn--ghost" type="submit" formaction="<?= $base ?>/reject" formnovalidate>Decline</button>
      </form>
    </div>
  <?php endif ?>
</div>
</body>
</html>
