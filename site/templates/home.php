<?php

use Breakfast\Platform\Teletext\Registry;

$softkeys = [
    ['label' => 'Back to top',  'sub' => 'P100', 'href' => '#top'],
    ['label' => 'Our work',     'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'How it works','sub' => 'P400', 'href' => '/about#how'],
    ['label' => 'Contact us',   'sub' => 'P700', 'href' => url('contact')],
];

$workPage = page('work');
$journalPage = page('journal');
$latest = $journalPage?->children()->listed()->sortBy('date', 'desc')->limit(3);
$services = array_values(iterator_to_array($page->services_cards()->toStructure()));
$principles = array_values(iterator_to_array($page->why_points()->toStructure()));

// Keep the four offer cells editable through Kirby. The final cell carries
// Breakfast's editorial principle into the commercial home-screen grid.
$offerRows = [];
foreach ($services as $index => $service) {
    $offerRows[] = [
        'number' => 301 + $index,
        'title' => (string) $service->title(),
        'text' => (string) $service->text()->or($service->audience()),
        'href' => (string) $service->link()->or(url('services')),
        'motif' => $index === 0 ? 'monitor' : ($index === 1 ? 'cursor' : 'egg'),
    ];
}
$offerRows[] = [
    'number' => 400,
    'title' => (string) ($principles[0]->title() ?? 'Words before decoration'),
    'text' => (string) ($principles[0]->text() ?? 'Strategy, copy and clarity before we write a line of code.'),
    'href' => '/about#how',
    'motif' => 'mug',
];
$offerRows = array_slice($offerRows, 0, 4);

snippet('layouts/header');
?>

<section class="tt-home" id="top" aria-labelledby="home-heading">
  <div class="tt-home__body">
    <div class="tt-home__left">
      <div class="tt-home__index">
        <h1 id="home-heading" class="tt-h1-copy"><?= esc($page->hero_headline()->or('Web design in North Wales that helps local businesses')) ?><?= $page->hero_highlight()->isNotEmpty() ? ' ' . esc($page->hero_highlight()) : '' ?></h1>
        <p class="tt-home__intro"><?= esc($page->hero_text()->or("Websites you’ll be proud to send people to.")) ?></p>
        <div class="tt-home__index-art">
          <nav class="tt-home__links" aria-label="Breakfast Text index">
            <?php foreach ([
                ['101', 'Start a project', url('start-a-project')],
                ['110', 'Website review', url('website-review')],
                ['200', 'Our work', $workPage ? $workPage->url() : url('work')],
                ['300', 'Services', page('services') ? page('services')->url() : url('services')],
                ['400', 'How it works', '/about#how'],
                ['500', 'About Breakfast', page('about') ? page('about')->url() : url('about')],
                ['600', 'Journal', $journalPage ? $journalPage->url() : url('journal')],
                ['700', 'Contact', url('contact')],
            ] as [$number, $label, $href]): ?>
              <a href="<?= esc($href) ?>"><b><?= esc($number) ?></b><span><?= esc($label) ?></span><i aria-hidden="true"></i></a>
            <?php endforeach ?>
          </nav>
          <div class="tt-home__sunrise"><?php snippet('teletext/pixel-art', ['motif' => 'sunrise', 'size' => 'lg']) ?></div>
        </div>
      </div>

      <section class="tt-home__offers" aria-labelledby="offers-heading">
        <div class="tt-home__section-label"><h2 id="offers-heading">What we do</h2></div>
        <div class="tt-home__offer-grid">
          <?php foreach ($offerRows as $offer): ?>
            <a class="tt-home__offer" href="<?= esc($offer['href']) ?>">
              <span class="tt-home__offer-art"><?php snippet('teletext/pixel-art', ['motif' => $offer['motif'], 'size' => 'sm']) ?></span>
              <strong><?= esc($offer['title']) ?></strong>
              <span><?= esc($offer['text']) ?></span>
              <small>P<?= esc($offer['number']) ?> <b>›</b></small>
            </a>
          <?php endforeach ?>
        </div>
      </section>
    </div>

    <aside class="tt-home__right">
      <section class="tt-home__sidebox" aria-labelledby="status-heading">
        <h2 id="status-heading">Current status</h2>
        <p class="tt-home__status"><b aria-hidden="true"></b><?= esc($site->availability_text()->or('Taking on new projects')) ?></p>
        <p>Get in touch to<br>check availability.</p>
      </section>

      <?php if ($latest && $latest->isNotEmpty()): ?>
      <section class="tt-home__sidebox tt-home__journal" aria-labelledby="journal-heading">
        <h2 id="journal-heading">Latest from the journal</h2>
        <?php foreach ($latest as $article): ?>
          <?php $articleNumber = Registry::numberFor($article, $site); ?>
          <a href="<?= esc($article->url()) ?>"><b><?= $articleNumber !== null ? esc($articleNumber) : '601' ?></b><span><?= esc($article->title()) ?></span></a>
        <?php endforeach ?>
        <a class="tt-home__more" href="<?= esc($journalPage ? $journalPage->url() : url('journal')) ?>">More on P600 <b>›</b></a>
      </section>
      <?php endif ?>
    </aside>
  </div>
</section>

<?php snippet('layouts/footer', ['softkeys' => $softkeys]) ?>
