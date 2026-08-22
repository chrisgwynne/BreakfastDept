<?php

/**
 * P100 — the Breakfast Text home index.
 *
 * The visible frame is a real 40 × 24 Teletext-style raster. Copy and links
 * remain semantic HTML; only the wordmark and breakfast illustration are
 * decorative cell art.
 */

$now = new DateTime('now', new DateTimeZone('Europe/London'));
$directory = [
    ['101', 'Start a project', url('start-a-project')],
    ['110', 'Website review', url('website-review')],
    ['200', 'Our work', page('work')?->url() ?? url('work')],
    ['300', 'Services', page('services')?->url() ?? url('services')],
    ['400', 'How it works', '/about#how'],
    ['500', 'About Breakfast', page('about')?->url() ?? url('about')],
    ['600', 'Journal', page('journal')?->url() ?? url('journal')],
    ['700', 'Contact', url('contact')],
];

$letterPatterns = [
    'B' => ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
    'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
    'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
    'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
    'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
    'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
    'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
    'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
];

$wordCells = [];
$word = 'BREAKFAST';
for ($row = 0; $row < 7; $row++) {
    foreach (str_split($word) as $index => $letter) {
        foreach (str_split($letterPatterns[$letter][$row]) as $cell) {
            $wordCells[] = $cell === '1';
        }
        if ($index < strlen($word) - 1) {
            $wordCells[] = false;
        }
    }
}

$artCells = [];
for ($y = 0; $y < 20; $y++) {
    for ($x = 0; $x < 28; $x++) {
        $colour = '';

        // Steam.
        if (($x === 5 && $y >= 1 && $y <= 3) || ($x === 8 && $y >= 0 && $y <= 2)) {
            $colour = 'white';
        }
        if (($x === 4 && $y === 2) || ($x === 7 && $y === 1)) {
            $colour = 'white';
        }

        // Blue mug with cyan rim and handle.
        if ($x >= 2 && $x <= 11 && $y >= 6 && $y <= 14) {
            $colour = 'cyan';
        }
        if ($x >= 3 && $x <= 10 && $y >= 7 && $y <= 13) {
            $colour = 'blue';
        }
        if ($x >= 12 && $x <= 15 && $y >= 8 && $y <= 12) {
            $colour = 'cyan';
        }
        if ($x >= 12 && $x <= 13 && $y >= 9 && $y <= 11) {
            $colour = 'blue';
        }
        if ($x >= 1 && $x <= 12 && $y === 15) {
            $colour = 'white';
        }

        // Toast and fried egg.
        $toastEdge = ($x >= 18 && $x <= 25 && $y >= 5 && $y <= 15)
            || ($x >= 17 && $x <= 26 && $y >= 7 && $y <= 14);
        if ($toastEdge) {
            $colour = 'yellow';
        }
        if ($x >= 19 && $x <= 24 && $y >= 8 && $y <= 13) {
            $colour = 'red';
        }
        $eggWhite = (($x >= 15 && $x <= 21) && ($y >= 14 && $y <= 18))
            || (($x >= 17 && $x <= 24) && ($y >= 13 && $y <= 17));
        if ($eggWhite) {
            $colour = 'white';
        }
        if ($x >= 19 && $x <= 22 && $y >= 14 && $y <= 16) {
            $colour = 'yellow';
        }

        // Counter and transmission shadow.
        if ($y === 18 && $x >= 1 && $x <= 26) {
            $colour = 'green';
        }
        if ($y === 19 && $x >= 3 && $x <= 24) {
            $colour = 'blue';
        }

        $artCells[] = $colour;
    }
}

snippet('layouts/header');
?>

<div class="tt-p100-viewport">
  <div class="tt-p100-holder" data-tt-p100-holder>
    <section class="tt-p100" id="top" data-tt-p100-stage aria-labelledby="home-heading">
      <div class="tt-p100__statusbar" aria-label="Teletext status row">
        <span>P100/01</span>
        <strong>BREAKFAST</strong>
        <span><b>100 <?= esc(strtoupper($now->format('d M'))) ?></b> <time data-tt-clock data-live="true"><?= esc($now->format('H:i:s')) ?></time></span>
      </div>

      <header class="tt-p100__masthead">
        <h1 id="home-heading" class="sr-only">Breakfast — web design in North Wales</h1>
        <div class="tt-p100__wordmark" aria-hidden="true">
          <?php foreach ($wordCells as $isOn): ?><i<?= $isOn ? ' class="is-on"' : '' ?>></i><?php endforeach ?>
        </div>
      </header>

      <div class="tt-p100__servicebar" aria-label="Breakfast Text service title">
        <span>P100</span><strong>WEB DESIGN · NORTH WALES</strong><span>INDEX</span>
      </div>

      <div class="tt-p100__proposition">
        <p>WEBSITES YOU'LL BE PROUD TO SEND PEOPLE TO.</p>
        <p>CLEAR WORDS · USEFUL DESIGN · BUILT TO WORK</p>
      </div>

      <div class="tt-p100__indexfield">
        <nav class="tt-p100__directory" aria-label="Breakfast Text index">
          <?php foreach ($directory as [$number, $label, $href]): ?>
            <a href="<?= esc($href) ?>"><b><?= esc($number) ?></b><span><?= esc($label) ?></span><i aria-hidden="true">................................</i><em aria-hidden="true">›</em></a>
          <?php endforeach ?>
          <div class="tt-p100__select"><strong>SELECT</strong><span>TYPE A THREE-DIGIT PAGE NUMBER</span></div>
        </nav>

        <aside class="tt-p100__feature" aria-label="Breakfast transmission graphic">
          <div class="tt-p100__art" aria-hidden="true">
            <?php foreach ($artCells as $colour): ?><i<?= $colour !== '' ? ' class="' . esc($colour, 'attr') . '"' : '' ?>></i><?php endforeach ?>
          </div>
          <div class="tt-p100__caption"><strong>GOOD MORNING</strong><span>BREAKFAST IS ONLINE</span></div>
        </aside>
      </div>

      <p class="tt-p100__serviceline"><b>P101</b> START A PROJECT&nbsp;&nbsp; <b>P110</b> GET A PLAIN-ENGLISH WEBSITE REVIEW</p>
      <p class="tt-p100__booking"><b>BOOKING STATUS:</b> <?= esc(strtoupper($site->availability_text()->or('Taking on new projects'))) ?> · NORTH WALES</p>

      <div class="tt-p100__bands">
        <p>NEED A WEBSITE? START AT PAGE 101 · REPLY WITHIN 24 HOURS</p>
        <p>GOOD WEBSITES · PLAIN ENGLISH · MADE IN NORTH WALES</p>
      </div>

      <p class="tt-p100__prompt"><span>BREAKFAST</span><span>PRESS A COLOUR KEY OR ENTER PAGE NUMBER</span><span>P100</span></p>

      <nav class="tt-p100__softkeys" aria-label="Quick navigation">
        <a href="#top"><i></i><span>BACK</span></a>
        <a href="<?= esc(page('work')?->url() ?? url('work')) ?>"><i></i><span>WORK</span></a>
        <a href="<?= esc(url('start-a-project')) ?>"><i></i><span>START</span></a>
        <a href="<?= esc(url('contact')) ?>"><i></i><span>CONTACT</span></a>
      </nav>
    </section>
  </div>
</div>

<?php snippet('layouts/footer') ?>
