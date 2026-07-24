<?php

/**
 * Hosted client contract signing page (/contract/<token>). Self-contained +
 * branded + noindex. Shows the full contract body and a genuine signing form —
 * the client must type their name and tick a consent box (an explicit,
 * evidenced act). A page visit alone only records "viewed". Fully keyboard
 * accessible; no drawing required.
 *
 * @var array<string,mixed> $contract
 * @var string $token
 */
$c = $contract;
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$rich = static fn ($v): string => nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
$status = (string) ($c['status'] ?? 'sent');
$parties = is_array($c['parties'] ?? null) ? $c['parties'] : [];
$clientParty = null;
foreach ($parties as $p) {
    if ((string) $p['role'] === 'client') {
        $clientParty = $p;
    }
}
$clientSigned = $clientParty !== null && (string) $clientParty['status'] === 'signed';
$closed = in_array($status, ['declined', 'void', 'expired', 'withdrawn', 'superseded'], true);
$completed = ($c['completed_at'] ?? '') !== '';
$sections = is_array($c['sections_list'] ?? null) ? $c['sections_list'] : [];
$base = rtrim((string) kirby()->site()->url(), '/') . '/contract/' . $e($token);
// The frozen, merge-resolved clauses live in the snapshot; fall back to live.
$snapshot = json_decode((string) ($c['snapshot'] ?? ''), true);
$renderSections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : array_map(static fn ($s) => ['heading' => $s['heading'] ?? '', 'body' => $s['body'] ?? ''], $sections);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($c['title'] ?? 'Contract') ?></title>
<style>
  :root { --ink:#1c1a17; --muted:#6b6459; --line:#e5ded0; --butter:#fdc800; --purple:#5b3df5; --paper:#fbf7ee; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:var(--paper); line-height:1.55; }
  .wrap { max-width: 760px; margin: 0 auto; padding: 32px 20px 80px; }
  .brand { display:flex; align-items:center; gap:10px; margin-bottom:20px; }
  .brand__mark { width:22px; height:22px; background:var(--butter); border-radius:5px; }
  .eyebrow { color:var(--purple); letter-spacing:1px; font-size:13px; font-weight:600; }
  h1 { font-size:26px; margin:6px 0; } h2 { font-size:16px; margin:18px 0 5px; }
  .meta { color:var(--muted); font-size:14px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:24px; margin-top:20px; }
  .sec p { margin:0 0 4px; color:#3a352d; font-size:15px; }
  .btn { display:inline-block; border:0; border-radius:10px; padding:12px 20px; font-size:15px; font-weight:600; cursor:pointer; }
  .btn--primary { background:var(--purple); color:#fff; } .btn--ghost { background:#efe9db; color:var(--ink); }
  .field { margin-bottom:12px; } .field label { display:block; font-size:13px; color:var(--muted); margin-bottom:4px; }
  .input { width:100%; padding:11px; border:1px solid var(--line); border-radius:8px; font-size:15px; }
  .consent { display:flex; gap:10px; align-items:flex-start; font-size:14px; margin:14px 0; }
  .banner { padding:14px 18px; border-radius:10px; margin-top:20px; font-weight:600; }
  .banner--ok { background:#d8f3df; color:#1c6b34; } .banner--closed { background:#efe9db; color:var(--muted); }
  a.dl { color:var(--purple); font-weight:600; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><span class="brand__mark"></span><strong><?= $e($c['seller_name'] ?? 'Breakfast') ?></strong></div>
  <div class="eyebrow">CONTRACT <?= $e($c['number'] ?? '') ?></div>
  <h1><?= $e($c['title'] ?? 'Contract') ?></h1>
  <div class="meta">Between <?= $e($c['seller_name'] ?? 'Breakfast') ?> and <?= $e($c['client_name'] ?? '') ?></div>

  <div class="card">
    <?php $n = 1; foreach ($renderSections as $sec): ?>
      <div class="sec"><h2><?= $n++ ?>. <?= $e($sec['heading'] ?? '') ?></h2><p><?= $rich($sec['body'] ?? '') ?></p></div>
    <?php endforeach ?>
  </div>

  <?php if ($completed): ?>
    <div class="banner banner--ok">✓ This contract is fully signed. Thank you.</div>
  <?php elseif ($clientSigned): ?>
    <div class="banner banner--ok">✓ You’ve signed. We’ll countersign and send you the final signed copy.</div>
  <?php elseif ($closed): ?>
    <div class="banner banner--closed">This contract is <?= $e($status) ?> and can no longer be signed.</div>
  <?php else: ?>
    <div class="card">
      <h2>Sign this contract</h2>
      <p class="meta"><?= $e($c['signature_wording'] ?? 'By typing your name and confirming below, you agree to this contract.') ?></p>
      <form method="post" action="<?= $base ?>/sign">
        <div class="field"><label for="name">Full legal name</label><input id="name" class="input" name="name" required autocomplete="name"></div>
        <div class="field"><label for="email">Email</label><input id="email" class="input" name="email" type="email" value="<?= $e($c['client_email'] ?? '') ?>"></div>
        <label class="consent"><input type="checkbox" name="consent" value="yes" required> <span>I have read and agree to this contract, and I’m authorised to sign on behalf of the Client.</span></label>
        <button class="btn btn--primary" type="submit">Sign contract</button>
        <button class="btn btn--ghost" type="submit" formaction="<?= $base ?>/decline" formnovalidate>Decline</button>
      </form>
    </div>
  <?php endif ?>
</div>
</body>
</html>
