<?php

/**
 * Client portal home — the signed-in client's project list.
 *
 * Rendered only for a valid portal session. Shows exactly the projects the
 * identity has been granted (server-enforced); nothing else is reachable.
 *
 * @var array<string,mixed> $identity
 * @var list<array<string,mixed>> $projects
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$base = rtrim((string) kirby()->site()->url(), '/');
$name = trim((string) ($identity['display_name'] ?? '')) ?: (string) ($identity['email'] ?? 'there');
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Your projects</title>
<style>
  :root { --ink:#1c1a17; --muted:#6b6459; --line:#e7dfcd; --butter:#fdc800; --paper:#fffdf7; --purple:#5b3df5; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f6f1e6; color:var(--ink); font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; }
  .bar { background:var(--paper); border-bottom:1px solid var(--line); padding:14px 20px; display:flex; align-items:center; gap:12px; }
  .mark { width:18px; height:18px; background:var(--butter); border-radius:5px; display:inline-block; }
  .bar .who { margin-left:auto; color:var(--muted); font-size:14px; }
  .bar a { color:var(--ink); font-size:14px; text-decoration:none; border:1px solid var(--line); padding:6px 12px; border-radius:99px; }
  .wrap { max-width:760px; margin:28px auto; padding:0 16px; }
  h1 { font-size:24px; margin:0 0 4px; }
  .lead { color:var(--muted); margin:0 0 24px; }
  .proj { display:flex; align-items:center; gap:16px; background:var(--paper); border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:12px; }
  .proj__name { font-weight:650; }
  .proj__meta { color:var(--muted); font-size:13px; }
  .pill { font-size:12px; padding:2px 10px; border-radius:99px; background:#efe9db; color:var(--muted); text-transform:capitalize; }
  .bar2 { margin-left:auto; text-align:right; min-width:120px; }
  .track { height:6px; background:#efe9db; border-radius:99px; overflow:hidden; margin-top:6px; }
  .fill { height:100%; background:var(--butter); }
  .empty { color:var(--muted); background:var(--paper); border:1px solid var(--line); border-radius:12px; padding:24px; text-align:center; }
</style>
</head>
<body>
  <div class="bar">
    <span class="mark"></span> <strong>Breakfast</strong>
    <span class="who"><?= $e($name) ?></span>
    <a href="<?= $e($base) ?>/portal/logout" data-test="portal-logout">Sign out</a>
  </div>
  <div class="wrap" data-test="portal-home">
    <h1>Hello <?= $e($name) ?></h1>
    <p class="lead">Here are your projects with us.</p>

    <?php if (!count($projects)): ?>
      <div class="empty" data-test="portal-empty">You don’t have any active projects to view yet. We’ll let you know when there’s something here.</div>
    <?php else: ?>
      <?php foreach ($projects as $pr): ?>
        <div class="proj" data-test="portal-project">
          <div>
            <div class="proj__name"><?= $e($pr['name'] ?? '') ?></div>
            <div class="proj__meta"><?= !empty($pr['target_date']) ? 'Target: ' . $e($pr['target_date']) : 'In progress' ?></div>
          </div>
          <div class="bar2">
            <span class="pill"><?= $e(str_replace('_', ' ', (string) ($pr['status'] ?? ''))) ?></span>
            <div class="track"><span class="fill" style="width:<?= (int) ($pr['progress_percent'] ?? 0) ?>%"></span></div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</body>
</html>
