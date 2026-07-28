<?php

/**
 * Hosted client change-request page.
 *
 * Rendered for the tokened client link (/change/<token>). All figures come from
 * the frozen snapshot taken at send — never from live data — so what the client
 * sees and approves is exactly what was recorded. Approval is an explicit,
 * evidenced act (typed name + confirmation); a decision is server-enforced.
 *
 * @var array<string,mixed> $cr
 * @var string $token
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$nonce = (string) ($nonce ?? '');
// The snapshot is stored as a native array (flat-file store); tolerate a legacy
// JSON string too.
$snapRaw = $cr['snapshot'] ?? null;
$snap = is_array($snapRaw) ? $snapRaw : (is_string($snapRaw) && $snapRaw !== '' ? (json_decode($snapRaw, true) ?: []) : []);
$status = (string) ($cr['status'] ?? '');
$decided = in_array($status, ['approved', 'rejected'], true);
$base = rtrim((string) kirby()->site()->url(), '/') . '/change/' . $token;
$currency = (string) ($snap['currency'] ?? 'GBP');
$money = static function ($pence) use ($currency): string {
    $sym = $currency === 'GBP' ? '£' : '';
    return $sym . number_format(((int) $pence) / 100, 2);
};
$items = is_array($snap['items'] ?? null) ? $snap['items'] : [];
$expired = $status === 'expired';
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Change request <?= $e($snap['number'] ?? '') ?></title>
<style>
  :root { --ink:#1c1a17; --muted:#6b6459; --line:#e7dfcd; --butter:#fdc800; --paper:#fffdf7; --purple:#5b3df5; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f6f1e6; color:var(--ink); font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; }
  .wrap { max-width:680px; margin:32px auto; padding:0 16px; }
  .sheet { background:var(--paper); border:1px solid var(--line); border-radius:14px; padding:32px; }
  .eyebrow { color:var(--purple); letter-spacing:1px; font-size:12px; font-weight:700; text-transform:uppercase; }
  h1 { font-size:24px; margin:4px 0 4px; }
  .lead { color:var(--muted); margin:0 0 24px; }
  h2 { font-size:16px; margin:24px 0 6px; border-top:1px solid var(--line); padding-top:18px; }
  .body-text { white-space:pre-wrap; }
  table.items { width:100%; border-collapse:collapse; margin-top:8px; }
  table.items td { padding:8px 4px; border-bottom:1px solid var(--line); }
  table.items td.amt { text-align:right; white-space:nowrap; }
  .opt { color:var(--muted); font-style:italic; }
  .totals { width:260px; margin-left:auto; margin-top:12px; }
  .totals td { padding:4px 6px; }
  .totals tr.grand td { border-top:2px solid var(--ink); font-weight:bold; font-size:18px; }
  .decision { margin:28px -32px -32px; padding:24px 32px; border-top:1px solid var(--line); background:#fff; border-radius:0 0 14px 14px; }
  .field { margin:12px 0; }
  label.q { display:block; font-weight:600; margin-bottom:6px; }
  input[type=text],input[type=email],textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font:inherit; background:#fff; }
  textarea { min-height:70px; }
  .check { display:flex; gap:8px; align-items:flex-start; margin:12px 0; font-size:14px; }
  .btn { border:none; padding:12px 22px; border-radius:99px; font-weight:600; cursor:pointer; font-size:15px; }
  .btn--primary { background:var(--butter); color:var(--ink); }
  .btn--ghost { background:transparent; color:var(--ink); border:1px solid var(--line); }
  .btn[disabled] { opacity:.5; cursor:not-allowed; }
  .actions { display:flex; gap:12px; align-items:center; margin-top:16px; }
  .done { padding:16px; border-radius:10px; font-weight:600; }
  .done--ok { background:#e5f4ec; color:#1c7a4a; }
  .done--no { background:#fbeaea; color:#a3352b; }
  .err { color:#a3352b; font-size:14px; margin-left:auto; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="sheet" data-test="change-request">
      <div class="eyebrow">Change request <?= $e($snap['number'] ?? '') ?></div>
      <h1><?= $e($snap['title'] ?? 'Change request') ?></h1>
      <p class="lead">For <?= $e($snap['project_name'] ?? '') ?><?= !empty($snap['seller_name']) ? ' &middot; from ' . $e($snap['seller_name']) : '' ?></p>

      <?php if ($status === 'approved'): ?>
        <div class="done done--ok" data-test="cr-approved">Thank you — you approved this change on <?= $e(substr((string) ($cr['decided_at'] ?? ''), 0, 10)) ?>. We’ll get started.</div>
      <?php elseif ($status === 'rejected'): ?>
        <div class="done done--no" data-test="cr-rejected">You declined this change. Nothing has changed on your project. Contact us if you’d like to discuss it.</div>
      <?php elseif ($expired): ?>
        <div class="done done--no">This change request has expired. Please contact us for an updated version.</div>
      <?php endif; ?>

      <?php if (!empty($snap['description'])): ?>
        <h2>What is changing</h2>
        <div class="body-text"><?= $e($snap['description']) ?></div>
      <?php endif; ?>
      <?php if (!empty($snap['scope_impact'])): ?>
        <h2>Scope impact</h2>
        <div class="body-text"><?= $e($snap['scope_impact']) ?></div>
      <?php endif; ?>
      <?php if (!empty($snap['time_impact'])): ?>
        <h2>Timeline impact</h2>
        <div class="body-text"><?= $e($snap['time_impact']) ?></div>
      <?php endif; ?>

      <h2>Pricing</h2>
      <table class="items">
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= $e($it['description'] ?? '') ?><?= ($it['kind'] ?? '') === 'optional' ? ' <span class="opt">(optional)</span>' : '' ?></td>
            <td class="amt"><?= $e($money($it['line_total'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <table class="totals">
        <tr><td>Subtotal</td><td class="amt"><?= $e($money($snap['subtotal'] ?? 0)) ?></td></tr>
        <?php if ((int) ($snap['tax_total'] ?? 0) > 0): ?>
          <tr><td>Tax</td><td class="amt"><?= $e($money($snap['tax_total'] ?? 0)) ?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td>Total</td><td class="amt"><?= $e($money($snap['total'] ?? 0)) ?></td></tr>
      </table>
      <?php if (!empty($snap['target_date_impact'])): ?>
        <p class="lead" style="margin-top:16px;">Revised target date: <strong><?= $e($snap['target_date_impact']) ?></strong></p>
      <?php endif; ?>

      <?php if (!$decided && !$expired): ?>
      <div class="decision">
        <form id="cr-form" data-base="<?= $e($base) ?>">
          <div class="field">
            <label class="q" for="cr-name">Your full name <span style="color:#c0392b;">*</span></label>
            <input type="text" id="cr-name" name="name" autocomplete="name" required>
          </div>
          <div class="field">
            <label class="q" for="cr-email">Email</label>
            <input type="email" id="cr-email" name="email" autocomplete="email">
          </div>
          <div class="field">
            <label class="q" for="cr-comment">Comment (optional)</label>
            <textarea id="cr-comment" name="comment"></textarea>
          </div>
          <label class="check">
            <input type="checkbox" id="cr-consent">
            <span>I confirm I’m authorised to approve this change and its price of <strong><?= $e($money($snap['total'] ?? 0)) ?></strong>.</span>
          </label>
          <div class="actions">
            <button type="button" class="btn btn--primary" id="cr-approve" data-test="cr-approve" disabled>Approve this change</button>
            <button type="button" class="btn btn--ghost" id="cr-reject" data-test="cr-reject">Decline</button>
            <span class="err" id="cr-error"></span>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$decided && !$expired): ?>
  <script<?= $nonce !== '' ? ' nonce="' . $e($nonce) . '"' : '' ?>>
    (function () {
      var form = document.getElementById('cr-form');
      if (!form) return;
      var base = form.getAttribute('data-base');
      var consent = document.getElementById('cr-consent');
      var approve = document.getElementById('cr-approve');
      var reject = document.getElementById('cr-reject');
      var nameEl = document.getElementById('cr-name');
      var errEl = document.getElementById('cr-error');

      function refresh() { approve.disabled = !(consent.checked && nameEl.value.trim()); }
      consent.addEventListener('change', refresh);
      nameEl.addEventListener('input', refresh);

      function submit(verb) {
        errEl.textContent = '';
        var body = new URLSearchParams({
          name: nameEl.value, email: document.getElementById('cr-email').value,
          comment: document.getElementById('cr-comment').value,
        });
        approve.disabled = true; reject.disabled = true;
        fetch(base + '/' + verb, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
          .then(function (r) {
            if (r.redirected) { location.href = r.url; return; }
            if (!r.ok) { return r.text().then(function (t) { errEl.textContent = t || 'Something went wrong.'; refresh(); reject.disabled = false; }); }
            location.reload();
          })
          .catch(function () { errEl.textContent = 'Network error. Please try again.'; refresh(); reject.disabled = false; });
      }
      approve.addEventListener('click', function () { submit('approve'); });
      reject.addEventListener('click', function () {
        if (confirm('Decline this change? Nothing on your project will change.')) submit('reject');
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
