<?php

/**
 * Client portal sign-in.
 *
 * Passwordless: the client enters their email and receives a single-use link.
 * The page never reveals whether an address is registered. Outside production a
 * dev link is shown so the flow is demonstrable without an inbox.
 *
 * @var bool        $sent
 * @var string|null $devLink
 * @var string|null $error
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$sent = $sent ?? false;
$devLink = $devLink ?? null;
$error = $error ?? null;
$base = rtrim((string) kirby()->site()->url(), '/');
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Project portal — sign in</title>
<style>
  :root { --ink:#1c1a17; --muted:#6b6459; --line:#e7dfcd; --butter:#fdc800; --paper:#fffdf7; --purple:#5b3df5; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f6f1e6; color:var(--ink); font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; display:grid; min-height:100vh; place-items:center; }
  .card { background:var(--paper); border:1px solid var(--line); border-radius:14px; padding:32px; width:min(420px, 92vw); }
  .mark { width:20px; height:20px; background:var(--butter); border-radius:5px; display:inline-block; vertical-align:-3px; }
  h1 { font-size:22px; margin:12px 0 4px; }
  .lead { color:var(--muted); margin:0 0 20px; }
  label { display:block; font-weight:600; margin-bottom:6px; }
  input[type=email] { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:8px; font:inherit; background:#fff; }
  .btn { width:100%; margin-top:14px; background:var(--butter); color:var(--ink); border:none; padding:12px; border-radius:99px; font-weight:700; cursor:pointer; font-size:15px; }
  .note { margin-top:16px; padding:14px; border-radius:10px; background:#e5f4ec; color:#1c7a4a; font-size:14px; }
  .err { margin-top:16px; padding:14px; border-radius:10px; background:#fbeaea; color:#a3352b; font-size:14px; }
  .dev { margin-top:14px; padding:12px; border:1px dashed var(--purple); border-radius:10px; font-size:13px; word-break:break-all; }
  .dev a { color:var(--purple); }
</style>
</head>
<body>
  <div class="card" data-test="portal-login">
    <div><span class="mark"></span> <strong>Breakfast</strong></div>
    <?php if ($error): ?>
      <h1>Link problem</h1>
      <div class="err" data-test="portal-error"><?= $e($error) ?></div>
      <p class="lead" style="margin-top:16px;">Request a fresh link below.</p>
    <?php elseif ($sent): ?>
      <h1>Check your email</h1>
      <p class="lead">If that address has a project with us, a secure sign-in link is on its way. It works once and expires in 15 minutes.</p>
      <?php if ($devLink): ?>
        <div class="dev">Dev link (not shown in production): <a href="<?= $e($devLink) ?>" data-test="portal-dev-link"><?= $e($devLink) ?></a></div>
      <?php endif; ?>
    <?php else: ?>
      <h1>Your project portal</h1>
      <p class="lead">Enter your email and we’ll send you a secure sign-in link — no password needed.</p>
    <?php endif; ?>

    <?php if (!$sent || $error): ?>
    <form method="post" action="<?= $e($base) ?>/portal/login">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@example.com" data-test="portal-email">
      <button type="submit" class="btn" data-test="portal-submit">Email me a link</button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
