<?php

/**
 * Hosted client onboarding form.
 *
 * Rendered for the tokened client link (/onboarding/<token>). Answers save
 * incrementally to the server (the durable source of truth) and validation is
 * server-side; conditional visibility is mirrored here only for UX. All monetary
 * and structural data comes from the frozen template version.
 *
 * @var array<string,mixed> $instance
 * @var array<string,mixed> $structure
 * @var string $token
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$questions = is_array($structure['questions'] ?? null) ? $structure['questions'] : [];
$sections  = is_array($structure['sections'] ?? null) ? $structure['sections'] : [];
$answers   = is_array($instance['answers'] ?? null) ? $instance['answers'] : [];
$status    = (string) ($instance['status'] ?? '');
$submitted = in_array($status, ['submitted', 'under_review', 'completed', 'needs_clarification'], true);
$base      = rtrim((string) kirby()->site()->url(), '/') . '/onboarding/' . $token;

// Group questions by section key.
$bySection = [];
foreach ($questions as $q) {
    $bySection[(string) ($q['section_key'] ?? '')][] = $q;
}
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Project onboarding</title>
<style>
  :root { --ink:#1c1a17; --muted:#6b6459; --line:#e7dfcd; --butter:#fdc800; --paper:#fffdf7; --purple:#5b3df5; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f6f1e6; color:var(--ink); font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; }
  .wrap { max-width:680px; margin:32px auto; padding:0 16px; }
  .sheet { background:var(--paper); border:1px solid var(--line); border-radius:14px; padding:32px; }
  h1 { font-size:24px; margin:0 0 4px; }
  .lead { color:var(--muted); margin:0 0 24px; }
  .sect { margin:28px 0 8px; font-size:18px; font-weight:650; border-top:1px solid var(--line); padding-top:20px; }
  .field { margin:16px 0; }
  label.q { display:block; font-weight:600; margin-bottom:6px; }
  .req { color:#c0392b; }
  .help { color:var(--muted); font-size:14px; margin:2px 0 6px; }
  input[type=text],input[type=email],input[type=tel],input[type=url],input[type=number],input[type=date],textarea,select {
    width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font:inherit; background:#fff; }
  textarea { min-height:90px; }
  .choices label { display:block; font-weight:400; margin:4px 0; }
  .bar { position:sticky; bottom:0; background:var(--paper); border-top:1px solid var(--line); padding:16px; margin:24px -32px -32px; display:flex; gap:12px; align-items:center; border-radius:0 0 14px 14px; }
  .btn { background:var(--ink); color:#fff; border:none; padding:11px 20px; border-radius:99px; font-weight:600; cursor:pointer; font-size:15px; }
  .btn--ghost { background:transparent; color:var(--ink); border:1px solid var(--line); }
  .btn--primary { background:var(--butter); color:var(--ink); }
  .status { font-size:14px; color:var(--muted); margin-left:auto; }
  .done { background:#e5f4ec; color:#1c7a4a; padding:16px; border-radius:10px; font-weight:600; }
  [hidden] { display:none !important; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="sheet">
      <h1>Project onboarding</h1>
      <p class="lead">Your answers save automatically. You can leave and come back to this link at any time.</p>

      <?php if ($submitted): ?>
        <div class="done" data-test="onboarding-done">Thank you — your onboarding has been submitted. We’ll be in touch if we need anything else.</div>
      <?php else: ?>
      <form id="onboarding-form" data-base="<?= $e($base) ?>">
        <?php foreach ($sections as $s): $skey = (string) ($s['skey'] ?? ''); ?>
          <div class="sect"><?= $e($s['title'] ?? '') ?></div>
          <?php foreach ($bySection[$skey] ?? [] as $q):
            $type = (string) ($q['type'] ?? 'short_text');
            $qkey = (string) ($q['qkey'] ?? '');
            $val  = $answers[$qkey] ?? '';
            $cond = trim((string) ($q['condition'] ?? ''));
            if (in_array($type, ['info', 'heading'], true)) {
                echo '<div class="field"><strong>' . $e($q['label'] ?? '') . '</strong>';
                if (!empty($q['help'])) {
                    echo '<p class="help">' . $e($q['help']) . '</p>';
                }
                echo '</div>';
                continue;
            }
            ?>
            <div class="field" data-qkey="<?= $e($qkey) ?>"<?= $cond !== '' ? ' data-cond="' . $e($cond) . '"' : '' ?>>
              <label class="q"><?= $e($q['label'] ?? '') ?><?php if (!empty($q['required'])): ?> <span class="req">*</span><?php endif; ?></label>
              <?php if (!empty($q['help'])): ?><p class="help"><?= $e($q['help']) ?></p><?php endif; ?>
              <?php
              $name = 'q_' . $e($qkey);
              switch ($type):
                case 'long_text': ?>
                  <textarea name="<?= $name ?>" data-q="<?= $e($qkey) ?>"><?= $e(is_array($val) ? '' : $val) ?></textarea>
                <?php break;
                case 'yes_no': ?>
                  <select name="<?= $name ?>" data-q="<?= $e($qkey) ?>">
                    <option value="">—</option>
                    <option value="yes"<?= $val === 'yes' ? ' selected' : '' ?>>Yes</option>
                    <option value="no"<?= $val === 'no' ? ' selected' : '' ?>>No</option>
                  </select>
                <?php break;
                case 'single_choice': ?>
                  <select name="<?= $name ?>" data-q="<?= $e($qkey) ?>">
                    <option value="">—</option>
                    <?php foreach ($q['options'] ?? [] as $o): ?>
                      <option value="<?= $e($o['value']) ?>"<?= $val === $o['value'] ? ' selected' : '' ?>><?= $e($o['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php break;
                case 'multi_choice': ?>
                  <div class="choices" data-q="<?= $e($qkey) ?>" data-multi="1">
                    <?php $sel = is_array($val) ? $val : []; foreach ($q['options'] ?? [] as $o): ?>
                      <label><input type="checkbox" value="<?= $e($o['value']) ?>"<?= in_array($o['value'], $sel, true) ? ' checked' : '' ?>> <?= $e($o['label']) ?></label>
                    <?php endforeach; ?>
                  </div>
                <?php break;
                default:
                  $inputType = ['email' => 'email', 'phone' => 'tel', 'url' => 'url', 'number' => 'number', 'currency' => 'number', 'date' => 'date'][$type] ?? 'text'; ?>
                  <input type="<?= $inputType ?>" name="<?= $name ?>" data-q="<?= $e($qkey) ?>" value="<?= $e(is_array($val) ? '' : $val) ?>" placeholder="<?= $e($q['placeholder'] ?? '') ?>">
              <?php endswitch; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="bar">
          <button type="button" class="btn btn--ghost" id="save-btn" data-test="onboarding-save">Save draft</button>
          <button type="submit" class="btn btn--primary" data-test="onboarding-submit">Submit onboarding</button>
          <span class="status" id="save-status"></span>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <script>
    (function () {
      var form = document.getElementById('onboarding-form');
      if (!form) return;
      var base = form.getAttribute('data-base');
      var statusEl = document.getElementById('save-status');

      function collect() {
        var answers = {};
        form.querySelectorAll('[data-q]').forEach(function (el) {
          var key = el.getAttribute('data-q');
          if (el.getAttribute('data-multi')) {
            answers[key] = Array.prototype.slice.call(el.querySelectorAll('input:checked')).map(function (c) { return c.value; });
          } else {
            answers[key] = el.value;
          }
        });
        return answers;
      }

      function applyConditions() {
        var a = collect();
        form.querySelectorAll('[data-cond]').forEach(function (field) {
          try {
            var cond = JSON.parse(field.getAttribute('data-cond'));
            field.hidden = !evalGroup(cond, a);
          } catch (e) { /* show on parse error */ }
        });
      }
      function evalGroup(g, a) {
        var op = g.op === 'or' ? 'or' : 'and';
        var rules = g.rules || [];
        if (!rules.length) return true;
        var res = rules.map(function (r) { return r.op ? evalGroup(r, a) : evalRule(r, a); });
        return op === 'or' ? res.indexOf(true) !== -1 : res.indexOf(false) === -1;
      }
      function evalRule(r, a) {
        var v = a[r.q];
        switch (r.cmp) {
          case 'equals': return String(v) === String(r.value);
          case 'not_equals': return String(v) !== String(r.value);
          case 'answered': return v !== undefined && v !== '' && !(Array.isArray(v) && !v.length);
          case 'selected': return Array.isArray(v) ? v.indexOf(String(r.value)) !== -1 : String(v) === String(r.value);
          default: return true;
        }
      }

      function post(path, done) {
        statusEl.textContent = 'Saving…';
        fetch(base + path, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ answers: collect() }),
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok) { statusEl.textContent = res.j.error || 'Could not save.'; return; }
            statusEl.textContent = path === '/submit' ? 'Submitted.' : 'Saved.';
            if (done) done(res.j);
          }).catch(function () { statusEl.textContent = 'Network error.'; });
      }

      form.addEventListener('change', applyConditions);
      document.getElementById('save-btn').addEventListener('click', function () { post('/save'); });
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        post('/submit', function () { location.reload(); });
      });
      applyConditions();
    })();
  </script>
</body>
</html>
