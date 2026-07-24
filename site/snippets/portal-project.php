<?php

/**
 * Client portal — a single project's read-only experience.
 *
 * Overview, client-visible milestones + tasks, and shared files. Rendered only
 * for a valid portal session whose identity has a live grant for this project.
 *
 * @var array<string,mixed> $identity
 * @var array<string,mixed> $data overview, milestones, tasks, files
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$base = rtrim((string) kirby()->site()->url(), '/');
$ov = is_array($data['overview'] ?? null) ? $data['overview'] : [];
$milestones = is_array($data['milestones'] ?? null) ? $data['milestones'] : [];
$tasks = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];
$files = is_array($data['files'] ?? null) ? $data['files'] : [];
$byMilestone = [];
$looseTasks = [];
foreach ($tasks as $t) {
    $ms = (string) ($t['milestone_uuid'] ?? '');
    if ($ms === '') {
        $looseTasks[] = $t;
    } else {
        $byMilestone[$ms][] = $t;
    }
}
$size = static function (int $n): string {
    return $n > 1048576 ? round($n / 1048576, 1) . ' MB' : max(1, (int) round($n / 1024)) . ' KB';
};
$statusLabel = static fn (string $s): string => ucfirst(str_replace('_', ' ', $s));
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($ov['name'] ?? 'Project') ?></title>
<style>
  :root { --ink:#1c1a17; --muted:#6b6459; --line:#e7dfcd; --butter:#fdc800; --paper:#fffdf7; --purple:#5b3df5; --ok:#1c7a4a; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f6f1e6; color:var(--ink); font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; }
  .bar { background:var(--paper); border-bottom:1px solid var(--line); padding:14px 20px; display:flex; align-items:center; gap:12px; }
  .mark { width:18px; height:18px; background:var(--butter); border-radius:5px; display:inline-block; }
  .bar a { color:var(--ink); font-size:14px; text-decoration:none; border:1px solid var(--line); padding:6px 12px; border-radius:99px; }
  .bar .sp { margin-left:auto; }
  .wrap { max-width:760px; margin:28px auto; padding:0 16px; }
  a.back { color:var(--muted); font-size:14px; text-decoration:none; }
  h1 { font-size:26px; margin:8px 0 6px; }
  .pill { font-size:12px; padding:2px 10px; border-radius:99px; background:#efe9db; color:var(--muted); text-transform:capitalize; }
  .track { height:8px; background:#efe9db; border-radius:99px; overflow:hidden; margin:14px 0 4px; max-width:320px; }
  .fill { height:100%; background:var(--butter); }
  .meta { color:var(--muted); font-size:14px; }
  h2 { font-size:16px; margin:28px 0 10px; border-top:1px solid var(--line); padding-top:20px; }
  .ms { background:var(--paper); border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:12px; }
  .ms__head { display:flex; align-items:center; gap:10px; }
  .ms__title { font-weight:650; }
  .ms__due { margin-left:auto; color:var(--muted); font-size:13px; }
  ul.tasks { list-style:none; margin:10px 0 0; padding:0; }
  ul.tasks li { display:flex; align-items:center; gap:10px; padding:5px 0; font-size:15px; }
  .dot { width:9px; height:9px; border-radius:50%; background:#cfc7b5; flex:none; }
  .dot--done { background:var(--ok); }
  .dot--active { background:var(--butter); }
  li.done .tt { color:var(--muted); text-decoration:line-through; }
  .file { display:flex; align-items:center; gap:14px; background:var(--paper); border:1px solid var(--line); border-radius:12px; padding:12px 16px; margin-bottom:10px; }
  .file__ext { width:38px; height:38px; border-radius:8px; background:#efe9db; display:grid; place-items:center; font-size:11px; font-weight:700; color:var(--muted); flex:none; }
  .file__name { font-weight:600; }
  .file__meta { color:var(--muted); font-size:13px; }
  .file a { margin-left:auto; color:var(--ink); font-size:14px; text-decoration:none; border:1px solid var(--line); padding:6px 12px; border-radius:99px; }
  .empty { color:var(--muted); }
</style>
</head>
<body>
  <div class="bar">
    <span class="mark"></span> <strong>Breakfast</strong>
    <span class="sp"></span>
    <a href="<?= $e($base) ?>/portal">All projects</a>
    <a href="<?= $e($base) ?>/portal/logout">Sign out</a>
  </div>
  <div class="wrap" data-test="portal-project-view">
    <a class="back" href="<?= $e($base) ?>/portal">← Back to your projects</a>
    <h1><?= $e($ov['name'] ?? 'Project') ?></h1>
    <span class="pill"><?= $e($statusLabel((string) ($ov['status'] ?? ''))) ?></span>
    <div class="track"><span class="fill" style="width:<?= (int) ($ov['progress_percent'] ?? 0) ?>%"></span></div>
    <div class="meta"><?= (int) ($ov['progress_percent'] ?? 0) ?>% complete<?= !empty($ov['target_date']) ? ' · target ' . $e($ov['target_date']) : '' ?></div>

    <h2>Progress</h2>
    <?php if (!count($milestones) && !count($looseTasks)): ?>
      <p class="empty" data-test="portal-no-milestones">We’ll share milestones here as the work gets underway.</p>
    <?php else: ?>
      <?php foreach ($milestones as $m):
        $mid = (string) ($m['uuid'] ?? '');
        $mstatus = (string) ($m['status'] ?? ''); ?>
        <div class="ms" data-test="portal-milestone">
          <div class="ms__head">
            <span class="ms__title"><?= $e($m['title'] ?? '') ?></span>
            <span class="pill"><?= $e($statusLabel($mstatus)) ?></span>
            <?php if (!empty($m['due_date'])): ?><span class="ms__due">due <?= $e($m['due_date']) ?></span><?php endif; ?>
          </div>
          <?php $mt = $byMilestone[$mid] ?? []; if (count($mt)): ?>
            <ul class="tasks">
              <?php foreach ($mt as $t): $done = (string) ($t['status'] ?? '') === 'completed'; $active = (string) ($t['status'] ?? '') === 'in_progress'; ?>
                <li class="<?= $done ? 'done' : '' ?>"><span class="dot <?= $done ? 'dot--done' : ($active ? 'dot--active' : '') ?>"></span> <span class="tt"><?= $e($t['title'] ?? '') ?></span></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (count($looseTasks)): ?>
        <div class="ms">
          <div class="ms__head"><span class="ms__title">Other items</span></div>
          <ul class="tasks">
            <?php foreach ($looseTasks as $t): $done = (string) ($t['status'] ?? '') === 'completed'; ?>
              <li class="<?= $done ? 'done' : '' ?>"><span class="dot <?= $done ? 'dot--done' : '' ?>"></span> <span class="tt"><?= $e($t['title'] ?? '') ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <h2>Shared files</h2>
    <?php if (!count($files)): ?>
      <p class="empty" data-test="portal-no-files">No files have been shared with you yet.</p>
    <?php else: ?>
      <?php foreach ($files as $f): ?>
        <div class="file" data-test="portal-file">
          <span class="file__ext"><?= $e(strtoupper((string) ($f['extension'] ?? '')) ?: 'FILE') ?></span>
          <div>
            <div class="file__name"><?= $e($f['display_name'] ?? '') ?></div>
            <div class="file__meta">v<?= (int) ($f['current_version'] ?? 1) ?> · <?= $e($size((int) ($f['byte_size'] ?? 0))) ?></div>
          </div>
          <a href="<?= $e($base) ?>/portal/file/<?= $e($f['uuid'] ?? '') ?>/download" data-test="portal-file-download">Download</a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</body>
</html>
