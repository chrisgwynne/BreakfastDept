<?php

/**
 * A short system-style notice (e.g. form states, confirmations). Kept a
 * separate snippet so every "TRANSMITTING…" / "MESSAGE RECEIVED" moment
 * across the site looks and behaves the same.
 *
 * @var string $text
 * @var string $tone  ok|warn|info
 */
$text = $text ?? '';
$tone = $tone ?? 'info';
$dot  = match ($tone) {
    'ok'   => 'tt-dot--green',
    'warn' => 'tt-dot--red',
    default => 'tt-dot--yellow',
};
?>
<p class="form-status" role="status">
  <span class="tt-dot <?= $dot ?>" aria-hidden="true"></span>
  <strong><?= esc($text) ?></strong>
</p>
