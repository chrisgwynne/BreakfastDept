<?php

/**
 * Coloured soft-key row (RED/GREEN/YELLOW/CYAN) — the Teletext colour-key
 * navigation convention, made into real, accessible, keyboard-reachable
 * controls. A template can pass its own 4-item $softkeys array (each with
 * label/sublabel/href) to override the sensible defaults below; anything it
 * doesn't override falls back to the site-wide default.
 *
 * @var array<int,array{label:string,sub?:string,href:string}>|null $softkeys
 */

$defaults = [
    ['label' => 'Back',     'sub' => 'P100',  'href' => url('/')],
    ['label' => 'Our Work', 'sub' => 'P200',  'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Services', 'sub' => 'P300',  'href' => page('services') ? page('services')->url() : url('services')],
    ['label' => 'Contact',  'sub' => 'P700',  'href' => page('contact') ? page('contact')->url() : url('contact')],
];

$keys = (isset($softkeys) && is_array($softkeys) && count($softkeys) === 4) ? $softkeys : $defaults;
$colours = ['red', 'green', 'yellow', 'cyan'];
?>
<nav class="tt-softkeys" aria-label="Quick navigation">
  <?php foreach ($keys as $i => $key): ?>
    <a class="tt-softkey tt-softkey--<?= $colours[$i] ?>" href="<?= esc($key['href']) ?>">
      <span class="tt-softkey__dot" aria-hidden="true"></span>
      <span class="tt-softkey__label"><?= esc($key['label']) ?></span>
      <?php if (!empty($key['sub'])): ?><small><?= esc($key['sub']) ?></small><?php endif ?>
    </a>
  <?php endforeach ?>
</nav>
